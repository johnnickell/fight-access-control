<?php

declare(strict_types=1);

use Fight\AccessControl\Application\AccessControl\Role\CommandHandler\CreateCustomRoleHandler;
use Fight\AccessControl\Application\AccessControl\Role\CommandHandler\GrantPermissionToCustomRoleHandler;
use Fight\AccessControl\Application\AccessControl\Role\CommandHandler\RemoveCustomRoleHandler;
use Fight\AccessControl\Application\AccessControl\Role\CommandHandler\RenameCustomRoleHandler;
use Fight\AccessControl\Application\AccessControl\Role\CommandHandler\RevokePermissionFromCustomRoleHandler;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\AssignRoleToUserHandler;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\RemoveRoleFromUserHandler;
use Fight\AccessControl\Domain\AccessControl\Role\Command\GrantPermissionToCustomRole;
use Fight\AccessControl\Domain\AccessControl\Role\Command\RenameCustomRole;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\Role\RoleName;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Messaging\Command\CommandMessage;

return static function (
    CreateCustomRoleHandler $create,
    RenameCustomRoleHandler $rename,
    RemoveCustomRoleHandler $remove,
    GrantPermissionToCustomRoleHandler $grant,
    RevokePermissionFromCustomRoleHandler $revoke,
    AssignRoleToUserHandler $assign,
    RemoveRoleFromUserHandler $unassign,
    CommandMessage $message,
    UserId $userId,
    RoleId $roleId,
    RoleName $name
): void {
    (static fn (mixed $result): mixed => $result)($create->handle($message));
    (static fn (mixed $result): mixed => $result)($rename->handle($message));
    (static fn (mixed $result): mixed => $result)($remove->handle($message));
    (static fn (mixed $result): mixed => $result)($grant->handle($message));
    (static fn (mixed $result): mixed => $result)($revoke->handle($message));
    (static fn (mixed $result): mixed => $result)($assign->handle($message));
    (static fn (mixed $result): mixed => $result)($unassign->handle($message));
    new GrantPermissionToCustomRole($userId, $roleId, $roleId);
    new RenameCustomRole($userId, $roleId, $name, 'current');
};
