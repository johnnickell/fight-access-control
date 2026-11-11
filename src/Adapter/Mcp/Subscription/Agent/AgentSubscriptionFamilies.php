<?php

declare(strict_types=1);

namespace Fight\AccessControl\Adapter\Mcp\Subscription\Agent;

use Fight\AccessControl\Adapter\Mcp\Prompt\Agent\AgentPromptPermissionBinding;
use Fight\AccessControl\Adapter\Mcp\Prompt\Agent\AgentPromptPermissionCatalog;
use Fight\AccessControl\Adapter\Mcp\Resource\Agent\AgentResourcePermissionCatalog;
use Fight\AccessControl\Adapter\Mcp\Skill\Agent\AgentSkillPermissionCatalog;
use Fight\AccessControl\Adapter\Mcp\Tool\Agent\AgentToolPermissionCatalog;
use Fight\AccessControl\Domain\AccessControl\Agent\AuthenticatedAgentPrincipal;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\Common\Application\Mcp\Resource\McpProtectedResourceProvider;
use Fight\Common\Application\Mcp\Resource\McpResourceInfo;
use Fight\Common\Application\Mcp\Skill\McpSkillResources;
use Fight\Common\Application\Mcp\Tool\McpToolAvailability;
use Fight\Common\Application\Mcp\Tool\McpToolInfo;
use Fight\Common\Application\Mcp\Tool\McpToolRegistry;
use LogicException;

/**
 * Class AgentSubscriptionFamilies
 *
 * Checks configured family metadata with a fresh principal, never a retained request availability object.
 * Family visibility alone is insufficient: AgentSubscriptionScope separately admits whole catalog activity.
 * Resource catalogs and immutable Skill revisions must be the same objects served by ordinary Common dispatch.
 */
final readonly class AgentSubscriptionFamilies
{
    /**
     * Constructs AgentSubscriptionFamilies
     */
    public function __construct(
        private ?McpToolRegistry $toolRegistry = null,
        private ?AgentToolPermissionCatalog $toolCatalog = null,
        private ?AgentResourcePermissionCatalog $resourceCatalog = null,
        private ?AgentSkillPermissionCatalog $skillCatalog = null,
        private ?AgentPromptPermissionCatalog $promptCatalog = null
    ) {
        if (($toolRegistry === null) !== ($toolCatalog === null)) {
            throw new LogicException('Subscription Tool composition must share a registry and catalog.');
        }
    }

    /**
     * Returns current family availability without executing Tools or opening Resource content
     */
    public function allows(AuthenticatedAgentPrincipal $principal, string $filter, ?string $uri): bool
    {
        if ($filter === 'toolsListChanged') {
            if ($this->toolRegistry === null) {
                return false;
            }

            $availability = new readonly class ($this->toolCatalog, $principal) implements McpToolAvailability {
                /**
                 * Constructs the current metadata predicate
                 */
                public function __construct(
                    private AgentToolPermissionCatalog $catalog,
                    private AuthenticatedAgentPrincipal $principal
                ) {
                }

                /**
                 * @inheritDoc
                 */
                public function isAvailable(McpToolInfo $tool): bool
                {
                    $requirements = $this->catalog->requirementsFor($tool);

                    return $requirements !== null && array_all(
                        $requirements,
                        fn(PermissionName $permission): bool => $this->principal->hasPermission($permission)
                    );
                }
            };

            return $this->toolRegistry->available($availability) !== [];
        }

        if ($filter === 'promptsListChanged') {
            if ($uri !== null || $this->promptCatalog === null) {
                return false;
            }

            return array_any(
                $this->promptCatalog->prompts(),
                fn(AgentPromptPermissionBinding $prompt): bool =>
                    $this->permitted($principal, $this->promptCatalog->requirementsFor($prompt->info()))
            );
        }

        if ($this->resourceCatalog === null) {
            return false;
        }

        if ($filter === 'resourceSubscriptions' && $uri !== null) {
            $selected = null;
            foreach ($this->resourceCatalog->providers() as $provider) {
                $resource = $provider->find($uri);
                if ($resource === null) {
                    continue;
                }

                if ($selected !== null || $resource->uri() !== $uri) {
                    throw new LogicException('Subscription Resource ownership is inconsistent.');
                }

                $selected = [$resource, $provider];
            }

            return $selected !== null && $this->resourceAllowed($principal, $selected[0], $selected[1]);
        }

        if ($filter !== 'resourcesListChanged' || $uri !== null) {
            return false;
        }

        $count = 0;
        $seen = [];
        $available = false;
        foreach ($this->resourceCatalog->providers() as $provider) {
            foreach ($provider->resources() as $resource) {
                if (++$count > 10000) {
                    throw new LogicException('Subscription Resource metadata exceeds bounds.');
                }

                if (isset($seen[$resource->uri()])) {
                    throw new LogicException('Subscription Resource ownership is inconsistent.');
                }

                $seen[$resource->uri()] = true;
                $available = $this->resourceAllowed($principal, $resource, $provider) || $available;
            }
        }

        return $available;
    }

    /**
     * Returns selected metadata admission including complete Skill and every member requirement where applicable
     */
    private function resourceAllowed(
        AuthenticatedAgentPrincipal $principal,
        McpResourceInfo $resource,
        object $provider
    ): bool {
        if (!$this->permitted($principal, $this->resourceCatalog->requirementsFor($resource))) {
            return false;
        }

        if (!$provider instanceof McpProtectedResourceProvider) {
            return true;
        }

        // Never invoke a provider's request-snapshot policy as continuing live authority.
        if (!$provider instanceof McpSkillResources || $this->skillCatalog === null) {
            return false;
        }

        foreach ($this->skillCatalog->revisions() as $revision) {
            if (!isset($revision->resources()[$resource->uri()])) {
                continue;
            }

            $entry = $provider->findEntry($revision->entry()->get('uri'));
            if (
                $entry === null
                || !$this->permitted($principal, $this->skillCatalog->requirementsFor($entry))
            ) {
                return false;
            }

            foreach ($revision->resources() as $member) {
                if (!$this->permitted($principal, $this->resourceCatalog->requirementsFor($member))) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    /**
     * Returns whether every exact canonical requirement is currently granted
     *
     * @phpstan-param non-empty-list<PermissionName>|null $requirements
     */
    private function permitted(AuthenticatedAgentPrincipal $principal, ?array $requirements): bool
    {
        return $requirements !== null && array_all($requirements, $principal->hasPermission(...));
    }
}
