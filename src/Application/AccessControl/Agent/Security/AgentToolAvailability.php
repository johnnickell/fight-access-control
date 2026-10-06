<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\Security;

use Fight\AccessControl\Application\AccessControl\Agent\AgentToolPermissionCatalog;
use Fight\AccessControl\Domain\AccessControl\Agent\AuthenticatedAgentPrincipal;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\Common\Application\Mcp\Tool\McpToolAvailability;
use Fight\Common\Application\Mcp\Tool\McpToolInfo;
use SensitiveParameter;
use Throwable;

/**
 * Class AgentToolAvailability
 *
 * Owns one MCP request's lazy Agent resolution and exposes only Common's neutral decision.
 * Both successful and rejected resolution remain request-local; construct a fresh instance and provider
 * for every later request, including protected interaction retries.
 */
final class AgentToolAvailability implements McpToolAvailability
{
    private bool $resolved = false;

    private ?AuthenticatedAgentPrincipal $principal = null;

    /**
     * Constructs AgentToolAvailability
     */
    public function __construct(
        private readonly AgentToolPermissionCatalog $catalog,
        private readonly CurrentAgentPrincipalProvider $provider,
        #[SensitiveParameter] private readonly SignedAgentRequest $request,
        private readonly string $correlationId
    ) {
    }

    /**
     * @inheritDoc
     */
    public function isAvailable(McpToolInfo $tool): bool
    {
        $requirements = $this->catalog->requirementsFor($tool);
        if ($requirements === null) {
            return false;
        }

        if (!$this->resolved) {
            $this->resolved = true;
            try {
                $this->principal = $this->provider->resolve($this->request, $this->correlationId);
            } catch (Throwable) {
                return false;
            }
        }

        return $this->principal !== null && array_all(
            $requirements,
            fn(PermissionName $permission): bool => $this->principal->hasPermission($permission)
        );
    }
}
