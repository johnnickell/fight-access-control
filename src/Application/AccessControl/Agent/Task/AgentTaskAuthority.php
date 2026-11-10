<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\Task;

use Closure;
use Fight\AccessControl\Application\AccessControl\Agent\Authentication\CurrentAgentPrincipalProvider;
use Fight\AccessControl\Application\AccessControl\Agent\Authentication\SignedAgentRequest;
use Fight\AccessControl\Application\AccessControl\Agent\Continuation\AgentContinuationBinding;
use Fight\AccessControl\Application\AccessControl\Agent\Continuation\AgentContinuationGuard;
use Fight\AccessControl\Application\AccessControl\Agent\Continuation\AgentContinuationScope;
use Fight\AccessControl\Application\AccessControl\Agent\Exception\AgentContinuationRejectedException;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentAuthenticationDiagnosticClassification;
use Fight\AccessControl\Domain\AccessControl\Agent\AuthenticatedAgentPrincipal;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\CurrentAgentPrincipalResolutionRejectedException;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use LogicException;
use SensitiveParameter;

/**
 * Class AgentTaskAuthority
 *
 * Binds one independent operation requirement to trusted request or bounded continuation authority.
 */
final readonly class AgentTaskAuthority
{
    /**
     * Constructs AgentTaskAuthority
     *
     * @phpstan-param Closure(): AuthenticatedAgentPrincipal $resolve
     * @phpstan-param array<array-key, mixed> $requirements
     */
    private function __construct(#[SensitiveParameter] private Closure $resolve, private array $requirements)
    {
        if (
            $requirements === [] || !array_is_list($requirements)
            || !array_all($requirements, static fn(mixed $value): bool => $value instanceof PermissionName)
        ) {
            throw new LogicException('Task Permission requirements must be a nonempty canonical list.');
        }
    }

    /**
     * Creates independent requirements over the same trusted ordinary-request Agent provider
     *
     * @phpstan-param array<array-key, mixed> $requirements
     */
    public static function fromRequest(
        CurrentAgentPrincipalProvider $provider,
        #[SensitiveParameter] SignedAgentRequest $request,
        string $correlationId,
        array $requirements
    ): self {
        return new self(
            static fn(): AuthenticatedAgentPrincipal => $provider->resolve($request, $correlationId),
            $requirements
        );
    }

    /**
     * Executes bounded protected work with authority usable only inside the existing synchronized guard
     *
     * The action configures actual Common handlers/buses with the supplied ephemeral authority and invokes them
     * synchronously. Original operation caller continuation, when required, needs its own independent guard/binding.
     * No provider, signed request, nonce replay, enclosing business transaction or reusable allow is introduced.
     *
     * @phpstan-param array<array-key, mixed> $requirements
     * @phpstan-param Closure(self): void $action
     */
    public static function duringContinuation(
        AgentContinuationGuard $guard,
        #[SensitiveParameter] AgentContinuationBinding $binding,
        array $requirements,
        #[SensitiveParameter] AgentContinuationScope $scope,
        #[SensitiveParameter] Closure $action
    ): void {
        $currentScope = new AgentTaskContinuationScope($scope);
        $active = false;
        $authority = new self(static function () use ($currentScope, &$active): AuthenticatedAgentPrincipal {
            // The separately invoked guard callback enables this resolver only for its synchronous action.
            /** @var bool $active */
            if (!$active) {
                throw new AgentContinuationRejectedException();
            }

            return $currentScope->principal();
        }, $requirements);
        try {
            $guard->run($binding, $requirements, $currentScope, static function () use (
                $authority,
                $action,
                &$active
            ): void {
                $active = true;
                try {
                    $action($authority);
                } finally {
                    $active = false;
                }
            });
        } finally {
            $active = false;
        }
    }

    /**
     * Returns the complete admitted direct Agent snapshot or an intentional authentication or Permission denial
     *
     * Operational and inconsistent-snapshot failures propagate to the existing safe error owner, never empty success.
     */
    public function principal(): ?AuthenticatedAgentPrincipal
    {
        try {
            $principal = ($this->resolve)();
        } catch (CurrentAgentPrincipalResolutionRejectedException $currentAgentPrincipalResolutionRejectedException) {
            if (
                in_array($currentAgentPrincipalResolutionRejectedException->getDiagnostic()->getClassification(), [
                AgentAuthenticationDiagnosticClassification::AUTHENTICATION_REJECTED,
                AgentAuthenticationDiagnosticClassification::AGENT_AUTHORITY_NOT_CURRENT
                ], true)
            ) {
                return null;
            }

            throw $currentAgentPrincipalResolutionRejectedException;
        }

        foreach ($this->requirements as $requirement) {
            if (!$principal->hasPermission($requirement)) {
                return null;
            }
        }

        return $principal;
    }
}
