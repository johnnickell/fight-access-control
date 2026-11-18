<?php

declare(strict_types=1);

use Fight\AccessControl\Application\AccessControl\ActivationGrant\QueryHandler\FindInvitationDeliveryStatusHandler;
use Fight\AccessControl\Application\AccessControl\Agent\QueryHandler\GetAgentByIdHandler;
use Fight\AccessControl\Application\AccessControl\Agent\QueryHandler\GetAgentProfileHandler;
use Fight\AccessControl\Application\AccessControl\Agent\QueryHandler\ListAgentsHandler;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\QueryHandler\{
    CountAgentDeliveryKeyReferencesHandler
};
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\QueryHandler\GetAgentOperationHandler;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\QueryHandler\{
    ListAgentDeliveryMaintenanceHandler
};
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\QueryHandler\ListDueAgentDeliveriesHandler;
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
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Query\FindInvitationDeliveryStatus;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Query\InvitationDeliveryStatusView;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\AgentProfileView;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\AgentView;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\GetAgentById;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\GetAgentProfile;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\ListAgents;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Query\AgentOperationView;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Query\CountAgentDeliveryKeyReferences;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Query\GetAgentOperation;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Query\ListAgentDeliveryMaintenance;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Query\ListDueAgentDeliveries;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\DueCredentialDelivery;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\ExpiredCredentialDelivery;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\Query\CredentialDeliveryAbsent;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\Query\CredentialDeliveryStatusView;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\Query\FindCredentialDeliveryStatus;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\Query\FindDueCredentialDeliveries;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\Query\FindExpiredCredentialDeliveries;
use Fight\AccessControl\Domain\AccessControl\Feature\Query\FeaturePreparationResult;
use Fight\AccessControl\Domain\AccessControl\Feature\Query\FeatureView;
use Fight\AccessControl\Domain\AccessControl\Feature\Query\GetFeatureById;
use Fight\AccessControl\Domain\AccessControl\Feature\Query\ListFeatures;
use Fight\AccessControl\Domain\AccessControl\Feature\Query\ValidateFeaturePreparation;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\ManagedPolicyPlan;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\Query\PreviewManagedPolicy;
use Fight\AccessControl\Domain\AccessControl\Permission\Query\GetPermissionById;
use Fight\AccessControl\Domain\AccessControl\Permission\Query\ListPermissions;
use Fight\AccessControl\Domain\AccessControl\Permission\Query\PermissionView;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\Query\ListActiveSessions;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\Query\SessionView;
use Fight\AccessControl\Domain\AccessControl\Role\Query\GetRoleById;
use Fight\AccessControl\Domain\AccessControl\Role\Query\ListRoles;
use Fight\AccessControl\Domain\AccessControl\Role\Query\RoleView;
use Fight\AccessControl\Domain\AccessControl\User\Query\GetUserById;
use Fight\AccessControl\Domain\AccessControl\User\Query\ListUsers;
use Fight\AccessControl\Domain\AccessControl\User\Query\UserView;
use Fight\Common\Adapter\Messaging\Query\QueryPipeline;
use Fight\Common\Adapter\Messaging\Query\Routing\InMemoryQueryRouter;
use Fight\Common\Adapter\Messaging\Query\RoutingQueryBus;
use Fight\Common\Application\Messaging\Query\QueryBus;
use Fight\Common\Domain\Messaging\Meta;
use Fight\Common\Domain\Messaging\Query\QueryMessage;
use Fight\Common\Domain\Repository\ResultSet;

use function PHPStan\dumpType;

static function (
    FindInvitationDeliveryStatusHandler $handler,
    FindInvitationDeliveryStatus $query,
    QueryBus $bus
): void {
    dumpType($handler->handle(QueryMessage::create($query)));
    /** @var InvitationDeliveryStatusView|CredentialDeliveryAbsent $fetched */
    $fetched = $bus->fetch($query);
    dumpType($fetched);
    $message = QueryMessage::create($query)->withMeta(Meta::create(['origin' => 'contract']))
        ->mergeMeta(Meta::create(['correlation' => 'safe']));
    /** @var InvitationDeliveryStatusView|CredentialDeliveryAbsent $dispatched */
    $dispatched = $bus->dispatch($message);
    dumpType($dispatched);
};

static function (GetAgentByIdHandler $handler, GetAgentById $query, QueryBus $bus): void {
    dumpType($handler->handle(QueryMessage::create($query)));
    /** @var AgentView $fetched */
    $fetched = $bus->fetch($query);
    dumpType($fetched);
    $message = QueryMessage::create($query)->withMeta(Meta::create(['origin' => 'contract']))
        ->mergeMeta(Meta::create(['correlation' => 'safe']));
    /** @var AgentView $dispatched */
    $dispatched = $bus->dispatch($message);
    dumpType($dispatched);
};

static function (GetAgentProfileHandler $handler, GetAgentProfile $query, QueryBus $bus): void {
    dumpType($handler->handle(QueryMessage::create($query)));
    /** @var AgentProfileView $fetched */
    $fetched = $bus->fetch($query);
    dumpType($fetched);
    $message = QueryMessage::create($query)->withMeta(Meta::create(['origin' => 'contract']))
        ->mergeMeta(Meta::create(['correlation' => 'safe']));
    /** @var AgentProfileView $dispatched */
    $dispatched = $bus->dispatch($message);
    dumpType($dispatched);
};

static function (ListAgentsHandler $handler, ListAgents $query, QueryBus $bus): void {
    dumpType($handler->handle(QueryMessage::create($query)));
    /** @var ResultSet<AgentView> $fetched */
    $fetched = $bus->fetch($query);
    dumpType($fetched);
    $message = QueryMessage::create($query)->withMeta(Meta::create(['origin' => 'contract']))
        ->mergeMeta(Meta::create(['correlation' => 'safe']));
    /** @var ResultSet<AgentView> $dispatched */
    $dispatched = $bus->dispatch($message);
    dumpType($dispatched);
    foreach ($dispatched->records() as $record) {
        dumpType($record);
    }
};

static function (
    CountAgentDeliveryKeyReferencesHandler $handler,
    CountAgentDeliveryKeyReferences $query,
    QueryBus $bus
): void {
    dumpType($handler->handle(QueryMessage::create($query)));
    /** @var int $fetched */
    $fetched = $bus->fetch($query);
    dumpType($fetched);
    $message = QueryMessage::create($query)->withMeta(Meta::create(['origin' => 'contract']))
        ->mergeMeta(Meta::create(['correlation' => 'safe']));
    /** @var int $dispatched */
    $dispatched = $bus->dispatch($message);
    dumpType($dispatched);
};

static function (GetAgentOperationHandler $handler, GetAgentOperation $query, QueryBus $bus): void {
    dumpType($handler->handle(QueryMessage::create($query)));
    /** @var AgentOperationView $fetched */
    $fetched = $bus->fetch($query);
    dumpType($fetched);
    $message = QueryMessage::create($query)->withMeta(Meta::create(['origin' => 'contract']))
        ->mergeMeta(Meta::create(['correlation' => 'safe']));
    /** @var AgentOperationView $dispatched */
    $dispatched = $bus->dispatch($message);
    dumpType($dispatched);
};

static function (
    ListAgentDeliveryMaintenanceHandler $handler,
    ListAgentDeliveryMaintenance $query,
    QueryBus $bus
): void {
    dumpType($handler->handle(QueryMessage::create($query)));
    /** @var list<AgentOperationView> $fetched */
    $fetched = $bus->fetch($query);
    dumpType($fetched);
    $message = QueryMessage::create($query)->withMeta(Meta::create(['origin' => 'contract']))
        ->mergeMeta(Meta::create(['correlation' => 'safe']));
    /** @var list<AgentOperationView> $dispatched */
    $dispatched = $bus->dispatch($message);
    dumpType($dispatched);
    foreach ($dispatched as $record) {
        dumpType($record);
    }
};

static function (ListDueAgentDeliveriesHandler $handler, ListDueAgentDeliveries $query, QueryBus $bus): void {
    dumpType($handler->handle(QueryMessage::create($query)));
    /** @var list<AgentOperationView> $fetched */
    $fetched = $bus->fetch($query);
    dumpType($fetched);
    $message = QueryMessage::create($query)->withMeta(Meta::create(['origin' => 'contract']))
        ->mergeMeta(Meta::create(['correlation' => 'safe']));
    /** @var list<AgentOperationView> $dispatched */
    $dispatched = $bus->dispatch($message);
    dumpType($dispatched);
    foreach ($dispatched as $record) {
        dumpType($record);
    }
};

static function (
    FindCredentialDeliveryStatusHandler $handler,
    FindCredentialDeliveryStatus $query,
    QueryBus $bus
): void {
    dumpType($handler->handle(QueryMessage::create($query)));
    /** @var CredentialDeliveryStatusView|CredentialDeliveryAbsent $fetched */
    $fetched = $bus->fetch($query);
    dumpType($fetched);
    $message = QueryMessage::create($query)->withMeta(Meta::create(['origin' => 'contract']))
        ->mergeMeta(Meta::create(['correlation' => 'safe']));
    /** @var CredentialDeliveryStatusView|CredentialDeliveryAbsent $dispatched */
    $dispatched = $bus->dispatch($message);
    dumpType($dispatched);
};

static function (FindDueCredentialDeliveriesHandler $handler, FindDueCredentialDeliveries $query, QueryBus $bus): void {
    dumpType($handler->handle(QueryMessage::create($query)));
    /** @var list<DueCredentialDelivery> $fetched */
    $fetched = $bus->fetch($query);
    dumpType($fetched);
    $message = QueryMessage::create($query)->withMeta(Meta::create(['origin' => 'contract']))
        ->mergeMeta(Meta::create(['correlation' => 'safe']));
    /** @var list<DueCredentialDelivery> $dispatched */
    $dispatched = $bus->dispatch($message);
    dumpType($dispatched);
    foreach ($dispatched as $record) {
        dumpType($record);
    }
};

static function (
    FindExpiredCredentialDeliveriesHandler $handler,
    FindExpiredCredentialDeliveries $query,
    QueryBus $bus
): void {
    dumpType($handler->handle(QueryMessage::create($query)));
    /** @var list<ExpiredCredentialDelivery> $fetched */
    $fetched = $bus->fetch($query);
    dumpType($fetched);
    $message = QueryMessage::create($query)->withMeta(Meta::create(['origin' => 'contract']))
        ->mergeMeta(Meta::create(['correlation' => 'safe']));
    /** @var list<ExpiredCredentialDelivery> $dispatched */
    $dispatched = $bus->dispatch($message);
    dumpType($dispatched);
    foreach ($dispatched as $record) {
        dumpType($record);
    }
};

static function (GetFeatureByIdHandler $handler, GetFeatureById $query, QueryBus $bus): void {
    dumpType($handler->handle(QueryMessage::create($query)));
    /** @var FeatureView $fetched */
    $fetched = $bus->fetch($query);
    dumpType($fetched);
    $message = QueryMessage::create($query)->withMeta(Meta::create(['origin' => 'contract']))
        ->mergeMeta(Meta::create(['correlation' => 'safe']));
    /** @var FeatureView $dispatched */
    $dispatched = $bus->dispatch($message);
    dumpType($dispatched);
};

static function (ListFeaturesHandler $handler, ListFeatures $query, QueryBus $bus): void {
    dumpType($handler->handle(QueryMessage::create($query)));
    /** @var ResultSet<FeatureView> $fetched */
    $fetched = $bus->fetch($query);
    dumpType($fetched);
    $message = QueryMessage::create($query)->withMeta(Meta::create(['origin' => 'contract']))
        ->mergeMeta(Meta::create(['correlation' => 'safe']));
    /** @var ResultSet<FeatureView> $dispatched */
    $dispatched = $bus->dispatch($message);
    dumpType($dispatched);
    foreach ($dispatched->records() as $record) {
        dumpType($record);
    }
};

static function (ValidateFeaturePreparationHandler $handler, ValidateFeaturePreparation $query, QueryBus $bus): void {
    dumpType($handler->handle(QueryMessage::create($query)));
    /** @var FeaturePreparationResult $fetched */
    $fetched = $bus->fetch($query);
    dumpType($fetched);
    $message = QueryMessage::create($query)->withMeta(Meta::create(['origin' => 'contract']))
        ->mergeMeta(Meta::create(['correlation' => 'safe']));
    /** @var FeaturePreparationResult $dispatched */
    $dispatched = $bus->dispatch($message);
    dumpType($dispatched);
};

static function (PreviewManagedPolicyHandler $handler, PreviewManagedPolicy $query, QueryBus $bus): void {
    dumpType($handler->handle(QueryMessage::create($query)));
    /** @var ManagedPolicyPlan $fetched */
    $fetched = $bus->fetch($query);
    dumpType($fetched);
    $message = QueryMessage::create($query)->withMeta(Meta::create(['origin' => 'contract']))
        ->mergeMeta(Meta::create(['correlation' => 'safe']));
    /** @var ManagedPolicyPlan $dispatched */
    $dispatched = $bus->dispatch($message);
    dumpType($dispatched);
};

static function (GetPermissionByIdHandler $handler, GetPermissionById $query, QueryBus $bus): void {
    dumpType($handler->handle(QueryMessage::create($query)));
    /** @var PermissionView $fetched */
    $fetched = $bus->fetch($query);
    dumpType($fetched);
    $message = QueryMessage::create($query)->withMeta(Meta::create(['origin' => 'contract']))
        ->mergeMeta(Meta::create(['correlation' => 'safe']));
    /** @var PermissionView $dispatched */
    $dispatched = $bus->dispatch($message);
    dumpType($dispatched);
};

static function (ListPermissionsHandler $handler, ListPermissions $query, QueryBus $bus): void {
    dumpType($handler->handle(QueryMessage::create($query)));
    /** @var ResultSet<PermissionView> $fetched */
    $fetched = $bus->fetch($query);
    dumpType($fetched);
    $message = QueryMessage::create($query)->withMeta(Meta::create(['origin' => 'contract']))
        ->mergeMeta(Meta::create(['correlation' => 'safe']));
    /** @var ResultSet<PermissionView> $dispatched */
    $dispatched = $bus->dispatch($message);
    dumpType($dispatched);
    foreach ($dispatched->records() as $record) {
        dumpType($record);
    }
};

static function (ListActiveSessionsHandler $handler, ListActiveSessions $query, QueryBus $bus): void {
    dumpType($handler->handle(QueryMessage::create($query)));
    /** @var ResultSet<SessionView> $fetched */
    $fetched = $bus->fetch($query);
    dumpType($fetched);
    $message = QueryMessage::create($query)->withMeta(Meta::create(['origin' => 'contract']))
        ->mergeMeta(Meta::create(['correlation' => 'safe']));
    /** @var ResultSet<SessionView> $dispatched */
    $dispatched = $bus->dispatch($message);
    dumpType($dispatched);
    foreach ($dispatched->records() as $record) {
        dumpType($record);
    }
};

static function (GetRoleByIdHandler $handler, GetRoleById $query, QueryBus $bus): void {
    dumpType($handler->handle(QueryMessage::create($query)));
    /** @var RoleView $fetched */
    $fetched = $bus->fetch($query);
    dumpType($fetched);
    $message = QueryMessage::create($query)->withMeta(Meta::create(['origin' => 'contract']))
        ->mergeMeta(Meta::create(['correlation' => 'safe']));
    /** @var RoleView $dispatched */
    $dispatched = $bus->dispatch($message);
    dumpType($dispatched);
};

static function (ListRolesHandler $handler, ListRoles $query, QueryBus $bus): void {
    dumpType($handler->handle(QueryMessage::create($query)));
    /** @var ResultSet<RoleView> $fetched */
    $fetched = $bus->fetch($query);
    dumpType($fetched);
    $message = QueryMessage::create($query)->withMeta(Meta::create(['origin' => 'contract']))
        ->mergeMeta(Meta::create(['correlation' => 'safe']));
    /** @var ResultSet<RoleView> $dispatched */
    $dispatched = $bus->dispatch($message);
    dumpType($dispatched);
    foreach ($dispatched->records() as $record) {
        dumpType($record);
    }
};

static function (GetUserByIdHandler $handler, GetUserById $query, QueryBus $bus): void {
    dumpType($handler->handle(QueryMessage::create($query)));
    /** @var UserView $fetched */
    $fetched = $bus->fetch($query);
    dumpType($fetched);
    $message = QueryMessage::create($query)->withMeta(Meta::create(['origin' => 'contract']))
        ->mergeMeta(Meta::create(['correlation' => 'safe']));
    /** @var UserView $dispatched */
    $dispatched = $bus->dispatch($message);
    dumpType($dispatched);
};

static function (ListUsersHandler $handler, ListUsers $query, QueryBus $bus): void {
    dumpType($handler->handle(QueryMessage::create($query)));
    /** @var ResultSet<UserView> $fetched */
    $fetched = $bus->fetch($query);
    dumpType($fetched);
    $message = QueryMessage::create($query)->withMeta(Meta::create(['origin' => 'contract']))
        ->mergeMeta(Meta::create(['correlation' => 'safe']));
    /** @var ResultSet<UserView> $dispatched */
    $dispatched = $bus->dispatch($message);
    dumpType($dispatched);
    foreach ($dispatched->records() as $record) {
        dumpType($record);
    }
};

static function (
    QueryBus $interface,
    RoutingQueryBus $routing,
    QueryPipeline $pipeline,
    QueryMessage $message,
    ListUsers $query
): void {
    dumpType($interface->fetch($query));
    dumpType($interface->dispatch($message));
    dumpType($routing->fetch($query));
    dumpType($routing->dispatch($message));
    dumpType($pipeline->fetch($query));
    dumpType($pipeline->dispatch($message));
};
