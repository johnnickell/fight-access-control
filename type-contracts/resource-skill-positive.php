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
use Fight\Common\Domain\Value\Basic\StrictJson;

/**
 * Proves exact Resource and Skill associations without narrowing dynamic JSON
 */
function resourceSkillContracts(
    AgentResourcePermissionBinding $resourceBinding,
    AgentResourcePermissionCatalog $resources,
    AgentResourceAvailability $resourceAvailability,
    AgentSkillPermissionBinding $skillBinding,
    AgentSkillPermissionCatalog $skills,
    AgentSkillAvailability $skillAvailability,
    McpResourceInfo $descriptor,
    StrictJson $entry
): void {
    \PHPStan\dumpType($resourceBinding->provider());
    \PHPStan\dumpType($resourceBinding->uri());
    \PHPStan\dumpType($resourceBinding->permissions());
    \PHPStan\dumpType($resources->providers());
    \PHPStan\dumpType($resources->requirementsFor($descriptor));
    \PHPStan\dumpType($resourceAvailability->isAvailable($descriptor));
    \PHPStan\dumpType($skillBinding->revision());
    \PHPStan\dumpType($skillBinding->permissions());
    \PHPStan\dumpType($skills->revisions());
    \PHPStan\dumpType($skills->requirementsFor($entry));
    \PHPStan\dumpType($skillAvailability->isAvailable($entry));
    \PHPStan\dumpType(array_map(
        static fn(PermissionName $permission): string => $permission->toString(),
        $skillBinding->permissions()
    ));
    \PHPStan\dumpType($descriptor->toArray());
    \PHPStan\dumpType($skillBinding->revision()->entry()->get('uri'));
}
