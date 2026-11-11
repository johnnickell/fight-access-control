<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\TypeContracts;

use Fight\AccessControl\Adapter\Mcp\Tool\Agent\AgentProfileToolFailures;
use Fight\AccessControl\Adapter\Mcp\Tool\Agent\AgentToolAvailability;
use Fight\AccessControl\Adapter\Mcp\Tool\Agent\AgentToolPermissionCatalog;
use Fight\AccessControl\Adapter\Mcp\Tool\Agent\Attribute\RequiresAgentPermission;
use Fight\AccessControl\Adapter\Mcp\Tool\Agent\GetAgentProfileTool;
use Fight\AccessControl\Adapter\Mcp\Tool\Agent\UpdateAgentProfileTool;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\AgentProfileView;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\GetAgentProfile;
use Fight\Common\Application\Mcp\Tool\McpProgressReporter;
use Fight\Common\Application\Mcp\Tool\McpToolInfo;
use Fight\Common\Application\Messaging\Query\QueryBus;
use Fight\Common\Application\Validation\Data\ApplicationData;

/**
 * Proves native Tool contracts and the permitted known Query caller annotation
 */
function profileToolContracts(
    GetAgentProfileTool $read,
    UpdateAgentProfileTool $update,
    AgentToolPermissionCatalog $catalog,
    AgentToolAvailability $availability,
    RequiresAgentPermission $requirement,
    McpToolInfo $definition,
    ApplicationData $input,
    McpProgressReporter $progress,
    QueryBus $queryBus,
    GetAgentProfile $query
): void {
    \PHPStan\dumpType($read->handle($input, $progress));
    \PHPStan\dumpType($update->handle($input, $progress));
    \PHPStan\dumpType($catalog->requirementsFor($definition));
    \PHPStan\dumpType($availability->isAvailable($definition));
    \PHPStan\dumpType($requirement->getPermissionName());
    \PHPStan\dumpType(AgentProfileToolFailures::create());
    \PHPStan\dumpType($input->get('name'));
    \PHPStan\dumpType($queryBus->fetch($query));
    /** @var AgentProfileView $profile */
    $profile = $queryBus->fetch($query);
    \PHPStan\dumpType($profile->toArray());
}
