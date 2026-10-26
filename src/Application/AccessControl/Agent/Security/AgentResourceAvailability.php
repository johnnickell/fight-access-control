<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\Security;

use Fight\AccessControl\Application\AccessControl\Agent\AgentResourcePermissionCatalog;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentAuthenticationDiagnosticClassification;
use Fight\AccessControl\Domain\AccessControl\Agent\AuthenticatedAgentPrincipal;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\CurrentAgentPrincipalResolutionRejectedException;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\Common\Application\Mcp\Resource\McpResourceAvailability;
use Fight\Common\Application\Mcp\Resource\McpResourceInfo;
use SensitiveParameter;
use Throwable;

/**
 * Class AgentResourceAvailability
 *
 * Reuses one trusted ordinary-request principal. Operational failure is not a successful empty catalog.
 */
final class AgentResourceAvailability implements McpResourceAvailability
{
    private bool $resolved = false;

    private ?AuthenticatedAgentPrincipal $principal = null;

    private ?Throwable $failure = null;

    /**
     * Constructs AgentResourceAvailability
     */
    public function __construct(
        private readonly AgentResourcePermissionCatalog $catalog,
        private readonly CurrentAgentPrincipalProvider $provider,
        #[SensitiveParameter] private readonly SignedAgentRequest $request,
        private readonly string $correlationId
    ) {
    }

    /**
     * @inheritDoc
     */
    public function isAvailable(McpResourceInfo $resource): bool
    {
        $requirements = $this->catalog->requirementsFor($resource);
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
