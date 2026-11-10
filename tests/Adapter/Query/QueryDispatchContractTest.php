<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Query;

use DateTimeImmutable;
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
use Fight\AccessControl\Application\AccessControl\Feature\Discovery\FeatureDiscoveryResult;
use Fight\AccessControl\Application\AccessControl\Feature\Discovery\FeatureReferenceDiscovery;
use Fight\AccessControl\Application\AccessControl\Feature\Discovery\FeatureReferences;
use Fight\AccessControl\Application\AccessControl\Feature\Discovery\FeatureReferenceScope;
use Fight\AccessControl\Application\AccessControl\Feature\QueryHandler\GetFeatureByIdHandler;
use Fight\AccessControl\Application\AccessControl\Feature\QueryHandler\ListFeaturesHandler;
use Fight\AccessControl\Application\AccessControl\Feature\QueryHandler\ValidateFeaturePreparationHandler;
use Fight\AccessControl\Application\AccessControl\ManagedPolicy\QueryHandler\PreviewManagedPolicyHandler;
use Fight\AccessControl\Application\AccessControl\ManagedPolicy\Service\ManagedPolicyPlanner;
use Fight\AccessControl\Application\AccessControl\Permission\QueryHandler\GetPermissionByIdHandler;
use Fight\AccessControl\Application\AccessControl\Permission\QueryHandler\ListPermissionsHandler;
use Fight\AccessControl\Application\AccessControl\RefreshSession\QueryHandler\ListActiveSessionsHandler;
use Fight\AccessControl\Application\AccessControl\RefreshSession\Service\SessionAdministrationAuthorization;
use Fight\AccessControl\Application\AccessControl\Role\QueryHandler\GetRoleByIdHandler;
use Fight\AccessControl\Application\AccessControl\Role\QueryHandler\ListRolesHandler;
use Fight\AccessControl\Application\AccessControl\User\QueryHandler\GetUserByIdHandler;
use Fight\AccessControl\Application\AccessControl\User\QueryHandler\ListUsersHandler;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrantRepository;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Query\FindInvitationDeliveryStatus;
use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentName;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentRepository;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\GetAgentById;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\GetAgentProfile;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\ListAgents;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Maintenance\AgentDeliveryKeyVersion;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Query\CountAgentDeliveryKeyReferences;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Query\GetAgentOperation;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Query\ListAgentDeliveryMaintenance;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Query\ListDueAgentDeliveries;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\Query\FindCredentialDeliveryStatus;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\Query\FindDueCredentialDeliveries;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\Query\FindExpiredCredentialDeliveries;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeGrantRepository;
use Fight\AccessControl\Domain\AccessControl\Feature\Feature;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureId;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureName;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureRepository;
use Fight\AccessControl\Domain\AccessControl\Feature\Query\GetFeatureById;
use Fight\AccessControl\Domain\AccessControl\Feature\Query\ListFeatures;
use Fight\AccessControl\Domain\AccessControl\Feature\Query\ValidateFeaturePreparation;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\ManagedPolicy;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\Query\PreviewManagedPolicy;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrantRepository;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionRepository;
use Fight\AccessControl\Domain\AccessControl\Permission\Query\GetPermissionById;
use Fight\AccessControl\Domain\AccessControl\Permission\Query\ListPermissions;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\Query\ListActiveSessions;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSession;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionId;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionRepository;
use Fight\AccessControl\Domain\AccessControl\Role\Query\GetRoleById;
use Fight\AccessControl\Domain\AccessControl\Role\Query\ListRoles;
use Fight\AccessControl\Domain\AccessControl\Role\Role;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\Role\RoleName;
use Fight\AccessControl\Domain\AccessControl\Role\RoleRepository;
use Fight\AccessControl\Domain\AccessControl\User\Query\GetUserById;
use Fight\AccessControl\Domain\AccessControl\User\Query\ListUsers;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\AccessControl\Domain\AccessControl\User\UserRepository;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Adapter\Messaging\Query\QueryPipeline;
use Fight\Common\Adapter\Messaging\Query\Routing\InMemoryQueryRouter;
use Fight\Common\Adapter\Messaging\Query\RoutingQueryBus;
use Fight\Common\Application\Messaging\Query\QueryBus;
use Fight\Common\Application\Messaging\Query\QueryFilter;
use Fight\Common\Application\Messaging\Query\QueryHandler;
use Fight\Common\Domain\Collection\ArrayList;
use Fight\Common\Domain\Exception\LookupException;
use Fight\Common\Domain\Messaging\Meta;
use Fight\Common\Domain\Messaging\Query\Query;
use Fight\Common\Domain\Messaging\Query\QueryMessage;
use Fight\Common\Domain\Repository\Pagination;
use Fight\Common\Domain\Repository\ResultSet;
use Fight\Test\AccessControl\Application\AccessControl\AgentCredentialOperation\Maintenance\MaintenanceEnvironment;
use Fight\Test\AccessControl\Domain\AccessControl\User\UserFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use RuntimeException;
use stdClass;
use TypeError;

#[CoversClass(FindInvitationDeliveryStatusHandler::class)]
#[CoversClass(GetAgentByIdHandler::class)]
#[CoversClass(GetAgentProfileHandler::class)]
#[CoversClass(ListAgentsHandler::class)]
#[CoversClass(CountAgentDeliveryKeyReferencesHandler::class)]
#[CoversClass(GetAgentOperationHandler::class)]
#[CoversClass(ListAgentDeliveryMaintenanceHandler::class)]
#[CoversClass(ListDueAgentDeliveriesHandler::class)]
#[CoversClass(FindCredentialDeliveryStatusHandler::class)]
#[CoversClass(FindDueCredentialDeliveriesHandler::class)]
#[CoversClass(FindExpiredCredentialDeliveriesHandler::class)]
#[CoversClass(GetFeatureByIdHandler::class)]
#[CoversClass(ListFeaturesHandler::class)]
#[CoversClass(ValidateFeaturePreparationHandler::class)]
#[CoversClass(PreviewManagedPolicyHandler::class)]
#[CoversClass(GetPermissionByIdHandler::class)]
#[CoversClass(ListPermissionsHandler::class)]
#[CoversClass(ListActiveSessionsHandler::class)]
#[CoversClass(GetRoleByIdHandler::class)]
#[CoversClass(ListRolesHandler::class)]
#[CoversClass(GetUserByIdHandler::class)]
#[CoversClass(ListUsersHandler::class)]
final class QueryDispatchContractTest extends TestCase
{
    /** @return iterable<string, array{string, bool}> */
    public static function bindings(): iterable
    {
        foreach (
            [
            'GetUserById', 'ListUsers', 'GetRoleById', 'ListRoles', 'GetPermissionById', 'ListPermissions',
            'GetAgentById', 'GetAgentProfile', 'ListAgents', 'GetAgentOperation', 'ListDueAgentDeliveries',
            'ListAgentDeliveryMaintenance', 'CountAgentDeliveryKeyReferences', 'GetFeatureById', 'ListFeatures',
            'ValidateFeaturePreparation', 'PreviewManagedPolicy', 'ListActiveSessions',
            'FindInvitationDeliveryStatus', 'FindCredentialDeliveryStatus', 'FindDueCredentialDeliveries',
            'FindExpiredCredentialDeliveries'
            ] as $name
        ) {
            yield $name.'-routing' => [$name, false];
            yield $name.'-pipeline' => [$name, true];
        }
    }

    #[DataProvider('bindings')]
    public function test_real_registered_handlers_preserve_results_and_message_contracts(
        string $name,
        bool $pipeline
    ): void {
        [$query, $handler] = $this->fixture($name);
        self::assertSame($query::class, $handler::queryRegistration());
        $router = new InMemoryQueryRouter();
        $router->registerHandler($handler::queryRegistration(), $handler);
        self::assertSame($handler, $router->match($query));
        $bus = $this->bus($router, $pipeline);
        $original = QueryMessage::create($query);
        $message = $original->withMeta(Meta::create(['origin' => 'contract', 'replace' => 1]))
            ->mergeMeta(Meta::create(['replace' => 2, 'correlation' => 'safe']));
        $serialized = $message->arraySerialize();
        self::assertSame([], $original->meta()->toArray());
        self::assertSame($original->id(), $message->id());
        self::assertSame($original->timestamp(), $message->timestamp());
        self::assertSame($query, $message->payload());
        self::assertSame(
            ['origin' => 'contract', 'replace' => 2, 'correlation' => 'safe'],
            $message->meta()->toArray()
        );
        $decoded = QueryMessage::arrayDeserialize($serialized);
        self::assertSame($serialized, $decoded->arraySerialize());
        self::assertInstanceOf($query::class, $decoded->payload());
        $expected = $bus->fetch($query);
        if ($expected instanceof ResultSet) {
            self::assertCount($name === 'ListActiveSessions' ? 0 : 1, $expected->records());
        }

        self::assertEquals($expected, $bus->dispatch($message));
        self::assertEquals($expected, $bus->dispatch($decoded));
        self::assertSame($serialized, $message->arraySerialize());
        self::assertStringNotContainsString('PRIVATE', serialize($expected));
    }

    #[DataProvider('bindings')]
    public function test_unconfigured_binding_is_not_a_fabricated_result(string $name, bool $pipeline): void
    {
        [$query] = $this->fixture($name);
        $bus = $this->bus(new InMemoryQueryRouter(), $pipeline);
        $this->expectException(LookupException::class);
        $bus->dispatch(QueryMessage::create($query)->mergeMeta(Meta::create(['origin' => 'contract'])));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function pagedBindings(): iterable
    {
        foreach (self::bindings() as $key => [$name, $pipeline]) {
            if (
                in_array($name, [
                'ListUsers', 'ListRoles', 'ListPermissions', 'ListAgents', 'ListFeatures', 'ListActiveSessions'
                ], true)
            ) {
                yield $key => [$name, $pipeline];
            }
        }
    }

    #[DataProvider('pagedBindings')]
    public function test_paged_provider_primary_failure_survives_real_fetch_and_dispatch(
        string $name,
        bool $pipeline
    ): void {
        [$query, $handler] = $this->fixture($name);
        $failure = new RuntimeException('primary page provider failure');
        $reflection = new ReflectionClass($handler);
        $constructor = $reflection->getConstructor();
        self::assertNotNull($constructor);
        $primary = $constructor->getParameters()[0]->getName();
        $arguments = [];
        foreach ($constructor->getParameters() as $parameter) {
            $collaborator = $reflection->getProperty($parameter->getName())->getValue($handler);
            if ($parameter->getName() === $primary) {
                $type = $parameter->getType();
                self::assertInstanceOf(ReflectionNamedType::class, $type);
                $repositoryType = $type->getName();
                if (!interface_exists($repositoryType)) {
                    self::fail('The primary repository must be an inward interface.');
                }

                $collaborator = $this->createMock($repositoryType);
                $collaborator->expects(self::exactly(2))
                    ->method($name === 'ListActiveSessions' ? 'getByUserId' : 'getAll')
                    ->willThrowException($failure);
            }

            $arguments[] = $collaborator;
        }

        $handler = $reflection->newInstanceArgs($arguments);
        $router = new InMemoryQueryRouter();
        $router->registerHandler($handler::queryRegistration(), $handler);

        $bus = $this->bus($router, $pipeline);
        foreach ([false, true] as $dispatch) {
            try {
                if ($dispatch) {
                    $bus->dispatch(QueryMessage::create($query)->mergeMeta(Meta::create(['origin' => 'contract'])));
                } else {
                    $bus->fetch($query);
                }

                self::fail('Provider failure became an empty or stale result.');
            } catch (RuntimeException $runtimeException) {
                self::assertSame($failure, $runtimeException);
            }
        }
    }

    public function test_pipeline_preserves_primary_throwable_and_exact_message_instance(): void
    {
        [$query] = $this->fixture('ListUsers');
        $failure = new RuntimeException('primary');
        $handler = $this->createMock(QueryHandler::class);
        $message = QueryMessage::create($query)->withMeta(Meta::create(['origin' => 'contract']));
        $handler->expects(self::once())->method('handle')->with(self::identicalTo($message))
            ->willThrowException($failure);
        $router = new InMemoryQueryRouter();
        $router->registerHandler(ListUsers::class, $handler);

        $filter = $this->createMock(QueryFilter::class);
        $filter->expects(self::once())->method('process')->willReturnCallback(
            static function (QueryMessage $received, callable $next) use ($message): void {
                self::assertSame($message, $received);
                $next($received);
            }
        );
        $bus = new QueryPipeline(new RoutingQueryBus($router));
        $bus->addFilter($filter);
        try {
            $bus->dispatch($message);
            self::fail('Primary failure was lost.');
        } catch (RuntimeException $runtimeException) {
            self::assertSame($failure, $runtimeException);
        }
    }

    public function test_native_message_boundary_rejects_non_query_payload(): void
    {
        $this->expectException(TypeError::class);
        new ReflectionMethod(QueryMessage::class, 'create')->invoke(null, new stdClass());
    }

    public function test_native_registration_boundary_rejects_non_handler(): void
    {
        $this->expectException(TypeError::class);
        new ReflectionMethod(InMemoryQueryRouter::class, 'registerHandler')->invoke(
            new InMemoryQueryRouter(),
            ListUsers::class,
            new stdClass()
        );
    }

    private function bus(InMemoryQueryRouter $router, bool $pipeline): QueryBus
    {
        $bus = new RoutingQueryBus($router);

        return $pipeline ? new QueryPipeline($bus) : $bus;
    }

    /** @return array{Query, QueryHandler} */
    private function fixture(string $name): array
    {
        $now = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $pagination = new Pagination(1, 10);
        $userId = UserId::generate();
        $user = UserFixture::withIdAndAuthenticationVersion($userId, 'safe@example.test', UserState::ACTIVE, 1);
        $role = Role::define(RoleId::generate(), RoleName::fromString('ROLE_READER'), [], $now);
        $permission = Permission::define(PermissionId::generate(), PermissionName::fromString('READ_CASES'), $now);
        $agent = Agent::provision(
            AgentId::generate(),
            AgentName::fromString('Worker'),
            AgentCredentialId::generate(),
            'PRIVATE',
            $now
        );
        $feature = Feature::define(FeatureId::generate(), FeatureName::fromString('dashboard'), $permission->getId());
        $users = $this->createStub(UserRepository::class);
        $users->method('getById')->willReturn($user);
        $users->method('getAll')->willReturn(new ResultSet(1, 10, 1, ArrayList::of(User::class)->replace([$user])));
        $roles = $this->createStub(RoleRepository::class);
        $roles->method('getById')->willReturn($role);
        $roles->method('getAll')->willReturn(new ResultSet(1, 10, 1, ArrayList::of(Role::class)->replace([$role])));
        $permissions = $this->createStub(PermissionRepository::class);
        $permissions->method('getById')->willReturn($permission);
        $permissions->method('getByIds')->willReturn([]);
        $permissions->method('getAll')->willReturn(
            new ResultSet(1, 10, 1, ArrayList::of(Permission::class)->replace([$permission]))
        );
        $agents = $this->createStub(AgentRepository::class);
        $agents->method('getById')->willReturn($agent);
        $agents->method('getAll')->willReturn(new ResultSet(1, 10, 1, ArrayList::of(Agent::class)->replace([$agent])));
        $features = $this->createStub(FeatureRepository::class);
        $features->method('getAll')->willReturn(
            new ResultSet(1, 10, 1, ArrayList::of(Feature::class)->replace([$feature]))
        );
        $features->method('getById')->willReturn($feature);
        $sessions = $this->createStub(RefreshSessionRepository::class);
        $sessions->method('getByUserId')->willReturn(new ResultSet(1, 10, 0, ArrayList::of(RefreshSession::class)));
        $activation = $this->createStub(ActivationGrantRepository::class);
        $reset = $this->createStub(PasswordResetGrantRepository::class);
        $email = $this->createStub(EmailChangeGrantRepository::class);
        $discovery = $this->createStub(FeatureReferenceDiscovery::class);
        $discovery->method('discover')->willReturn(FeatureDiscoveryResult::complete(
            FeatureReferenceScope::CANDIDATE,
            new FeatureReferences()
        ));
        $env = new MaintenanceEnvironment();
        $repository = $env->delivery->provisioning->operationRepository;
        $key = $env->delivery->issuance->getKey();
        $destination = $env->delivery->issuance->getDestination();
        $clock = $env->delivery->clock;
        $bindings = [
            'GetUserById'                     => [new GetUserById($userId), new GetUserByIdHandler($users)],
            'ListUsers'                       => [new ListUsers($pagination), new ListUsersHandler($users)],
            'GetRoleById'                     => [new GetRoleById($role->getId()), new GetRoleByIdHandler($roles)],
            'ListRoles'                       => [new ListRoles($pagination), new ListRolesHandler($roles)],
            'GetPermissionById'               => [new GetPermissionById($permission->getId()),
                new GetPermissionByIdHandler($permissions)],
            'ListPermissions'                 => [new ListPermissions($pagination),
                new ListPermissionsHandler($permissions)],
            'GetAgentById'                    => [new GetAgentById($agent->getId()),
                new GetAgentByIdHandler($agents, $permissions)],
            'GetAgentProfile'                 => [new GetAgentProfile($agent->getId()),
                new GetAgentProfileHandler($agents)],
            'ListAgents'                      => [new ListAgents($pagination),
                new ListAgentsHandler($agents, $permissions)],
            'GetFeatureById'                  => [new GetFeatureById($feature->getId()),
                new GetFeatureByIdHandler($features, $permissions)],
            'ListFeatures'                    => [new ListFeatures($pagination),
                new ListFeaturesHandler($features, $permissions)],
            'ValidateFeaturePreparation'      => [new ValidateFeaturePreparation(),
                new ValidateFeaturePreparationHandler($discovery, $features, $permissions)],
            'PreviewManagedPolicy'            => [new PreviewManagedPolicy(new ManagedPolicy([], [])),
                new PreviewManagedPolicyHandler(new ManagedPolicyPlanner($permissions, $roles, $users, $agents))],
            'ListActiveSessions'              => [
                new ListActiveSessions($userId, $userId, RefreshSessionId::generate(), $pagination),
                new ListActiveSessionsHandler(
                    $sessions,
                    $clock,
                    $this->createStub(SessionAdministrationAuthorization::class)
                )],
            'FindInvitationDeliveryStatus'    => [new FindInvitationDeliveryStatus($userId),
                new FindInvitationDeliveryStatusHandler($activation)],
            'FindCredentialDeliveryStatus'    => [new FindCredentialDeliveryStatus('activation', $userId->toString()),
                new FindCredentialDeliveryStatusHandler($activation, $reset, $email)],
            'FindDueCredentialDeliveries'     => [new FindDueCredentialDeliveries($now, 10),
                new FindDueCredentialDeliveriesHandler($activation, $reset, $email)],
            'FindExpiredCredentialDeliveries' => [new FindExpiredCredentialDeliveries($now, 10),
                new FindExpiredCredentialDeliveriesHandler($activation, $reset, $email)],
            'GetAgentOperation'               => [new GetAgentOperation($key, $destination),
                new GetAgentOperationHandler($repository, $env->delivery->provisioning->operationAuthorization)],
            'ListDueAgentDeliveries'          => [new ListDueAgentDeliveries($key->getScope(), $destination),
                new ListDueAgentDeliveriesHandler($repository, $env->delivery->deliveryAuthorization, $clock)],
            'ListAgentDeliveryMaintenance'    => [new ListAgentDeliveryMaintenance($key->getScope(), $destination),
                new ListAgentDeliveryMaintenanceHandler($repository, $env, $clock)],
            'CountAgentDeliveryKeyReferences' => [
                new CountAgentDeliveryKeyReferences(new AgentDeliveryKeyVersion('test-key-v1')),
                new CountAgentDeliveryKeyReferencesHandler($repository, $env, $clock)]
        ];

        return $bindings[$name];
    }
}
