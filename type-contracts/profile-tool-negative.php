<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\TypeContracts;

use Fight\AccessControl\Adapter\Mcp\Tool\Agent\AgentToolAvailability;
use Fight\AccessControl\Adapter\Mcp\Tool\Agent\AgentToolPermissionCatalog;
use Fight\AccessControl\Adapter\Mcp\Tool\Agent\GetAgentProfileTool;
use Fight\Common\Application\Mcp\Tool\McpProgressReporter;
use Fight\Common\Application\Mcp\Tool\McpToolInfo;
use Fight\Common\Application\Validation\Data\ApplicationData;

/**
 * Rejects incompatible descriptors, input and nullable requirements
 */
function incompatibleProfileToolContracts(
    GetAgentProfileTool $read,
    AgentToolPermissionCatalog $catalog,
    AgentToolAvailability $availability,
    McpToolInfo $definition,
    ApplicationData $input,
    McpProgressReporter $progress
): void {
    $availability->isAvailable($input);
    $read->handle($definition, $progress);
    count($catalog->requirementsFor($definition));
}
