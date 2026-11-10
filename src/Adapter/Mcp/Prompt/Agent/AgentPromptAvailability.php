<?php

declare(strict_types=1);

namespace Fight\AccessControl\Adapter\Mcp\Prompt\Agent;

use Fight\AccessControl\Application\AccessControl\Agent\Authentication\CurrentAgentPrincipalProvider;
use Fight\AccessControl\Application\AccessControl\Agent\Authentication\SignedAgentRequest;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentAuthenticationDiagnosticClassification;
use Fight\AccessControl\Domain\AccessControl\Agent\AuthenticatedAgentPrincipal;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\CurrentAgentPrincipalResolutionRejectedException;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\Common\Application\Mcp\Prompt\McpPromptAvailability;
use Fight\Common\Application\Mcp\Prompt\McpPromptInfo;
use SensitiveParameter;
use Throwable;

/**
 * Class AgentPromptAvailability
 *
 * Reuses one trusted ordinary-request principal. Operational failure is not a successful empty catalog.
 */
final class AgentPromptAvailability implements McpPromptAvailability
{
    private bool $resolved = false;

    private ?AuthenticatedAgentPrincipal $principal = null;

    private ?Throwable $failure = null;

    /**
     * Constructs AgentPromptAvailability
     */
    public function __construct(
        private readonly AgentPromptPermissionCatalog $catalog,
        private readonly CurrentAgentPrincipalProvider $provider,
        #[SensitiveParameter] private readonly SignedAgentRequest $request,
        private readonly string $correlationId
    ) {
    }

    /**
     * @inheritDoc
     */
    public function isAvailable(McpPromptInfo $prompt): bool
    {
        $requirements = $this->catalog->requirementsFor($prompt);
        if ($requirements === null) {
            return false;
        }

        if (!$this->resolved) {
            $this->resolved = true;
            try {
                $this->principal = $this->provider->resolve($this->request, $this->correlationId);
            } catch (Throwable $failure) {
                $this->failure = $failure;
            }
        }

        if ($this->failure instanceof CurrentAgentPrincipalResolutionRejectedException) {
            if (
                in_array($this->failure->getDiagnostic()->getClassification(), [
                AgentAuthenticationDiagnosticClassification::AUTHENTICATION_REJECTED,
                AgentAuthenticationDiagnosticClassification::AGENT_AUTHORITY_NOT_CURRENT
                ], true)
            ) {
                return false;
            }
        }

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->principal !== null && array_all(
            $requirements,
            fn(PermissionName $permission): bool => $this->principal->hasPermission($permission)
        );
    }
}
