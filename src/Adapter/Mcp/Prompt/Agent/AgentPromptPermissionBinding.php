<?php

declare(strict_types=1);

namespace Fight\AccessControl\Adapter\Mcp\Prompt\Agent;

use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\Common\Application\Mcp\Prompt\McpPrompt;
use Fight\Common\Application\Mcp\Prompt\McpPromptInfo;
use Fight\Common\Application\Mcp\Prompt\McpPromptOutput;
use Fight\Common\Domain\Exception\DomainException;

/**
 * Class AgentPromptPermissionBinding
 *
 * Binds requirements to the actual immutable definition served to Common, never to a caller-supplied name.
 */
final readonly class AgentPromptPermissionBinding implements McpPrompt
{
    private McpPromptInfo $definition;

    /** @var non-empty-list<PermissionName> */
    private array $permissions;

    /**
     * Constructs AgentPromptPermissionBinding
     *
     * @phpstan-param array<array-key, mixed> $permissions
     */
    public function __construct(private McpPrompt $provider, array $permissions)
    {
        if ($permissions === [] || !array_is_list($permissions)) {
            throw new DomainException('Prompt requirements must be a non-empty list of canonical Permissions.');
        }

        $validated = [];
        foreach ($permissions as $permission) {
            if (!is_string($permission)) {
                throw new DomainException('Prompt requirements must contain canonical Permission names.');
            }

            $validated[] = PermissionName::fromString($permission);
        }

        $this->permissions = $validated;
        $this->definition = $provider->info();
    }

    /**
     * @inheritDoc
     */
    public function info(): McpPromptInfo
    {
        return $this->definition;
    }

    /**
     * @inheritDoc
     */
    public function get(array $arguments): McpPromptOutput
    {
        return $this->provider->get($arguments);
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
