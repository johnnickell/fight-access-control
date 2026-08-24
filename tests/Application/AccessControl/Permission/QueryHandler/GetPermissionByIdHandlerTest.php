<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Permission\QueryHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\Permission\QueryHandler\GetPermissionByIdHandler;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionTier;
use Fight\AccessControl\Domain\AccessControl\Permission\Query\GetPermissionById;
use Fight\AccessControl\Domain\AccessControl\Permission\Query\PermissionView;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Query\QueryMessage;
use Fight\Common\Domain\Type\Arrayable;
use Fight\Test\AccessControl\Application\AccessControl\Permission\Repository\InMemoryPermissionRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GetPermissionByIdHandler::class)]
#[CoversClass(GetPermissionById::class)]
final class GetPermissionByIdHandlerTest extends TestCase
{
    public function test_that_it_returns_an_independent_safe_arrayable_managed_permission_view(): void
    {
        $permissionId = PermissionId::fromString('018f0000-0000-7000-8000-000000000001');
        $permission = Permission::defineManaged(
            $permissionId,
            PermissionName::fromString('MANAGE_USERS'),
            PermissionTier::SUPER_ADMIN_ONLY,
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        );
        $permissions = new InMemoryPermissionRepository();
        $permissions->add($permission);

        $handler = new GetPermissionByIdHandler($permissions);

        self::assertSame(GetPermissionById::class, GetPermissionByIdHandler::queryRegistration());
        $view = $handler->handle(QueryMessage::create(new GetPermissionById($permissionId)));

        self::assertInstanceOf(PermissionView::class, $view);
        self::assertInstanceOf(Arrayable::class, $view);
        self::assertNotSame($permission, $view);
        self::assertSame($permissionId, $view->getPermissionId());
        self::assertSame(PermissionTier::SUPER_ADMIN_ONLY, $view->getTier());
        self::assertSame(
            [
                'permission_id' => '018f0000-0000-7000-8000-000000000001',
                'name' => 'MANAGE_USERS',
                'tier' => 'SUPER_ADMIN_ONLY',
                'managed' => true,
            ],
            $view->toArray()
        );
    }

    public function test_that_the_query_round_trips_and_rejects_missing_permission_id(): void
    {
        $permissionId = PermissionId::fromString('018f0000-0000-7000-8000-000000000002');
        $query = new GetPermissionById($permissionId);

        self::assertEquals($query, GetPermissionById::fromArray($query->toArray()));
        self::assertSame($permissionId, $query->getPermissionId());
        $this->expectException(DomainException::class);
        GetPermissionById::fromArray([]);
    }

    public function test_that_it_returns_null_for_an_unknown_stable_identifier(): void
    {
        $handler = new GetPermissionByIdHandler(new InMemoryPermissionRepository());

        $view = $handler->handle(QueryMessage::create(new GetPermissionById(
            PermissionId::fromString('018f0000-0000-7000-8000-000000000003')
        )));

        self::assertNull($view);
    }
}
