<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\TypeContracts;

use Fight\AccessControl\Adapter\Mcp\Resource\Agent\AgentResourceAvailability;
use Fight\AccessControl\Adapter\Mcp\Resource\Agent\AgentResourcePermissionBinding;
use Fight\AccessControl\Adapter\Mcp\Resource\Agent\AgentResourcePermissionCatalog;
use Fight\AccessControl\Adapter\Mcp\Skill\Agent\AgentSkillAvailability;
use Fight\AccessControl\Adapter\Mcp\Skill\Agent\AgentSkillPermissionBinding;
use Fight\AccessControl\Adapter\Mcp\Skill\Agent\AgentSkillPermissionCatalog;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\Common\Application\Mcp\Resource\McpResourceInfo;
use Fight\Common\Application\Mcp\Skill\McpSkillRevision;
use Fight\Common\Domain\Value\Basic\StrictJson;

/**
 * Rejects swapped metadata families and unsafe optional or collection use
 */
function incompatibleResourceSkillContracts(
    AgentResourceAvailability $resourceAvailability,
    AgentSkillAvailability $skillAvailability,
    AgentResourcePermissionCatalog $resources,
    AgentSkillPermissionCatalog $skills,
    McpResourceInfo $descriptor,
    McpSkillRevision $revision,
    StrictJson $entry
): void {
    $resourceAvailability->isAvailable($entry);
    $skillAvailability->isAvailable($descriptor);
    new AgentResourcePermissionBinding($revision, 'docs:one', ['READ_DOCUMENTS']);
    new AgentSkillPermissionBinding($descriptor, ['READ_SKILLS']);
    $requirePermissions = static function (PermissionName ...$permissions): void {
    };
    $requirePermissions(...$resources->providers());
    $requirePermissions(...$skills->revisions());
    requireResourceSkillPermissions($resources->requirementsFor($descriptor));
    requireResourceSkillPermissions($skills->requirementsFor($entry));
}

/**
 * Consumes already admitted Permission requirements
 *
 * @phpstan-param non-empty-list<PermissionName> $permissions
 */
function requireResourceSkillPermissions(array $permissions): void
{
}
