<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\Security;

use Closure;
use Fight\AccessControl\Application\AccessControl\Agent\Exception\AgentContinuationRejectedException;
use Fight\AccessControl\Application\AccessControl\Agent\Service\AgentContinuationFence;
use Fight\AccessControl\Application\AccessControl\Agent\Service\AgentContinuationScope;
use Fight\AccessControl\Application\AccessControl\Authorization\Service\ExactPermissionResolver;
use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentRepository;
use Fight\AccessControl\Domain\AccessControl\Agent\AuthenticatedAgentPrincipal;
use Fight\AccessControl\Domain\AccessControl\Authorization\PrincipalPermission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionRepository;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionTier;
use SensitiveParameter;
use Throwable;

/**
 * Class AgentContinuationGuard
 *
 * Admits one bounded action through fresh authority and scope checks under the required composite fence.
 */
final readonly class AgentContinuationGuard
{
    /**
     * Constructs AgentContinuationGuard
     */
    public function __construct(
        private AgentRepository $agents,
        private PermissionRepository $permissions,
        private Clock $clock,
        private AgentContinuationFence $fence
    ) {
    }

    /**
     * Executes the protected action only at a current synchronized admission point
     *
     * Requirements and scope are trusted consumer configuration for this exact action. Supply a nonempty conjunctive
     * list of canonical Permission names. The action must complete bounded protected work synchronously under the
     * fence; enqueueing a future effect does not authorize its later execution. Pre-admission faults are neutral.
     * Once action invocation begins, faults propagate to the consumer's safe error owner: denial cannot imply that
     * an effect did not happen. No allow token, reusable lease, business transaction or runtime manager is returned.
     *
     * @phpstan-param array<array-key, mixed> $requirements
     * @phpstan-param Closure(): void $action
     */
    public function run(
        #[SensitiveParameter] AgentContinuationBinding $binding,
        #[SensitiveParameter] array $requirements,
        #[SensitiveParameter] AgentContinuationScope $scope,
        #[SensitiveParameter] Closure $action
    ): void {
        $active = true;
        $attempted = false;
        $admitted = false;
        try {
            $context = $binding->getContext();
            $this->fence->synchronized(
                $context->getAgentId(),
                $scope,
                function () use ($context, $requirements, $scope, $action, &$active, &$attempted, &$admitted): void {
                    // A nonconforming fence may retain the callback until after run() has returned.
                    // @phpstan-ignore booleanNot.alwaysFalse
                    if (!$active || $attempted) {
                        throw new AgentContinuationRejectedException();
                    }

                    $attempted = true;
                    $this->validate($context, $requirements, $scope);
                    $admitted = true;
                    $action();
                }
            );
            if (!$admitted) {
                throw new AgentContinuationRejectedException();
            }
        } catch (Throwable $throwable) {
            if ($admitted) {
                throw $throwable;
            }

            throw new AgentContinuationRejectedException();
        } finally {
            $active = false;
        }
    }

    /**
     * Validates current authority and scope before sampling provenance validity at the admission point
     *
     * @phpstan-param array<array-key, mixed> $requirements
     */
    private function validate(
        #[SensitiveParameter] AgentContinuationContext $context,
        #[SensitiveParameter] array $requirements,
        #[SensitiveParameter] AgentContinuationScope $scope
    ): void {
        $this->agents->getOperationContract()->assertCompatible();
        $agent = $this->agents->getById($context->getAgentId());
        if (
            !$agent instanceof Agent
            || !$agent->getId()->equals($context->getAgentId())
            || !$agent->hasActiveCredential($context->getCredentialId(), $context->getCredentialRevision())
            || $requirements === []
            || !array_is_list($requirements)
        ) {
            throw new AgentContinuationRejectedException();
        }

        $definitions = new ExactPermissionResolver($this->permissions)->resolveDefinitions($agent->getPermissionIds());
        $permissions = [];
        foreach ($definitions as $definition) {
            if ($definition->getTier() !== PermissionTier::ADMIN_SAFE) {
                throw new AgentContinuationRejectedException();
            }

            $permissions[] = new PrincipalPermission($definition->getId(), $definition->getName());
        }

        $principal = new AuthenticatedAgentPrincipal(
            $agent->getId(),
            $agent->getCredentialId(),
            $agent->getCredentialRevision(),
            $agent->getPermissionAssignmentRevision(),
            $permissions
        );
        foreach ($requirements as $requirement) {
            if (!$requirement instanceof PermissionName || !$principal->hasPermission($requirement)) {
                throw new AgentContinuationRejectedException();
            }
        }

        if (!$scope->isAllowed($principal) || !$context->isValidAt($this->clock->now())) {
            throw new AgentContinuationRejectedException();
        }
    }
}
