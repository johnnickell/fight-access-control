<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent;

use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\Common\Application\Mcp\Skill\McpSkillRevision;
use Fight\Common\Domain\Exception\DomainException;

/**
 * Class AgentSkillPermissionBinding
 */
final readonly class AgentSkillPermissionBinding
{
    /** @var non-empty-list<PermissionName> */
    private array $permissions;

    /**
     * Constructs AgentSkillPermissionBinding
     *
     * Associates requirements with an already validated immutable revision, never a URI prefix or Tool identity.
     *
     * @phpstan-param array<array-key, mixed> $permissions
     */
    public function __construct(private McpSkillRevision $revision, array $permissions)
    {
        if ($permissions === [] || !array_is_list($permissions)) {
            throw new DomainException('Skill requirements must be a non-empty list of canonical Permissions.');
        }

        $validated = [];
        foreach ($permissions as $permission) {
            if (!is_string($permission)) {
                throw new DomainException('Skill requirements must contain canonical Permission names.');
            }

            $validated[] = PermissionName::fromString($permission);
        }

        $this->permissions = $validated;
    }

    /**
     * Returns the configured immutable revision
     */
    public function revision(): McpSkillRevision
    {
        return $this->revision;
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
