<?php

declare(strict_types=1);

use Fight\AccessControl\Application\AccessControl\Agent\QueryHandler\ListAgentsHandler;
use Fight\AccessControl\Application\AccessControl\Feature\QueryHandler\ListFeaturesHandler;
use Fight\AccessControl\Application\AccessControl\Permission\QueryHandler\ListPermissionsHandler;
use Fight\AccessControl\Application\AccessControl\RefreshSession\QueryHandler\ListActiveSessionsHandler;
use Fight\AccessControl\Application\AccessControl\Role\QueryHandler\ListRolesHandler;
use Fight\AccessControl\Application\AccessControl\User\QueryHandler\ListUsersHandler;
use Fight\Common\Domain\Messaging\Query\QueryMessage;

use function PHPStan\dumpType;

static function (
    ListUsersHandler $users,
    ListRolesHandler $roles,
    ListPermissionsHandler $permissions,
    ListAgentsHandler $agents,
    ListFeaturesHandler $features,
    ListActiveSessionsHandler $sessions,
    QueryMessage $message
): void {
    dumpType($users->handle($message));
    dumpType($roles->handle($message));
    dumpType($permissions->handle($message));
    dumpType($agents->handle($message));
    dumpType($features->handle($message));
    dumpType($sessions->handle($message));
    dumpType($users->handle($message)->records()->toArray()[0]);
    dumpType($roles->handle($message)->records()->toArray()[0]);
    dumpType($permissions->handle($message)->records()->toArray()[0]);
    dumpType($agents->handle($message)->records()->toArray()[0]);
    dumpType($features->handle($message)->records()->toArray()[0]);
    dumpType($sessions->handle($message)->records()->toArray()[0]);
};
