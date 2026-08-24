<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Role\QueryHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\Role\QueryHandler\ListRolesHandler;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Role\Query\ListRoles;
use Fight\AccessControl\Domain\AccessControl\Role\Query\RoleView;
use Fight\AccessControl\Domain\AccessControl\Role\Role;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\Role\RoleName;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Query\QueryMessage;
use Fight\Common\Domain\Repository\Pagination;
use Fight\Common\Domain\Repository\ResultSet;
use Fight\Common\Domain\Type\Arrayable;
use Fight\Test\AccessControl\Application\AccessControl\Role\Repository\InMemoryRoleRepository;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryAuthorizationReferenceState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

#[CoversClass(ListRolesHandler::class)]
#[CoversClass(ListRoles::class)]
#[CoversClass(RoleView::class)]
final class ListRolesHandlerTest extends TestCase
{
    public function test_that_it_returns_a_typed_page_of_safe_role_views(): void
    {
        $permissionId = PermissionId::fromString('018f0000-0000-7000-8000-000000000001');
        $authorizationReferences = new InMemoryAuthorizationReferenceState();
        $authorizationReferences->addPermission($permissionId);

        $roles = new InMemoryRoleRepository(authorizationReferences: $authorizationReferences);
        $custom = Role::define(
            RoleId::fromString('018f0000-0000-7000-8000-000000000002'),
            RoleName::fromString('ROLE_EDITOR'),
            [$permissionId],
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        );
        $managed = Role::defineManaged(
            RoleId::fromString('018f0000-0000-7000-8000-000000000003'),
            RoleName::fromString('ROLE_ADMINISTRATOR'),
            [$permissionId],
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        );
        $roles->add($custom);
        $roles->add($managed);

        $handler = new ListRolesHandler($roles);

        self::assertSame(ListRoles::class, ListRolesHandler::queryRegistration());
        $resultSet = $handler->handle(QueryMessage::create(new ListRoles(new Pagination(1, 25))));
        $views = $resultSet->records();

        self::assertInstanceOf(ResultSet::class, $resultSet);
        self::assertSame(1, $resultSet->page());
        self::assertSame(25, $resultSet->perPage());
        self::assertSame(2, $resultSet->totalRecords());
        self::assertCount(2, $views);
        self::assertInstanceOf(RoleView::class, $views->get(0));
        self::assertSame($custom->getId(), $views->get(0)->getRoleId());
        self::assertSame($custom->getName(), $views->get(0)->getName());
        self::assertFalse($views->get(0)->isManaged());
        self::assertSame([$permissionId], $views->get(0)->getPermissionIds());
        self::assertInstanceOf(Arrayable::class, $views->get(0));
        self::assertSame(
            [
                'role_id' => '018f0000-0000-7000-8000-000000000002',
                'name' => 'ROLE_EDITOR',
                'managed' => false,
                'permission_ids' => ['018f0000-0000-7000-8000-000000000001'],
            ],
            $views->get(0)->toArray()
        );
        self::assertSame($managed->getId(), $views->get(1)->getRoleId());
        self::assertSame($managed->getName(), $views->get(1)->getName());
        self::assertTrue($views->get(1)->isManaged());
        self::assertSame([$permissionId], $views->get(1)->getPermissionIds());
        self::assertSame(
            [
                'role_id' => '018f0000-0000-7000-8000-000000000003',
                'name' => 'ROLE_ADMINISTRATOR',
                'managed' => true,
                'permission_ids' => ['018f0000-0000-7000-8000-000000000001'],
            ],
            $views->get(1)->toArray()
        );

        $properties = array_map(
            static fn(ReflectionProperty $property): string => $property->getName(),
            new ReflectionClass(RoleView::class)->getProperties()
        );
        sort($properties);

        self::assertSame(['managed', 'name', 'permissionIds', 'roleId'], $properties);
    }

    public function test_that_the_query_round_trips_and_rejects_each_missing_required_key(): void
    {
        $query = new ListRoles(new Pagination(2, 10, ['name' => Pagination::DESC]));

        self::assertSame(
            [
                'page' => 2,
                'per_page' => 10,
                'orderings' => ['name' => Pagination::DESC],
            ],
            $query->toArray()
        );
        self::assertEquals($query, ListRoles::fromArray($query->toArray()));
        self::assertSame(2, $query->getPagination()->page());
        self::assertSame(10, $query->getPagination()->perPage());
        self::assertSame(['name' => Pagination::DESC], $query->getPagination()->orderings());

        foreach (['page', 'per_page', 'orderings'] as $requiredKey) {
            $incompleteData = $query->toArray();
            unset($incompleteData[$requiredKey]);

            try {
                ListRoles::fromArray($incompleteData);
                self::fail(sprintf('Missing key "%s" must be rejected.', $requiredKey));
            } catch (DomainException) {
            }
        }
    }

    public function test_that_pagination_selects_the_actual_second_page(): void
    {
        $roles = new InMemoryRoleRepository();
        $first = Role::define(
            RoleId::fromString('018f0000-0000-7000-8000-000000000004'),
            RoleName::fromString('ROLE_FIRST'),
            [],
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        );
        $second = Role::define(
            RoleId::fromString('018f0000-0000-7000-8000-000000000005'),
            RoleName::fromString('ROLE_SECOND'),
            [],
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        );
        $roles->add($first);
        $roles->add($second);

        $handler = new ListRolesHandler($roles);

        $resultSet = $handler->handle(QueryMessage::create(new ListRoles(new Pagination(2, 1))));

        self::assertSame(2, $resultSet->page());
        self::assertSame(1, $resultSet->perPage());
        self::assertSame(2, $resultSet->totalRecords());
        self::assertSame(2, $resultSet->totalPages());
        self::assertCount(1, $resultSet->records());
        self::assertSame($second->getId(), $resultSet->records()->get(0)->getRoleId());
    }
}
