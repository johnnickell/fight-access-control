<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\QueryHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\Agent\QueryHandler\GetAgentByIdHandler;
use Fight\AccessControl\Application\AccessControl\Agent\QueryHandler\ListAgentsHandler;
use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentName;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentRepository;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentReadException;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\AgentView;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\GetAgentById;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\ListAgents;
use Fight\AccessControl\Domain\AccessControl\Authorization\PrincipalPermission;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionRepository;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Query\QueryMessage;
use Fight\Common\Domain\Repository\Pagination;
use Fight\Common\Domain\Repository\ResultSet;
use Fight\Common\Domain\Type\Arrayable;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Repository\InMemoryAgentRepository;
use Fight\Test\AccessControl\Application\AccessControl\Permission\Repository\InMemoryPermissionRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

#[CoversClass(GetAgentByIdHandler::class)]
#[CoversClass(ListAgentsHandler::class)]
#[CoversClass(GetAgentById::class)]
#[CoversClass(ListAgents::class)]
#[CoversClass(AgentView::class)]
#[CoversClass(PrincipalPermission::class)]
final class AgentQueryHandlerTest extends TestCase
{
    public function test_get_returns_the_exact_safe_agent_shape_with_resolved_permission_name(): void
    {
        $permission = Permission::define(
            PermissionId::fromString('018f0000-0000-7000-8000-000000000001'),
            PermissionName::fromString('CONTENT_PUBLISH'),
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        );
        $agent = Agent::provision(
            AgentId::fromString('018f0000-0000-7000-8000-000000000002'),
            AgentName::fromString('Production deployment'),
            AgentCredentialId::fromString('018f0000-0000-7000-8000-000000000003'),
            'consumer-encrypted-secret-that-must-not-appear',
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        )->grantPermission(
            $permission->getId(),
            new DateTimeImmutable('2026-01-02T00:00:00+00:00')
        );
        $agents = new InMemoryAgentRepository();
        $agents->add($agent);

        $permissions = new InMemoryPermissionRepository();
        $permissions->add($permission);

        $handler = new GetAgentByIdHandler($agents, $permissions);

        self::assertSame(GetAgentById::class, GetAgentByIdHandler::queryRegistration());
        $view = $handler->handle(QueryMessage::create(new GetAgentById($agent->getId())));

        self::assertInstanceOf(AgentView::class, $view);
        self::assertInstanceOf(Arrayable::class, $view);
        self::assertSame(
            [
                'agent_id' => '018f0000-0000-7000-8000-000000000002',
                'name' => 'Production deployment',
                'state' => 'active',
                'credential_id' => '018f0000-0000-7000-8000-000000000003',
                'credential_revision' => 0,
                'permission_assignment_revision' => 2,
                'permissions' => [[
                    'permission_id' => '018f0000-0000-7000-8000-000000000001',
                    'name' => 'CONTENT_PUBLISH',
                ]],
            ],
            $view->toArray()
        );
        self::assertStringNotContainsString('consumer-encrypted-secret', serialize($view));
    }

    public function test_get_returns_null_for_unknown_agent_and_supports_empty_assignments(): void
    {
        $agents = new InMemoryAgentRepository();
        $agent = $this->agent('Production deployment');
        $agents->add($agent);
        $handler = new GetAgentByIdHandler($agents, new InMemoryPermissionRepository());

        self::assertNull($handler->handle(QueryMessage::create(new GetAgentById(AgentId::generate()))));
        $view = $handler->handle(QueryMessage::create(new GetAgentById($agent->getId())));

        self::assertInstanceOf(AgentView::class, $view);
        self::assertSame([], $view->getPermissions());
        self::assertSame([], $view->toArray()['permissions']);
        self::assertSame(1, $view->getPermissionAssignmentRevision());
    }

    public function test_list_preserves_page_metadata_order_and_typed_safe_views(): void
    {
        $agents = new InMemoryAgentRepository();
        $first = $this->agent('First deployment');
        $second = $this->agent('Second deployment');
        $agents->add($first);
        $agents->add($second);

        $handler = new ListAgentsHandler($agents, new InMemoryPermissionRepository());

        self::assertSame(ListAgents::class, ListAgentsHandler::queryRegistration());
        $result = $handler->handle(QueryMessage::create(new ListAgents(new Pagination(2, 1))));

        self::assertInstanceOf(ResultSet::class, $result);
        self::assertSame(2, $result->page());
        self::assertSame(1, $result->perPage());
        self::assertSame(2, $result->totalRecords());
        self::assertSame(2, $result->totalPages());
        self::assertCount(1, $result->records());
        $view = $result->records()->get(0);
        self::assertInstanceOf(AgentView::class, $view);
        self::assertSame($second->getId(), $view->getAgentId());
        self::assertSame($second->getName(), $view->getName());
        self::assertSame($second->getState(), $view->getState());
        self::assertSame($second->getCredentialId(), $view->getCredentialId());
        self::assertSame($second->getCredentialRevision(), $view->getCredentialRevision());
        self::assertSame($second->getPermissionAssignmentRevision(), $view->getPermissionAssignmentRevision());
        self::assertInstanceOf(Arrayable::class, $view);
    }

    public function test_multiple_assignments_resolve_in_agent_order_for_get_and_list(): void
    {
        $firstPermission = $this->permission('CONTENT_PUBLISH');
        $secondPermission = $this->permission('CONTENT_REVIEW');
        $agent = $this->agent('Production deployment')->grantPermission(
            $secondPermission->getId(),
            new DateTimeImmutable('2026-01-02T00:00:00+00:00')
        )->grantPermission(
            $firstPermission->getId(),
            new DateTimeImmutable('2026-01-03T00:00:00+00:00')
        );
        $secondAgent = $this->agent('Review deployment')->grantPermission(
            $firstPermission->getId(),
            new DateTimeImmutable('2026-01-04T00:00:00+00:00')
        );
        $agents = new InMemoryAgentRepository();
        $agents->add($agent);
        $agents->add($secondAgent);

        $permissions = new InMemoryPermissionRepository();
        $permissions->add($firstPermission);
        $permissions->add($secondPermission);

        $getView = new GetAgentByIdHandler($agents, $permissions)->handle(
            QueryMessage::create(new GetAgentById($agent->getId()))
        );
        $listResult = new ListAgentsHandler($agents, $permissions)->handle(
            QueryMessage::create(new ListAgents(new Pagination()))
        );

        self::assertInstanceOf(AgentView::class, $getView);
        $expected = [
            ['permission_id' => $secondPermission->getId()->toString(), 'name' => 'CONTENT_REVIEW'],
            ['permission_id' => $firstPermission->getId()->toString(), 'name' => 'CONTENT_PUBLISH'],
        ];
        self::assertSame($expected, $getView->toArray()['permissions']);
        self::assertSame($expected, $listResult->records()->get(0)->toArray()['permissions']);
        self::assertSame(
            [['permission_id' => $firstPermission->getId()->toString(), 'name' => 'CONTENT_PUBLISH']],
            $listResult->records()->get(1)->toArray()['permissions']
        );
    }

    public function test_list_resolves_page_permissions_once_and_preserves_each_agent_snapshot_order(): void
    {
        $publishPermission = $this->permission('CONTENT_PUBLISH');
        $reviewPermission = $this->permission('CONTENT_REVIEW');
        $firstAgent = $this->agent('Production deployment')->grantPermission(
            $reviewPermission->getId(),
            new DateTimeImmutable('2026-01-02T00:00:00+00:00')
        )->grantPermission(
            $publishPermission->getId(),
            new DateTimeImmutable('2026-01-03T00:00:00+00:00')
        );
        $secondAgent = $this->agent('Review deployment')->grantPermission(
            $publishPermission->getId(),
            new DateTimeImmutable('2026-01-04T00:00:00+00:00')
        )->grantPermission(
            $reviewPermission->getId(),
            new DateTimeImmutable('2026-01-05T00:00:00+00:00')
        );
        $agents = new InMemoryAgentRepository();
        $agents->add($firstAgent);
        $agents->add($secondAgent);

        $permissions = $this->createMock(PermissionRepository::class);
        $permissions->expects(self::once())
            ->method('getByIds')
            ->with(self::callback(function (array $ids) use ($reviewPermission, $publishPermission): bool {
                self::assertSame([$reviewPermission->getId(), $publishPermission->getId()], $ids);

                return true;
            }))
            ->willReturn([$publishPermission, $reviewPermission]);

        $result = new ListAgentsHandler($agents, $permissions)->handle(
            QueryMessage::create(new ListAgents(new Pagination()))
        );

        self::assertSame(
            [
                ['permission_id' => $reviewPermission->getId()->toString(), 'name' => 'CONTENT_REVIEW'],
                ['permission_id' => $publishPermission->getId()->toString(), 'name' => 'CONTENT_PUBLISH'],
            ],
            $result->records()->get(0)->toArray()['permissions']
        );
        self::assertSame(
            [
                ['permission_id' => $publishPermission->getId()->toString(), 'name' => 'CONTENT_PUBLISH'],
                ['permission_id' => $reviewPermission->getId()->toString(), 'name' => 'CONTENT_REVIEW'],
            ],
            $result->records()->get(1)->toArray()['permissions']
        );
    }

    public function test_get_and_list_fail_closed_for_invalid_bulk_permission_results(): void
    {
        foreach (['missing', 'duplicate', 'unexpected', 'mismatched'] as $case) {
            foreach (['get', 'list'] as $queryType) {
                $firstPermission = $this->permission('CONTENT_PUBLISH');
                $secondPermission = $this->permission('CONTENT_REVIEW');
                $unexpectedPermission = $this->permission('CONTENT_DELETE');
                $agent = $this->agent('Production deployment')->grantPermission(
                    $firstPermission->getId(),
                    new DateTimeImmutable('2026-01-02T00:00:00+00:00')
                )->grantPermission(
                    $secondPermission->getId(),
                    new DateTimeImmutable('2026-01-03T00:00:00+00:00')
                );
                $agents = new InMemoryAgentRepository();
                $agents->add($agent);
                $permissions = new InMemoryPermissionRepository(
                    getByIdsResult: static fn(): array => match ($case) {
                        'missing' => [$firstPermission],
                        'duplicate' => [$firstPermission, $firstPermission],
                        'unexpected' => [$firstPermission, $secondPermission, $unexpectedPermission],
                        default => [$firstPermission, $unexpectedPermission],
                    }
                );

                try {
                    if ($queryType === 'get') {
                        new GetAgentByIdHandler($agents, $permissions)->handle(
                            QueryMessage::create(new GetAgentById($agent->getId()))
                        );
                    } else {
                        new ListAgentsHandler($agents, $permissions)->handle(
                            QueryMessage::create(new ListAgents(new Pagination()))
                        );
                    }

                    self::fail(sprintf('Invalid %s bulk result was exposed by %s.', $case, $queryType));
                } catch (AgentReadException) {
                    self::assertSame($agent, $agents->getById($agent->getId()));
                }
            }
        }
    }

    public function test_queries_round_trip_and_reject_each_missing_key(): void
    {
        $agentId = AgentId::generate();
        $get = new GetAgentById($agentId);
        $list = new ListAgents(new Pagination(2, 10, ['name' => Pagination::DESC]));

        self::assertEquals($get, GetAgentById::fromArray($get->toArray()));
        self::assertSame($agentId, $get->getAgentId());
        self::assertEquals($list, ListAgents::fromArray($list->toArray()));
        self::assertSame(2, $list->getPagination()->page());
        self::assertSame(10, $list->getPagination()->perPage());
        self::assertSame(['name' => Pagination::DESC], $list->getPagination()->orderings());

        $cases = [
            [GetAgentById::class, ['agent_id'], $get->toArray()],
            [ListAgents::class, ['page', 'per_page', 'orderings'], $list->toArray()],
        ];
        foreach ($cases as [$type, $requiredKeys, $data]) {
            foreach ($requiredKeys as $key) {
                $incomplete = $data;
                unset($incomplete[$key]);

                try {
                    $type::fromArray($incomplete);
                    self::fail(sprintf('Missing Agent query key "%s" was accepted.', $key));
                } catch (DomainException) {
                    self::addToAssertionCount(1);
                }
            }
        }
    }

    public function test_view_reflection_and_serialization_expose_only_the_exact_allowlist(): void
    {
        $permission = $this->permission('CONTENT_PUBLISH');
        $agent = $this->agent('Production deployment')->grantPermission(
            $permission->getId(),
            new DateTimeImmutable('2026-01-02T00:00:00+00:00')
        );
        $view = AgentView::fromAgent(
            $agent,
            [new PrincipalPermission($permission->getId(), $permission->getName())]
        );
        $agentProperties = array_map(
            static fn(ReflectionProperty $property): string => $property->getName(),
            new ReflectionClass(AgentView::class)->getProperties()
        );
        $permissionProperties = array_map(
            static fn(ReflectionProperty $property): string => $property->getName(),
            new ReflectionClass(PrincipalPermission::class)->getProperties()
        );
        sort($agentProperties);
        sort($permissionProperties);

        self::assertSame(
            [
                'agentId',
                'credentialId',
                'credentialRevision',
                'name',
                'permissionAssignmentRevision',
                'permissions',
                'state',
            ],
            $agentProperties
        );
        self::assertSame(['name', 'permissionId'], $permissionProperties);
        self::assertSame($permission->getId(), $view->getPermissions()[0]->getPermissionId());
        self::assertSame($permission->getName(), $view->getPermissions()[0]->getName());
        self::assertSame(
            ['permission_id' => $permission->getId()->toString(), 'name' => 'CONTENT_PUBLISH'],
            $view->getPermissions()[0]->toArray()
        );
        $serialized = serialize($view);
        foreach (['encrypted', 'secret', 'createdAt', 'updatedAt', 'tier', 'managed', 'allowed'] as $disallowed) {
            self::assertStringNotContainsString($disallowed, $serialized);
        }
    }

    public function test_the_obsolete_agent_permission_view_type_is_not_available(): void
    {
        self::assertFalse(class_exists(
            'Fight\\AccessControl\\Domain\\AccessControl\\Agent\\Query\\AgentPermissionView'
        ));
    }

    public function test_query_handlers_depend_only_on_read_repositories(): void
    {
        foreach ([GetAgentByIdHandler::class, ListAgentsHandler::class] as $handlerType) {
            $constructor = new ReflectionClass($handlerType)->getConstructor();
            self::assertNotNull($constructor);
            self::assertSame(
                [
                    AgentRepository::class,
                    PermissionRepository::class,
                ],
                array_map(
                    static fn($parameter): string => (string) $parameter->getType(),
                    $constructor->getParameters()
                )
            );
        }
    }

    private function agent(string $name): Agent
    {
        return Agent::provision(
            AgentId::generate(),
            AgentName::fromString($name),
            AgentCredentialId::generate(),
            'consumer-encrypted-secret-that-must-not-appear',
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        );
    }

    private function permission(string $name): Permission
    {
        return Permission::define(
            PermissionId::generate(),
            PermissionName::fromString($name),
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        );
    }
}
