<?php

declare(strict_types=1);

use Fight\AccessControl\Application\AccessControl\Agent\QueryHandler\GetAgentByIdHandler;
use Fight\AccessControl\Application\AccessControl\Agent\QueryHandler\GetAgentProfileHandler;
use Fight\AccessControl\Application\AccessControl\Feature\QueryHandler\GetFeatureByIdHandler;
use Fight\AccessControl\Application\AccessControl\Permission\QueryHandler\GetPermissionByIdHandler;
use Fight\AccessControl\Application\AccessControl\Role\QueryHandler\GetRoleByIdHandler;
use Fight\AccessControl\Application\AccessControl\User\QueryHandler\GetUserByIdHandler;
use Fight\Common\Domain\Messaging\Query\QueryMessage;

use function PHPStan\dumpType;

static function (
    GetUserByIdHandler $user,
    GetRoleByIdHandler $role,
    GetPermissionByIdHandler $permission,
    GetAgentByIdHandler $agent,
    GetAgentProfileHandler $profile,
    GetFeatureByIdHandler $feature,
    QueryMessage $message
): void {
    dumpType($user->handle($message));
    dumpType($role->handle($message));
    dumpType($permission->handle($message));
    dumpType($agent->handle($message));
    dumpType($profile->handle($message));
    dumpType($feature->handle($message));
};
