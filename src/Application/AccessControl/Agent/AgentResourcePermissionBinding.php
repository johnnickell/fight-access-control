<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent;

use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\Common\Application\Mcp\Resource\McpReadableResourceProvider;
use Fight\Common\Application\Mcp\Resource\McpResourceInfo;
use Fight\Common\Domain\Exception\DomainException;

/**
 * Class AgentResourcePermissionBinding
 */
final readonly class AgentResourcePermissionBinding
{
    /** @var non-empty-list<PermissionName> */
    private array $permissions;

    /**
     * Constructs AgentResourcePermissionBinding
     *
     * Uses Common's URI validation without resolving a location or opening content.
     *
     * @phpstan-param array<array-key, mixed> $permissions
     */
    public function __construct(
        private McpReadableResourceProvider $provider,
        private string $uri,
        array $permissions
    ) {
        McpResourceInfo::fromArray(['uri' => $uri, 'name' => '']);
        if ($permissions === [] || !array_is_list($permissions)) {
            throw new DomainException('Resource requirements must be a non-empty list of canonical Permissions.');
        }

        $validated = [];
        foreach ($permissions as $permission) {
            if (!is_string($permission)) {
                throw new DomainException('Resource requirements must contain canonical Permission names.');
            }

            $validated[] = PermissionName::fromString($permission);
        }

        $this->permissions = $validated;
    }

    /**
     * Returns the configured provider instance
     */
    public function provider(): McpReadableResourceProvider
    {
        return $this->provider;
    }

    /**
     * Returns the exact protected URI
     */
    public function uri(): string
    {
        return $this->uri;
    }

    /**
     * Returns conjunctive direct Permission requirements
     *
     * @return non-empty-list<PermissionName>
     */
    public function permissions(): array
    {
        return $this->permissions;
    }
}
