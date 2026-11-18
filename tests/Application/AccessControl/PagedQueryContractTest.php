<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\Agent\QueryHandler\ListAgentsHandler;
use Fight\AccessControl\Application\AccessControl\Feature\QueryHandler\ListFeaturesHandler;
use Fight\AccessControl\Application\AccessControl\Permission\QueryHandler\ListPermissionsHandler;
use Fight\AccessControl\Application\AccessControl\RefreshSession\QueryHandler\ListActiveSessionsHandler;
use Fight\AccessControl\Application\AccessControl\Role\QueryHandler\ListRolesHandler;
use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Application\AccessControl\User\QueryHandler\ListUsersHandler;
use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentName;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentRepository;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\ListAgents;
use Fight\AccessControl\Domain\AccessControl\Feature\Feature;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureId;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureName;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureRepository;
use Fight\AccessControl\Domain\AccessControl\Feature\Query\ListFeatures;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionRepository;
use Fight\AccessControl\Domain\AccessControl\Permission\Query\ListPermissions;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\Exception\SessionAdministrationAuthorizationException;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\Query\ListActiveSessions;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshCredential;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSession;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionId;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionRepository;
use Fight\AccessControl\Domain\AccessControl\Role\Query\ListRoles;
use Fight\AccessControl\Domain\AccessControl\Role\Role;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\Role\RoleName;
use Fight\AccessControl\Domain\AccessControl\Role\RoleRepository;
use Fight\AccessControl\Domain\AccessControl\User\Query\ListUsers;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\AccessControl\Domain\AccessControl\User\UserRepository;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Adapter\Http\Error\SafeHttpErrorPolicy;
use Fight\Common\Adapter\Messaging\Query\Routing\InMemoryQueryRouter;
use Fight\Common\Adapter\Messaging\Query\RoutingQueryBus;
use Fight\Common\Application\Messaging\Query\QueryHandler;
use Fight\Common\Domain\Collection\ArrayList;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Query\Query;
use Fight\Common\Domain\Messaging\Query\QueryMessage;
use Fight\Common\Domain\Repository\Pagination;
use Fight\Common\Domain\Repository\ResultSet;
use Fight\Test\AccessControl\Application\AccessControl\RefreshSession\Service\FixedSessionAdministrationAuthorization;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Domain\AccessControl\User\UserFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Throwable;

#[CoversClass(ListUsersHandler::class)]
#[CoversClass(ListRolesHandler::class)]
#[CoversClass(ListPermissionsHandler::class)]
#[CoversClass(ListAgentsHandler::class)]
#[CoversClass(ListFeaturesHandler::class)]
#[CoversClass(ListActiveSessionsHandler::class)]
final class PagedQueryContractTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function paths(): iterable
    {
        foreach (['users', 'roles', 'permissions', 'agents', 'features', 'sessions'] as $path) {
            yield $path => [$path];
        }
    }

    /** @return iterable<string, array{string, int, int, int}> */
    public static function invalidPages(): iterable
    {
        foreach (self::paths() as [$path]) {
            foreach ([[2, 2, 0], [1, 3, 0], [0, 2, 0], [1, 0, 0], [1, 2, -1]] as $index => $tuple) {
                yield $path.':'.$index => [$path, ...$tuple];
            }
        }
    }

    #[DataProvider('invalidPages')]
    public function test_legacy_metadata_is_revalidated_and_related_to_the_request(
        string $path,
        int $page,
        int $size,
        int $total
    ): void {
        [$handler, $query] = $this->route($path, new ResultSet($page, $size, $total, new ArrayList()));
        $this->expectException(DomainException::class);
        $this->dispatch($handler, $query);
    }

    #[DataProvider('paths')]
    public function test_mutated_strict_pages_cannot_disclose_excessive_records(string $path): void
    {
        $records = new ArrayList();
        $page = ResultSet::strict(1, 2, 1, $records);
        $records->add($this->record($path));
        $records->add($this->record($path));
        [$handler, $query] = $this->route($path, $page);
        $this->expectException(DomainException::class);
        $this->dispatch($handler, $query);
    }

    #[DataProvider('paths')]
    public function test_legacy_pages_cannot_exceed_requested_size(string $path): void
    {
        $records = new ArrayList();
        for ($index = 0; $index < 3; ++$index) {
            $records->add($this->record($path));
        }

        [$handler, $query] = $this->route($path, new ResultSet(1, 2, 3, $records));
        $this->expectException(DomainException::class);
        $this->dispatch($handler, $query);
    }

    #[DataProvider('paths')]
    public function test_exact_elements_are_enforced_even_for_untyped_lists(string $path): void
    {
        $records = new ArrayList();
        $records->add(new stdClass());
        [$handler, $query] = $this->route($path, ResultSet::strict(1, 2, 1, $records));
        $failure = null;
        try {
            $this->dispatch($handler, $query);
        } catch (Throwable $throwable) {
            $failure = $throwable;
        }

        self::assertInstanceOf(Throwable::class, $failure);
        self::assertSame(500, new SafeHttpErrorPolicy()->present($failure)[0]);
    }

    #[DataProvider('paths')]
    public function test_valid_legacy_empty_partial_and_out_of_range_pages_are_supported(string $path): void
    {
        foreach ([[1, 0, []], [4, 0, []], [4, 3, []], [1, 7, [$this->record($path)]]] as [$number, $total, $rows]) {
            $records = new ArrayList();
            foreach ($rows as $row) {
                $records->add($row);
            }

            [$handler, $query] = $this->route($path, new ResultSet($number, 2, $total, $records), $number);
            $result = $this->dispatch($handler, $query);
            self::assertInstanceOf(ResultSet::class, $result);
            self::assertSame($number, $result->page());
            self::assertSame($total, $result->totalRecords());
            self::assertSame($total === 0 ? 1 : intdiv($total - 1, 2) + 1, $result->totalPages());
            self::assertCount(count($rows), $result);
            self::assertNotSame($records, $result->records());
            $records->add($this->record($path));
            self::assertCount(count($rows), $result);
        }
    }

    #[DataProvider('paths')]
    public function test_operational_failure_remains_internal_through_real_routing_and_safe_errors(string $path): void
    {
        $failure = new RuntimeException('Private provider diagnostic');
        [$handler, $query] = $this->route($path, $failure);
        try {
            $this->dispatch($handler, $query);
            self::fail('An operational failure must not become empty success.');
        } catch (RuntimeException $runtimeException) {
            self::assertSame($failure, $runtimeException);
            [$status, $envelope] = new SafeHttpErrorPolicy()->present($runtimeException);
            self::assertSame(500, $status);
            self::assertSame(['status' => 'error', 'message' => 'Internal server error.'], $envelope->toArray());
        }
    }

    public function test_session_owner_and_usability_are_validated_at_the_admitted_read_time(): void
    {
        $session = $this->session();
        $wrongOwner = RefreshSession::start(
            RefreshSessionId::generate(),
            UserId::generate(),
            RefreshCredential::fromString(str_repeat('b', 64)),
            new DateTimeImmutable('2026-01-01'),
            new DateTimeImmutable('2026-01-03'),
            new DateTimeImmutable('2026-01-04'),
            1,
            false
        );
        foreach ([$wrongOwner, $session->revoke(), $this->session('2026-01-02')] as $invalid) {
            $records = ArrayList::of(RefreshSession::class)->replace([$invalid]);
            [$handler, $query] = $this->route('sessions', ResultSet::strict(1, 2, 1, $records));
            try {
                $this->dispatch($handler, $query);
                self::fail('An unrelated or unusable session must not be disclosed.');
            } catch (DomainException $failure) {
                self::assertSame(500, new SafeHttpErrorPolicy()->present($failure)[0]);
            }
        }
    }

    public function test_cross_user_session_admission_precedes_clock_and_read_through_the_configured_bus(): void
    {
        $target = $this->userId();
        $actor = UserId::generate();
        $session = $this->session();
        $query = new ListActiveSessions($actor, $target, $session->getId(), new Pagination(1, 2));
        $denied = $this->createMock(RefreshSessionRepository::class);
        $denied->expects(self::never())->method('getByUserId');
        $authorization = new FixedSessionAdministrationAuthorization(false);
        $clock = $this->createMock(Clock::class);
        $clock->expects(self::never())->method('now');
        try {
            $this->dispatch(new ListActiveSessionsHandler(
                $denied,
                $clock,
                $authorization
            ), $query);
            self::fail('Cross-user authority must be admitted before reading.');
        } catch (SessionAdministrationAuthorizationException) {
            self::assertSame(1, $authorization->calls());
        }

        $admitted = $this->createMock(RefreshSessionRepository::class);
        $admitted->expects(self::once())->method('getByUserId')
            ->with($target, new DateTimeImmutable('2026-01-02'), $query->getPagination())
            ->willReturn(ResultSet::strict(1, 2, 1, ArrayList::of(RefreshSession::class)->replace([$session])));
        $authorization = new FixedSessionAdministrationAuthorization(true);
        $result = $this->dispatch(new ListActiveSessionsHandler(
            $admitted,
            new FixedClock(new DateTimeImmutable('2026-01-02')),
            $authorization
        ), $query);
        self::assertSame(1, $authorization->calls());
        self::assertSame($actor, $authorization->lastActorId());
        self::assertSame($target, $authorization->lastUserId());
        self::assertTrue($result->records()->get(0)->isCurrent());
        self::assertEquals($target, $result->records()->get(0)->getUserId());
    }

    public function test_feature_mapping_retains_missing_binding_and_rejects_a_wrong_related_identity(): void
    {
        $feature = Feature::define(FeatureId::generate(), FeatureName::fromString('page'), PermissionId::generate());
        $page = ResultSet::strict(1, 2, 1, ArrayList::of(Feature::class)->replace([$feature]));
        $repository = $this->createMock(FeatureRepository::class);
        $repository->expects(self::exactly(2))->method('getAll')->willReturn($page);
        $permissions = $this->createMock(PermissionRepository::class);
        $permissions->expects(self::exactly(2))->method('getById')->with($feature->getPermissionId())
            ->willReturnOnConsecutiveCalls(
                null,
                Permission::define(
                    PermissionId::generate(),
                    PermissionName::fromString('UNRELATED'),
                    new DateTimeImmutable()
                )
            );
        $handler = new ListFeaturesHandler($repository, $permissions);
        $query = new ListFeatures(new Pagination(1, 2));
        $result = $this->dispatch($handler, $query);
        self::assertTrue($result->records()->get(0)->isBindingMissing());
        self::assertSame($feature->getPermissionId(), $result->records()->get(0)->getPermissionId());
        self::assertSame($feature->getRevision(), $result->records()->get(0)->getRevision());
        $this->expectException(DomainException::class);
        $this->dispatch($handler, $query);
    }

    public function test_feature_dependency_cannot_change_the_captured_page_membership(): void
    {
        $feature = Feature::define(FeatureId::generate(), FeatureName::fromString('page'), PermissionId::generate());
        $records = ArrayList::of(Feature::class)->replace([$feature]);
        $repository = $this->createMock(FeatureRepository::class);
        $repository->expects(self::once())->method('getAll')->willReturn(ResultSet::strict(1, 2, 1, $records));
        $permissions = $this->createMock(PermissionRepository::class);
        $permissions->expects(self::once())->method('getById')->willReturnCallback(
            static function () use ($records, $feature): null {
                $records->add($feature);
                $records->add($feature);

                return null;
            }
        );
        $result = $this->dispatch(
            new ListFeaturesHandler($repository, $permissions),
            new ListFeatures(new Pagination(1, 2))
        );
        self::assertCount(1, $result);
        self::assertCount(3, $records);
        self::assertSame(1, $result->totalRecords());
    }

    public function test_the_supported_common_factory_owns_universal_invariants_and_integer_arithmetic(): void
    {
        foreach ([[0, 2, 0], [1, 0, 0], [1, 2, -1]] as [$page, $size, $total]) {
            try {
                ResultSet::strict($page, $size, $total, new ArrayList());
                self::fail('Common must reject invalid local bounds.');
            } catch (DomainException) {
                self::addToAssertionCount(1);
            }
        }

        $records = ArrayList::of('int')->replace([1, 2]);
        foreach ([[1, 1, 2], [1, 2, 1]] as [$page, $size, $total]) {
            try {
                ResultSet::strict($page, $size, $total, $records);
                self::fail('Common must reject excessive local records.');
            } catch (DomainException) {
                self::addToAssertionCount(1);
            }
        }

        $strict = ResultSet::strict(1, 2, PHP_INT_MAX, $records);
        self::assertSame(intdiv(PHP_INT_MAX - 1, 2) + 1, $strict->totalPages());
        self::assertSame($records, $strict->records());
        $records->add(3);
        self::assertCount(3, $strict);
        self::assertSame(0, new ResultSet(0, 0, -1, $records)->page());
    }

    /**
     * @param ResultSet<mixed>|RuntimeException $result
     * @return array{QueryHandler, Query}
     */
    private function route(string $path, ResultSet|RuntimeException $result, int $number = 1): array
    {
        $pagination = new Pagination($number, 2);
        $repositoryClass = match ($path) {
            'users' => UserRepository::class,
            'roles' => RoleRepository::class,
            'permissions' => PermissionRepository::class,
            'agents' => AgentRepository::class,
            'features' => FeatureRepository::class,
            default => RefreshSessionRepository::class
        };
        $repository = $this->createMock($repositoryClass);
        $call = $repository->expects(self::once())->method($path === 'sessions' ? 'getByUserId' : 'getAll');
        if ($path === 'sessions') {
            $call->with($this->userId(), new DateTimeImmutable('2026-01-02'), $pagination);
        } else {
            $call->with($pagination);
        }

        if ($result instanceof RuntimeException) {
            $call->willThrowException($result);
        } else {
            $call->willReturn($result);
        }

        $permissions = $this->createStub(PermissionRepository::class);
        $permissions->method('getByIds')->willReturn([]);
        $permissions->method('getById')->willReturn(null);
        $handler = match ($path) {
            'users' => new ListUsersHandler($repository),
            'roles' => new ListRolesHandler($repository),
            'permissions' => new ListPermissionsHandler($repository),
            'agents' => new ListAgentsHandler($repository, $permissions),
            'features' => new ListFeaturesHandler($repository, $permissions),
            default => new ListActiveSessionsHandler(
                $repository,
                new FixedClock(new DateTimeImmutable('2026-01-02')),
                new FixedSessionAdministrationAuthorization(false)
            )
        };
        $query = match ($path) {
            'users' => new ListUsers($pagination),
            'roles' => new ListRoles($pagination),
            'permissions' => new ListPermissions($pagination),
            'agents' => new ListAgents($pagination),
            'features' => new ListFeatures($pagination),
            default => new ListActiveSessions(
                $this->userId(),
                $this->userId(),
                RefreshSessionId::generate(),
                $pagination
            )
        };

        return [$handler, $query];
    }

    private function dispatch(QueryHandler $handler, Query $query): mixed
    {
        $router = new InMemoryQueryRouter();
        $router->registerHandler($handler::queryRegistration(), $handler);

        return new RoutingQueryBus($router)->dispatch(QueryMessage::create($query));
    }

    private function record(string $path): object
    {
        $at = new DateTimeImmutable('2026-01-01');

        return match ($path) {
            'users' => UserFixture::withState('page@example.test', UserState::ACTIVE),
            'roles' => Role::define(RoleId::generate(), RoleName::fromString('ROLE_PAGE'), [], $at),
            'permissions' => Permission::define(PermissionId::generate(), PermissionName::fromString('PAGE_READ'), $at),
            'agents' => Agent::provision(
                AgentId::generate(),
                AgentName::fromString('Page Agent'),
                AgentCredentialId::generate(),
                'encrypted',
                $at
            ),
            'features' => Feature::define(
                FeatureId::generate(),
                FeatureName::fromString('page'), PermissionId::generate()
            ),
            default => $this->session()
        };
    }

    private function userId(): UserId
    {
        return UserId::fromString('018f0000-0000-7000-8000-000000000001');
    }

    private function session(string $idleExpiry = '2026-01-03'): RefreshSession
    {
        return RefreshSession::start(
            RefreshSessionId::generate(),
            $this->userId(),
            RefreshCredential::fromString(str_repeat('a', 64)),
            new DateTimeImmutable('2026-01-01'),
            new DateTimeImmutable($idleExpiry),
            new DateTimeImmutable('2026-01-04'),
            1,
            false
        );
    }
}
