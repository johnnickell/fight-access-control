<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Permission\Repository;

use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\Common\Domain\Repository\Pagination;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class InMemoryPermissionRepositoryTest extends TestCase
{
    public function test_it_retrieves_permissions_by_id_and_name(): void
    {
        $repository = new InMemoryPermissionRepository();
        $permission = $this->permission('VIEW_USERS');
        $repository->add($permission);

        self::assertSame($permission, $repository->getById($permission->getId()));
        self::assertSame($permission, $repository->getByName($permission->getName()));
        self::assertNull($repository->getById(PermissionId::generate()));
        self::assertNull($repository->getByName(PermissionName::fromString('MISSING_PERMISSION')));
    }

    public function test_get_by_ids_is_unpaginated_deduplicates_and_omits_missing_ids(): void
    {
        $repository = new InMemoryPermissionRepository();
        $first = $this->permission('VIEW_USERS');
        $second = $this->permission('MANAGE_USERS');
        $repository->add($first);
        $repository->add($second);

        self::assertSame(
            [$second, $first],
            $repository->getByIds([
                $second->getId(),
                PermissionId::generate(),
                $first->getId(),
                PermissionId::fromString($second->getId()->toString()),
            ])
        );
    }

    public function test_get_all_returns_a_typed_paginated_result_set(): void
    {
        $repository = new InMemoryPermissionRepository();
        $first = $this->permission('VIEW_USERS');
        $second = $this->permission('MANAGE_USERS');
        $third = $this->permission('DELETE_USERS');
        $repository->add($first);
        $repository->add($second);
        $repository->add($third);

        $resultSet = $repository->getAll(new Pagination(2, 2));

        self::assertSame(2, $resultSet->page());
        self::assertSame(2, $resultSet->perPage());
        self::assertSame(2, $resultSet->totalPages());
        self::assertSame(3, $resultSet->totalRecords());
        self::assertSame(Permission::class, $resultSet->itemType());
        self::assertSame([$third], $resultSet->records()->toArray());
    }

    public function test_get_all_preserves_the_permission_type_when_the_page_is_empty(): void
    {
        $resultSet = new InMemoryPermissionRepository()->getAll(new Pagination());

        self::assertSame(Permission::class, $resultSet->itemType());
        self::assertSame([], $resultSet->records()->toArray());
        self::assertSame(0, $resultSet->totalRecords());
    }

    private function permission(string $name): Permission
    {
        return Permission::define(PermissionId::generate(), PermissionName::fromString($name));
    }
}
