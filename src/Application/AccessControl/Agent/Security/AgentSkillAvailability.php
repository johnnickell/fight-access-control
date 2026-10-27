<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\Security;

use Fight\AccessControl\Application\AccessControl\Agent\AgentSkillPermissionCatalog;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentAuthenticationDiagnosticClassification;
use Fight\AccessControl\Domain\AccessControl\Agent\AuthenticatedAgentPrincipal;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\CurrentAgentPrincipalResolutionRejectedException;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\Common\Application\Mcp\Skill\McpSkillAvailability;
use Fight\Common\Domain\Value\Basic\StrictJson;
use SensitiveParameter;
use Throwable;

/**
 * Class AgentSkillAvailability
 *
 * Shares the trusted ordinary-request provider with Resource admission, never reauthenticates per member.
 */
final class AgentSkillAvailability implements McpSkillAvailability
{
    private bool $resolved = false;

    private ?AuthenticatedAgentPrincipal $principal = null;

    private ?Throwable $failure = null;

    /**
     * Constructs AgentSkillAvailability
     */
    public function __construct(
        private readonly AgentSkillPermissionCatalog $catalog,
        private readonly CurrentAgentPrincipalProvider $provider,
        #[SensitiveParameter] private readonly SignedAgentRequest $request,
        private readonly string $correlationId
    ) {
    }

    /**
     * @inheritDoc
     */
    public function isAvailable(StrictJson $entry): bool
    {
        $requirements = $this->catalog->requirementsFor($entry);
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
