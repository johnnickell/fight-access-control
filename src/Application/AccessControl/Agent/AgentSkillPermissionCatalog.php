<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent;

use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\Common\Application\Mcp\Skill\McpSkillRevision;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Value\Basic\StrictJson;

/**
 * Class AgentSkillPermissionCatalog
 *
 * Associates complete immutable entries with requirements, not principals or continuing authority.
 */
final readonly class AgentSkillPermissionCatalog
{
    /** @var array<string, AgentSkillPermissionBinding> */
    private array $bindings;

    /**
     * Constructs AgentSkillPermissionCatalog
     *
     * @param array<array-key, mixed> $bindings
     */
    public function __construct(array $bindings)
    {
        if (!array_is_list($bindings)) {
            throw new DomainException('Skill bindings must be a list.');
        }

        $registered = [];
        $members = [];
        foreach ($bindings as $binding) {
            if (!$binding instanceof AgentSkillPermissionBinding) {
                throw new DomainException('Skill bindings require validated immutable revisions and requirements.');
            }

            $revision = $binding->revision();
            $uri = $revision->entry()->get('uri');
            if (isset($registered[$uri]) || array_intersect_key($members, $revision->resources()) !== []) {
                throw new DomainException('Skill bindings require one immutable revision owner per exact member URI.');
            }

            $registered[$uri] = $binding;
            $members += $revision->resources();
        }

        $this->bindings = $registered;
    }

    /**
     * Returns the validated revisions to serve through Common's Skill Resource provider
     *
     * @return list<McpSkillRevision>
     */
    public function revisions(): array
    {
        return array_values(array_map(
            static fn(AgentSkillPermissionBinding $binding): McpSkillRevision => $binding->revision(),
            $this->bindings
        ));
    }

    /**
     * Returns requirements only for an exact complete immutable entry exported by its bound revision
     *
     * Common exports a deterministic StrictJson representation, preserving scalar types and manifest order.
     * Fresh objects with the same export work; changed frontmatter, digests, members or aliases cannot borrow it.
     *
     * @return non-empty-list<PermissionName>|null
     */
    public function requirementsFor(StrictJson $entry): ?array
    {
        if (!$entry->isObject() || !is_string($entry->get('uri'))) {
            return null;
        }

        $binding = $this->bindings[$entry->get('uri')] ?? null;
        if ($binding === null || $binding->revision()->entry()->toString() !== $entry->toString()) {
            return null;
        }

        return $binding->permissions();
    }
}
