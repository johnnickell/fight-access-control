<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Role\QueryHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\Role\QueryHandler\GetRoleByIdHandler;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Role\Query\GetRoleById;
use Fight\AccessControl\Domain\AccessControl\Role\Query\RoleView;
use Fight\AccessControl\Domain\AccessControl\Role\Role;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\Role\RoleName;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Query\QueryMessage;
use Fight\Common\Domain\Type\Arrayable;
use Fight\Test\AccessControl\Application\AccessControl\Role\Repository\InMemoryRoleRepository;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryAuthorizationReferenceState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GetRoleByIdHandler::class)]
#[CoversClass(GetRoleById::class)]
final class GetRoleByIdHandlerTest extends TestCase
{
    public function test_that_it_returns_the_safe_arrayable_role_view_for_a_stable_identifier(): void
    {
        $permissionId = PermissionId::fromString('018f0000-0000-7000-8000-000000000001');
        $roleId = RoleId::fromString('018f0000-0000-7000-8000-000000000002');
        $authorizationReferences = new InMemoryAuthorizationReferenceState();
        $authorizationReferences->addPermission($permissionId);

        $roles = new InMemoryRoleRepository(authorizationReferences: $authorizationReferences);
        $roles->add(Role::defineManaged(
            $roleId,
            RoleName::fromString('ROLE_ADMINISTRATOR'),
            [$permissionId],
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        ));
        $handler = new GetRoleByIdHandler($roles);

        self::assertSame(GetRoleById::class, GetRoleByIdHandler::queryRegistration());
        $view = $handler->handle(QueryMessage::create(new GetRoleById($roleId)));

        self::assertInstanceOf(RoleView::class, $view);
        self::assertInstanceOf(Arrayable::class, $view);
        self::assertSame(
            [
                'role_id' => '018f0000-0000-7000-8000-000000000002',
                'name' => 'ROLE_ADMINISTRATOR',
                'managed' => true,
                'permission_ids' => ['018f0000-0000-7000-8000-000000000001'],
            ],
            $view->toArray()
        );
    }

    public function test_that_the_query_round_trips_and_rejects_missing_role_id(): void
    {
        $roleId = RoleId::fromString('018f0000-0000-7000-8000-000000000003');
        $query = new GetRoleById($roleId);

        self::assertEquals($query, GetRoleById::fromArray($query->toArray()));
        self::assertSame($roleId, $query->getRoleId());
        $this->expectException(DomainException::class);
        GetRoleById::fromArray([]);
    }

    public function test_that_it_returns_null_for_an_unknown_stable_identifier(): void
    {
        $handler = new GetRoleByIdHandler(new InMemoryRoleRepository());

        $view = $handler->handle(QueryMessage::create(new GetRoleById(
            RoleId::fromString('018f0000-0000-7000-8000-000000000004')
        )));

        self::assertNull($view);
    }
}
