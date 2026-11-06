<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Query;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\Agent\QueryHandler\GetAgentByIdHandler;
use Fight\AccessControl\Application\AccessControl\Agent\QueryHandler\GetAgentProfileHandler;
use Fight\AccessControl\Application\AccessControl\Feature\QueryHandler\GetFeatureByIdHandler;
use Fight\AccessControl\Application\AccessControl\Permission\QueryHandler\GetPermissionByIdHandler;
use Fight\AccessControl\Application\AccessControl\Role\QueryHandler\GetRoleByIdHandler;
use Fight\AccessControl\Application\AccessControl\User\QueryHandler\GetUserByIdHandler;
use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentName;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentRepository;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentReadException;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\AgentProfileView;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\AgentView;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\GetAgentById;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\GetAgentProfile;
use Fight\AccessControl\Domain\AccessControl\Feature\Exception\FeatureStateException;
use Fight\AccessControl\Domain\AccessControl\Feature\Feature;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureId;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureName;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureRepository;
use Fight\AccessControl\Domain\AccessControl\Feature\Query\FeatureView;
use Fight\AccessControl\Domain\AccessControl\Feature\Query\GetFeatureById;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionRepository;
use Fight\AccessControl\Domain\AccessControl\Permission\Query\GetPermissionById;
use Fight\AccessControl\Domain\AccessControl\Permission\Query\PermissionView;
use Fight\AccessControl\Domain\AccessControl\Role\Query\GetRoleById;
use Fight\AccessControl\Domain\AccessControl\Role\Query\RoleView;
use Fight\AccessControl\Domain\AccessControl\Role\Role;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\Role\RoleName;
use Fight\AccessControl\Domain\AccessControl\Role\RoleRepository;
use Fight\AccessControl\Domain\AccessControl\User\Query\GetUserById;
use Fight\AccessControl\Domain\AccessControl\User\Query\UserView;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\AccessControl\Domain\AccessControl\User\UserRepository;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Adapter\Http\Error\SafeHttpDiagnostics;
use Fight\Common\Adapter\Http\Error\SafeHttpErrorPolicy;
use Fight\Common\Adapter\Http\Psr17\JSendResponseFactory;
use Fight\Common\Adapter\Messaging\Query\Routing\InMemoryQueryRouter;
use Fight\Common\Adapter\Messaging\Query\RoutingQueryBus;
use Fight\Common\Adapter\Middleware\Psr15\SafeJSendErrorMiddleware;
use Fight\Common\Application\Error\Exception\ResourceNotFound;
use Fight\Common\Application\Messaging\Query\QueryHandler;
use Fight\Common\Domain\Exception\LookupException;
use Fight\Common\Domain\Messaging\Query\Query;
use Fight\Common\Domain\Messaging\Query\QueryMessage;
use Fight\Common\Domain\Type\Arrayable;
use Fight\Test\AccessControl\Domain\AccessControl\User\UserFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ReflectionClass;
use RuntimeException;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use stdClass;
use Throwable;
use TypeError;
use UnexpectedValueException;

#[CoversClass(GetUserByIdHandler::class)]
#[CoversClass(GetRoleByIdHandler::class)]
#[CoversClass(GetPermissionByIdHandler::class)]
#[CoversClass(GetAgentByIdHandler::class)]
#[CoversClass(GetAgentProfileHandler::class)]
#[CoversClass(GetFeatureByIdHandler::class)]
final class RequiredResourceQueryTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function families(): iterable
    {
        foreach (['user', 'role', 'permission', 'agent', 'profile', 'feature'] as $family) {
            yield $family => [$family];
        }
    }

    #[DataProvider('families')]
    public function test_wrong_identity_is_an_internal_fault_before_disclosing_any_view(string $family): void
    {
        [$query, , $repositoryType, $handlerType] = $this->fixture($family);
        [, $unrelated] = $this->fixture($family);
        $repository = $this->createMock($repositoryType);
        $repository->expects(self::exactly(2))->method('getById')->willReturn($unrelated);
        $permissions = $this->createMock(PermissionRepository::class);
        $permissions->expects(self::never())->method('getByIds');
        $permissions->expects(self::never())->method('getById');
        $bus = $this->bus(new $handlerType($repository, $permissions));

        try {
            $bus->fetch($query);
            self::fail('A different identity must not be disclosed.');
        } catch (UnexpectedValueException $unexpectedValueException) {
            self::assertSame(500, new SafeHttpErrorPolicy()->status($unexpectedValueException));
        }

        $response = $this->response($bus, $query);
        self::assertSame(500, $response->getStatusCode());
        self::assertStringNotContainsString($unrelated->getId()->toString(), (string) $response->getBody());
    }

    #[DataProvider('families')]
    public function test_fetch_and_serialized_dispatch_return_exact_non_null_views_read_only(string $family): void
    {
        [$query, $aggregate, $repositoryType, $handlerType, $viewType] = $this->fixture($family);
        $repository = $this->createMock($repositoryType);
        foreach (new ReflectionClass($repositoryType)->getMethods() as $method) {
            if ($method->getName() !== 'getById') {
                $repository->expects(self::never())->method($method->getName());
            }
        }

        $repository->expects(self::exactly(2))->method('getById')->with($aggregate->getId())->willReturn($aggregate);
        $permissions = $this->createMock(PermissionRepository::class);
        $permissions->expects($family === 'agent' ? self::exactly(2) : self::never())
            ->method('getByIds')->willReturn([]);
        $permissions->expects($family === 'feature' ? self::exactly(2) : self::never())
            ->method('getById')->willReturn(null);
        $bus = $this->bus(new $handlerType($repository, $permissions));
        $before = serialize($aggregate);
        $decoded = $query::fromArray($query->toArray());
        foreach ([$bus->fetch($query), $bus->dispatch(QueryMessage::create($decoded))] as $view) {
            self::assertInstanceOf($viewType, $view);
            self::assertInstanceOf(Arrayable::class, $view);
            $key = $family === 'profile' ? 'agent_id' : $family.'_id';
            self::assertSame($aggregate->getId()->toString(), $view->toArray()[$key]);
            self::assertStringNotContainsString('PRIVATE', serialize($view));
        }

        self::assertSame($before, serialize($aggregate));
    }

    #[DataProvider('families')]
    public function test_intentional_absence_is_safe_404_through_real_routing_and_middleware(string $family): void
    {
        [$query, , $repositoryType, $handlerType] = $this->fixture($family);
        $repository = $this->createMock($repositoryType);
        $repository->expects(self::exactly(2))->method('getById')->willReturn(null);
        $bus = $this->bus(new $handlerType($repository, $this->createStub(PermissionRepository::class)));
        try {
            $bus->fetch($query);
            self::fail('Required resource absence must throw.');
        } catch (ResourceNotFound $resourceNotFound) {
            self::assertSame(404, new SafeHttpErrorPolicy()->status($resourceNotFound));
        }

        $response = $this->response($bus, $query);
        self::assertSame(404, $response->getStatusCode());
        self::assertSame(
            ['status' => 'fail', 'data' => ['message' => 'Resource not found.']],
            json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)
        );
    }

    #[DataProvider('families')]
    public function test_operational_fault_retains_identity_and_safe_server_intent(string $family): void
    {
        [$query, , $repositoryType, $handlerType] = $this->fixture($family);
        $failure = new RuntimeException('PRIVATE resource not found password secret', 404);
        $repository = $this->createMock($repositoryType);
        $repository->expects(self::exactly(2))->method('getById')->willThrowException($failure);
        $bus = $this->bus(new $handlerType($repository, $this->createStub(PermissionRepository::class)));
        try {
            $bus->fetch($query);
            self::fail('Storage failure must propagate.');
        } catch (RuntimeException $runtimeException) {
            self::assertSame($failure, $runtimeException);
        }

        $response = $this->response($bus, $query);
        self::assertSame(500, $response->getStatusCode());
        self::assertStringNotContainsString('PRIVATE', (string) $response->getBody());
        self::assertStringContainsString('Internal server error.', (string) $response->getBody());
    }

    #[DataProvider('families')]
    public function test_native_repository_contract_fault_is_not_absence(string $family): void
    {
        [$query, , $repositoryType, $handlerType] = $this->fixture($family);
        $repository = $this->createMock($repositoryType);
        $repository->expects(self::exactly(2))->method('getById')->willReturnCallback(
            static fn(): object => new stdClass()
        );
        $bus = $this->bus(new $handlerType($repository, $this->createStub(PermissionRepository::class)));
        try {
            $bus->fetch($query);
            self::fail('A native return violation must not become absence.');
        } catch (TypeError $typeError) {
            self::assertSame(500, new SafeHttpErrorPolicy()->status($typeError));
        }

        self::assertSame(500, $this->response($bus, $query)->getStatusCode());
    }

    public function test_feature_binding_missing_is_data_but_wrong_binding_and_storage_faults_are_server_errors(): void
    {
        [$query, $feature] = $this->fixture('feature');
        $repository = $this->createMock(FeatureRepository::class);
        $repository->expects(self::exactly(4))->method('getById')->willReturn($feature);
        $permissions = $this->createMock(PermissionRepository::class);
        $bound = Permission::define(
            $feature->getPermissionId(),
            PermissionName::fromString('READ_CASES'),
            new DateTimeImmutable('2026-01-01')
        );
        [, $wrong] = $this->fixture('permission');
        $failure = new RuntimeException('PRIVATE missing binding', 404);
        $calls = 0;
        $permissions->expects(self::exactly(4))->method('getById')->with($feature->getPermissionId())
            ->willReturnCallback(static function () use (&$calls, $bound, $wrong, $failure): ?Permission {
                return match (++$calls) {
                    1 => null,
                    2 => $bound,
                    3 => $wrong,
                    default => throw $failure,
                };
            });
        $bus = $this->bus(new GetFeatureByIdHandler($repository, $permissions));
        $missing = $bus->fetch($query);
        self::assertTrue($missing->isBindingMissing());
        self::assertSame($feature->getPermissionId(), $missing->getPermissionId());
        self::assertNull($missing->getPermissionName());
        self::assertFalse($bus->fetch($query)->isBindingMissing());
        for ($i = 0; $i < 2; ++$i) {
            self::assertSame(500, $this->response($bus, $query)->getStatusCode());
        }

        self::assertSame(500, new SafeHttpErrorPolicy()->status(new FeatureStateException('PRIVATE')));
    }

    public function test_agent_snapshot_faults_are_internal_not_missing_and_cannot_expose_credentials(): void
    {
        [, $agent] = $this->fixture('agent');
        [, $permission] = $this->fixture('permission');
        [, $unrelated] = $this->fixture('permission');
        $agent = $agent->grantPermission($permission->getId(), new DateTimeImmutable('2026-01-02'));
        $query = new GetAgentById($agent->getId());
        foreach ([[], [$unrelated], [$permission, $permission], [new stdClass()]] as $invalid) {
            $repository = $this->createMock(AgentRepository::class);
            $repository->expects(self::once())->method('getById')->willReturn($agent);
            $permissions = $this->createMock(PermissionRepository::class);
            $permissions->expects(self::once())->method('getByIds')->with([$permission->getId()])->willReturn($invalid);
            $response = $this->response($this->bus(new GetAgentByIdHandler($repository, $permissions)), $query);
            self::assertSame(500, $response->getStatusCode());
            self::assertStringNotContainsString('PRIVATE', (string) $response->getBody());
        }

        self::assertSame(500, new SafeHttpErrorPolicy()->status(new AgentReadException('PRIVATE')));
    }

    public function test_revoked_profile_is_concealed_but_wrong_revoked_identity_remains_internal(): void
    {
        [$query, $agent] = $this->fixture('profile');
        [, $unrelated] = $this->fixture('profile');
        $repository = $this->createMock(AgentRepository::class);
        $repository->expects(self::exactly(2))->method('getById')->willReturn(
            $agent->revoke(new DateTimeImmutable('2026-01-02')),
            $unrelated->revoke(new DateTimeImmutable('2026-01-02'))
        );
        $bus = $this->bus(new GetAgentProfileHandler($repository));
        self::assertSame(404, $this->response($bus, $query)->getStatusCode());
        self::assertSame(500, $this->response($bus, $query)->getStatusCode());
    }

    public function test_unconfigured_routing_lookup_is_not_intentional_resource_absence(): void
    {
        [$query] = $this->fixture('user');
        $bus = new RoutingQueryBus(new InMemoryQueryRouter());
        $response = $this->response($bus, $query);
        self::assertSame(500, $response->getStatusCode());
        self::assertSame(500, new SafeHttpErrorPolicy()->status(new LookupException('PRIVATE', 404)));
    }

    private function bus(QueryHandler $handler): RoutingQueryBus
    {
        $router = new InMemoryQueryRouter();
        $router->registerHandler($handler::queryRegistration(), $handler);

        return new RoutingQueryBus($router);
    }

    private function response(RoutingQueryBus $bus, Query $query): ResponseInterface
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->willReturnCallback(
            static function () use ($bus, $query): ResponseInterface {
                $bus->dispatch(QueryMessage::create($query));

                return new ResponseFactory()->createResponse(204);
            }
        );
        $diagnostics = $this->createMock(SafeHttpDiagnostics::class);
        $diagnostics->expects(self::once())->method('report')->with(self::isInstanceOf(Throwable::class));
        $middleware = new SafeJSendErrorMiddleware(
            new JSendResponseFactory(new ResponseFactory(), new StreamFactory()),
            new SafeHttpErrorPolicy(),
            $diagnostics
        );

        return $middleware->process(new ServerRequestFactory()->createServerRequest('GET', '/resource'), $handler);
    }

    /** @return array{Query, object, class-string, class-string<QueryHandler>, class-string<Arrayable>} */
    private function fixture(string $family): array
    {
        $now = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $permission = Permission::define(PermissionId::generate(), PermissionName::fromString('READ_CASES'), $now);
        $agent = Agent::provision(
            AgentId::generate(),
            AgentName::fromString('Worker'),
            AgentCredentialId::generate(),
            'PRIVATE encrypted credential',
            $now
        );
        $user = UserFixture::withIdAndAuthenticationVersion(
            UserId::generate(),
            'safe@example.test',
            UserState::ACTIVE,
            1
        );
        $role = Role::define(RoleId::generate(), RoleName::fromString('ROLE_READER'), [], $now);
        $feature = Feature::define(FeatureId::generate(), FeatureName::fromString('dashboard'), $permission->getId());

        return match ($family) {
            'user' => [new GetUserById($user->getId()), $user, UserRepository::class,
                GetUserByIdHandler::class, UserView::class],
            'role' => [new GetRoleById($role->getId()), $role, RoleRepository::class,
                GetRoleByIdHandler::class, RoleView::class],
            'permission' => [new GetPermissionById($permission->getId()), $permission, PermissionRepository::class,
                GetPermissionByIdHandler::class, PermissionView::class],
            'agent' => [new GetAgentById($agent->getId()), $agent, AgentRepository::class,
                GetAgentByIdHandler::class, AgentView::class],
            'profile' => [new GetAgentProfile($agent->getId()), $agent, AgentRepository::class,
                GetAgentProfileHandler::class, AgentProfileView::class],
            'feature' => [new GetFeatureById($feature->getId()), $feature, FeatureRepository::class,
                GetFeatureByIdHandler::class, FeatureView::class],
            default => throw new UnexpectedValueException('Unknown test family.'),
        };
    }
}
