<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Permission\QueryHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\Permission\QueryHandler\ListPermissionsHandler;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionTier;
use Fight\AccessControl\Domain\AccessControl\Permission\Query\ListPermissions;
use Fight\AccessControl\Domain\AccessControl\Permission\Query\PermissionView;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Query\QueryMessage;
use Fight\Common\Domain\Repository\Pagination;
use Fight\Common\Domain\Repository\ResultSet;
use Fight\Common\Domain\Type\Arrayable;
use Fight\Test\AccessControl\Application\AccessControl\Permission\Repository\InMemoryPermissionRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

#[CoversClass(ListPermissionsHandler::class)]
#[CoversClass(ListPermissions::class)]
#[CoversClass(PermissionView::class)]
final class ListPermissionsHandlerTest extends TestCase
{
    public function test_that_it_returns_a_typed_page_of_safe_permission_views(): void
    {
        $permissions = new InMemoryPermissionRepository();
        $custom = Permission::define(
            PermissionId::fromString('018f0000-0000-7000-8000-000000000001'),
            PermissionName::fromString('EDIT_CONTENT'),
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        );
        $managed = Permission::defineManaged(
            PermissionId::fromString('018f0000-0000-7000-8000-000000000002'),
            PermissionName::fromString('MANAGE_USERS'),
            PermissionTier::SUPER_ADMIN_ONLY,
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        );
        $permissions->add($custom);
        $permissions->add($managed);

        $handler = new ListPermissionsHandler($permissions);

        self::assertSame(ListPermissions::class, ListPermissionsHandler::queryRegistration());
        $resultSet = $handler->handle(QueryMessage::create(new ListPermissions(new Pagination(1, 25))));
        $views = $resultSet->records();

        self::assertInstanceOf(ResultSet::class, $resultSet);
        self::assertSame(1, $resultSet->page());
        self::assertSame(25, $resultSet->perPage());
        self::assertSame(2, $resultSet->totalRecords());
        self::assertCount(2, $views);
        self::assertInstanceOf(PermissionView::class, $views->get(0));
        self::assertInstanceOf(Arrayable::class, $views->get(0));
        self::assertSame($custom->getId(), $views->get(0)->getPermissionId());
        self::assertSame($custom->getName(), $views->get(0)->getName());
        self::assertNull($views->get(0)->getTier());
        self::assertFalse($views->get(0)->isManaged());
        self::assertSame(
            [
                'permission_id' => '018f0000-0000-7000-8000-000000000001',
                'name' => 'EDIT_CONTENT',
                'tier' => null,
                'managed' => false,
            ],
            $views->get(0)->toArray()
        );
        self::assertSame($managed->getId(), $views->get(1)->getPermissionId());
        self::assertSame($managed->getName(), $views->get(1)->getName());
        self::assertSame(PermissionTier::SUPER_ADMIN_ONLY, $views->get(1)->getTier());
        self::assertTrue($views->get(1)->isManaged());
        self::assertSame(
            [
                'permission_id' => '018f0000-0000-7000-8000-000000000002',
                'name' => 'MANAGE_USERS',
                'tier' => 'SUPER_ADMIN_ONLY',
                'managed' => true,
            ],
            $views->get(1)->toArray()
        );

        $properties = array_map(
            static fn(ReflectionProperty $property): string => $property->getName(),
            new ReflectionClass(PermissionView::class)->getProperties()
        );
        sort($properties);

        self::assertSame(['managed', 'name', 'permissionId', 'tier'], $properties);
    }

    public function test_that_the_query_round_trips_and_rejects_each_missing_required_key(): void
    {
        $query = new ListPermissions(new Pagination(2, 10, ['name' => Pagination::DESC]));

        self::assertSame(
            [
                'page' => 2,
                'per_page' => 10,
                'orderings' => ['name' => Pagination::DESC],
            ],
            $query->toArray()
        );
        self::assertEquals(
            new ListPermissions(new Pagination(2, 10, ['name' => Pagination::DESC])),
            ListPermissions::fromArray(
                [
                    'page' => 2,
                    'per_page' => 10,
                    'orderings' => ['name' => Pagination::DESC],
                ]
            )
        );
        self::assertSame(2, $query->getPagination()->page());
        self::assertSame(10, $query->getPagination()->perPage());
        self::assertSame(['name' => Pagination::DESC], $query->getPagination()->orderings());

        foreach (['page', 'per_page', 'orderings'] as $requiredKey) {
            $completeData = [
                'page' => 2,
                'per_page' => 10,
                'orderings' => ['name' => Pagination::DESC],
            ];
            unset($completeData[$requiredKey]);

            try {
                ListPermissions::fromArray($completeData);
                self::fail(sprintf('Missing key "%s" must be rejected.', $requiredKey));
            } catch (DomainException) {
            }
        }
    }

    public function test_that_pagination_selects_the_actual_second_page(): void
    {
        $permissions = new InMemoryPermissionRepository();
        $first = Permission::define(
            PermissionId::fromString('018f0000-0000-7000-8000-000000000003'),
            PermissionName::fromString('FIRST_PERMISSION'),
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        );
        $second = Permission::define(
            PermissionId::fromString('018f0000-0000-7000-8000-000000000004'),
            PermissionName::fromString('SECOND_PERMISSION'),
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        );
        $permissions->add($first);
        $permissions->add($second);

        $handler = new ListPermissionsHandler($permissions);

        $resultSet = $handler->handle(QueryMessage::create(new ListPermissions(new Pagination(2, 1))));

        self::assertSame(2, $resultSet->page());
        self::assertSame(1, $resultSet->perPage());
        self::assertSame(2, $resultSet->totalRecords());
        self::assertSame(2, $resultSet->totalPages());
        self::assertCount(1, $resultSet->records());
        self::assertSame($second->getId(), $resultSet->records()->get(0)->getPermissionId());
    }
}
