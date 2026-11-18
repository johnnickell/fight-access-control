<?php

declare(strict_types=1);

namespace Fight\AccessControl\Adapter\Mcp\Prompt\Agent;

use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\Common\Application\Mcp\Prompt\McpPromptInfo;
use Fight\Common\Domain\Exception\DomainException;

/**
 * Class AgentPromptPermissionCatalog
 *
 * Associates exact definition objects only. Common owns names, duplicate rejection and bounded registration.
 */
final readonly class AgentPromptPermissionCatalog
{
    /** @var list<AgentPromptPermissionBinding> */
    private array $bindings;

    /**
     * Constructs AgentPromptPermissionCatalog
     *
     * @param array<array-key, mixed> $bindings
     */
    public function __construct(array $bindings)
    {
        if (!array_is_list($bindings)) {
            throw new DomainException('Prompt bindings must be a list.');
        }

        $seen = [];
        foreach ($bindings as $binding) {
            if (!$binding instanceof AgentPromptPermissionBinding || isset($seen[spl_object_id($binding->info())])) {
                throw new DomainException('Prompt bindings require one requirement association per definition.');
            }

            $seen[spl_object_id($binding->info())] = true;
        }

        $this->bindings = $bindings;
    }

    /**
     * Returns bound providers for registration with Common
     *
     * @return list<AgentPromptPermissionBinding>
     */
    public function prompts(): array
    {
        return $this->bindings;
    }

    /**
     * Returns requirements only for the exact registered immutable definition
     *
     * @return non-empty-list<PermissionName>|null
     */
    public function requirementsFor(McpPromptInfo $prompt): ?array
    {
        foreach ($this->bindings as $binding) {
            if ($binding->info() === $prompt) {
                return $binding->permissions();
            }
        }

        return null;
    }
}
