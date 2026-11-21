<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\TypeContracts;

use Fight\AccessControl\Application\AccessControl\Agent\CommandHandler\UpdateAgentHandler;
use Fight\AccessControl\Application\AccessControl\Agent\PermissionAssignment\AgentPermissionAssignmentCoordinator;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\Event\PermissionGrantedToAgent;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Messaging\Command\CommandMessage;

return static function (
    AgentPermissionAssignmentCoordinator $coordinator,
    UpdateAgentHandler $handler,
    CommandMessage $message,
    UserId $actor,
    AgentId $agent,
    PermissionId $permission
): void {
    $coordinator->grant($agent, $agent, $permission);
    $coordinator->replace($actor, $agent, 1, [$agent]);
    (static fn(PermissionGrantedToAgent $event): PermissionId => $event->getPermissionId())(
        $coordinator->grant($actor, $agent, $permission)[0] ?? null
    );
    (static fn(AgentId $id): string => $id->toString())($handler->handle($message));
};
