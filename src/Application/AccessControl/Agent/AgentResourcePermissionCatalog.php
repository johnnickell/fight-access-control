<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent;

use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\Common\Application\Mcp\Resource\McpReadableResourceProvider;
use Fight\Common\Application\Mcp\Resource\McpResourceInfo;
use Fight\Common\Domain\Exception\DomainException;

/**
 * Class AgentResourcePermissionCatalog
 *
 * Stores requirements, not a discovered catalog or authority. Common must serve providers() unchanged.
 */
final readonly class AgentResourcePermissionCatalog
{
    /** @var array<string, AgentResourcePermissionBinding> */
    private array $bindings;

    /** @var list<McpReadableResourceProvider> */
    private array $providers;

    /**
     * Constructs AgentResourcePermissionCatalog
     *
     * Validates explicit associations without enumerating dynamic discovery or opening content.
     *
     * @param array<array-key, mixed> $bindings
     */
    public function __construct(array $bindings)
    {
        if (!array_is_list($bindings)) {
            throw new DomainException('Resource bindings must be a list.');
        }

        $registered = [];
        $providers = [];
        foreach ($bindings as $binding) {
            if (!$binding instanceof AgentResourcePermissionBinding || isset($registered[$binding->uri()])) {
                throw new DomainException('Resource bindings require one owner and requirement per exact URI.');
            }

            $owned = $binding->provider()->find($binding->uri());
            if ($owned === null || $owned->uri() !== $binding->uri()) {
                throw new DomainException('A Resource binding must match its configured exact URI owner.');
            }

            $registered[$binding->uri()] = $binding;
            $providers[spl_object_id($binding->provider())] = $binding->provider();
        }

        $this->bindings = $registered;
        $this->providers = array_values($providers);
    }

    /**
     * Returns the exact provider instances to compose with Common
     *
     * @return list<McpReadableResourceProvider>
     */
    public function providers(): array
    {
        return $this->providers;
    }

    /**
     * Returns requirements for current metadata from its configured exact owner
     *
     * Fresh equivalent descriptors are legitimate. Disappearance or reassignment cannot borrow a URI's grant;
     * Common separately validates all providers for collisions and bounds before releasing results or bytes.
     *
     * @return non-empty-list<PermissionName>|null
     */
    public function requirementsFor(McpResourceInfo $resource): ?array
    {
        $binding = $this->bindings[$resource->uri()] ?? null;
        if ($binding === null) {
            return null;
        }

        $owned = $binding->provider()->find($resource->uri());
        if ($owned === null || $owned->uri() !== $resource->uri() || $owned != $resource) {
            return null;
        }

        return $binding->permissions();
    }
}
