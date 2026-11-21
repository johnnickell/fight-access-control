<?php

declare(strict_types=1);

use Fight\AccessControl\Application\AccessControl\ActivationGrant\QueryHandler\FindInvitationDeliveryStatusHandler;
use Fight\AccessControl\Application\AccessControl\Agent\QueryHandler\GetAgentByIdHandler;
use Fight\AccessControl\Application\AccessControl\Agent\QueryHandler\GetAgentProfileHandler;
use Fight\AccessControl\Application\AccessControl\Agent\QueryHandler\ListAgentsHandler;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\QueryHandler\{
    CountAgentDeliveryKeyReferencesHandler,
    GetAgentOperationHandler,
    ListAgentDeliveryMaintenanceHandler,
    ListDueAgentDeliveriesHandler
};
use Fight\AccessControl\Application\AccessControl\CredentialDelivery\QueryHandler\FindCredentialDeliveryStatusHandler;
use Fight\AccessControl\Application\AccessControl\CredentialDelivery\QueryHandler\FindDueCredentialDeliveriesHandler;
use Fight\AccessControl\Application\AccessControl\CredentialDelivery\QueryHandler\{
    FindExpiredCredentialDeliveriesHandler
};
use Fight\AccessControl\Application\AccessControl\Feature\QueryHandler\GetFeatureByIdHandler;
use Fight\AccessControl\Application\AccessControl\Feature\QueryHandler\ListFeaturesHandler;
use Fight\AccessControl\Application\AccessControl\Feature\QueryHandler\ValidateFeaturePreparationHandler;
use Fight\AccessControl\Application\AccessControl\ManagedPolicy\QueryHandler\PreviewManagedPolicyHandler;
use Fight\AccessControl\Application\AccessControl\Permission\QueryHandler\GetPermissionByIdHandler;
use Fight\AccessControl\Application\AccessControl\Permission\QueryHandler\ListPermissionsHandler;
use Fight\AccessControl\Application\AccessControl\RefreshSession\QueryHandler\ListActiveSessionsHandler;
use Fight\AccessControl\Application\AccessControl\Role\QueryHandler\GetRoleByIdHandler;
use Fight\AccessControl\Application\AccessControl\Role\QueryHandler\ListRolesHandler;
use Fight\AccessControl\Application\AccessControl\User\QueryHandler\GetUserByIdHandler;
use Fight\AccessControl\Application\AccessControl\User\QueryHandler\ListUsersHandler;
use Fight\AccessControl\Domain\AccessControl\Role\Query\RoleView;
use Fight\AccessControl\Domain\AccessControl\User\Query\ListUsers;
use Fight\AccessControl\Domain\AccessControl\User\Query\UserView;
use Fight\Common\Adapter\Messaging\Query\Routing\InMemoryQueryRouter;
use Fight\Common\Application\Messaging\Query\QueryBus;
use Fight\Common\Domain\Messaging\Meta;
use Fight\Common\Domain\Messaging\Query\QueryMessage;
use Fight\Common\Domain\Repository\ResultSet;

static function (
    FindInvitationDeliveryStatusHandler $invitation,
    GetAgentByIdHandler $agent,
    GetAgentProfileHandler $profile,
    ListAgentsHandler $agents,
    CountAgentDeliveryKeyReferencesHandler $count,
    GetAgentOperationHandler $operation,
    ListAgentDeliveryMaintenanceHandler $maintenance,
    ListDueAgentDeliveriesHandler $deliveries,
    FindCredentialDeliveryStatusHandler $credential,
    FindDueCredentialDeliveriesHandler $due,
    FindExpiredCredentialDeliveriesHandler $expired,
    GetFeatureByIdHandler $feature,
    ListFeaturesHandler $features,
    ValidateFeaturePreparationHandler $preparation,
    PreviewManagedPolicyHandler $policy,
    GetPermissionByIdHandler $permission,
    ListPermissionsHandler $permissions,
    ListActiveSessionsHandler $sessions,
    GetRoleByIdHandler $role,
    ListRolesHandler $roles,
    GetUserByIdHandler $user,
    ListUsersHandler $users,
    QueryMessage $message
): void {
    $requiresObject = static function (stdClass $value): void {
        $value->seen = true;
    };
    $requiresObject($invitation->handle($message));
    $requiresObject($agent->handle($message));
    $requiresObject($profile->handle($message));
    $requiresObject($agents->handle($message));
    $requiresObject($count->handle($message));
    $requiresObject($operation->handle($message));
    $requiresObject($maintenance->handle($message));
    $requiresObject($deliveries->handle($message));
    $requiresObject($credential->handle($message));
    $requiresObject($due->handle($message));
    $requiresObject($expired->handle($message));
    $requiresObject($feature->handle($message));
    $requiresObject($features->handle($message));
    $requiresObject($preparation->handle($message));
    $requiresObject($policy->handle($message));
    $requiresObject($permission->handle($message));
    $requiresObject($permissions->handle($message));
    $requiresObject($sessions->handle($message));
    $requiresObject($role->handle($message));
    $requiresObject($roles->handle($message));
    $requiresObject($user->handle($message));
    $requiresObject($users->handle($message));
};

static function (QueryBus $bus, InMemoryQueryRouter $router, ListUsers $query): void {
    QueryMessage::create(new stdClass());
    $bus->fetch(new stdClass());
    $bus->dispatch($query);
    $router->registerHandler(ListUsers::class, new stdClass());
    /** @var ResultSet<UserView> $fetched */
    $fetched = $bus->fetch($query);
    /** @var ResultSet<UserView> $dispatched */
    $dispatched = $bus->dispatch(QueryMessage::create($query)->withMeta(Meta::create())
        ->mergeMeta(Meta::create(['correlation' => 'safe'])));
    $requiresRole = static function (RoleView $value): void {
        echo $value->getRoleId()->toString();
    };
    foreach ($fetched->records() as $record) {
        $requiresRole($record);
    }
    foreach ($dispatched->records() as $record) {
        $requiresRole($record);
    }
};
