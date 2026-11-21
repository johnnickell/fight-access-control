<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\TypeContracts;

use Fight\AccessControl\Application\AccessControl\Agent\CommandHandler\GrantPermissionToAgentHandler;
use Fight\AccessControl\Application\AccessControl\Agent\CommandHandler\ReplaceAgentPermissionsHandler;
use Fight\AccessControl\Application\AccessControl\Agent\CommandHandler\RevokePermissionFromAgentHandler;
use Fight\AccessControl\Application\AccessControl\Agent\CommandHandler\UpdateAgentHandler;
use Fight\AccessControl\Application\AccessControl\Agent\PermissionAssignment\AgentPermissionAssignmentCoordinator;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentRepository;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;

return static function (
    GrantPermissionToAgentHandler $grant,
    RevokePermissionFromAgentHandler $revoke,
    ReplaceAgentPermissionsHandler $replace,
    UpdateAgentHandler $update,
    AgentPermissionAssignmentCoordinator $coordinator,
    AgentRepository $repository,
    UserId $actor,
    AgentId $agent,
    PermissionId $permission
): void {
    \PHPStan\dumpType($grant->handle(...));
    \PHPStan\dumpType($revoke->handle(...));
    \PHPStan\dumpType($replace->handle(...));
    \PHPStan\dumpType($update->handle(...));
    \PHPStan\dumpType($coordinator->grant($actor, $agent, $permission)[0] ?? null);
    \PHPStan\dumpType($coordinator->revoke($actor, $agent, $permission)[0] ?? null);
    \PHPStan\dumpType($coordinator->replace($actor, $agent, 1, [$permission])[0] ?? null);
    \PHPStan\dumpType($repository->rename(...));
};
