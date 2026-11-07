<?php

declare(strict_types=1);

use Fight\AccessControl\Application\AccessControl\Role\CommandHandler\CreateCustomRoleHandler;
use Fight\AccessControl\Application\AccessControl\Role\CommandHandler\GrantPermissionToCustomRoleHandler;
use Fight\AccessControl\Application\AccessControl\Role\CommandHandler\RemoveCustomRoleHandler;
use Fight\AccessControl\Application\AccessControl\Role\CommandHandler\RenameCustomRoleHandler;
use Fight\AccessControl\Application\AccessControl\Role\CommandHandler\RevokePermissionFromCustomRoleHandler;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\AssignRoleToUserHandler;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\RemoveRoleFromUserHandler;

return static function (
    CreateCustomRoleHandler $create,
    RenameCustomRoleHandler $rename,
    RemoveCustomRoleHandler $remove,
    GrantPermissionToCustomRoleHandler $grant,
    RevokePermissionFromCustomRoleHandler $revoke,
    AssignRoleToUserHandler $assign,
    RemoveRoleFromUserHandler $unassign
): void {
    PHPStan\dumpType($create->handle(...));
    PHPStan\dumpType($rename->handle(...));
    PHPStan\dumpType($remove->handle(...));
    PHPStan\dumpType($grant->handle(...));
    PHPStan\dumpType($revoke->handle(...));
    PHPStan\dumpType($assign->handle(...));
    PHPStan\dumpType($unassign->handle(...));
};
