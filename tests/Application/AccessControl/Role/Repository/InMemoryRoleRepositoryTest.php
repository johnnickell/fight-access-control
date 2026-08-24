<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Role\Repository;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Role\Role;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\Role\RoleName;
use Fight\Common\Domain\Repository\Pagination;
use Fight\Test\AccessControl\Application\AccessControl\Permission\Repository\InMemoryPermissionRepository;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

#[CoversNothing]
final class InMemoryRoleRepositoryTest extends TestCase
{
    public function test_reference_changing_writes_have_canonical_repository_owned_signatures(): void
    {
        self::assertSame(1, new ReflectionMethod(InMemoryRoleRepository::class, 'add')->getNumberOfParameters());
        self::assertSame(2, new ReflectionMethod(InMemoryRoleRepository::class, 'replace')->getNumberOfParameters());
        self::assertSame(1, new ReflectionMethod(InMemoryRoleRepository::class, 'remove')->getNumberOfParameters());
    }

    public function test_it_retrieves_roles_by_id_and_name(): void
    {
        $repository = new InMemoryRoleRepository();
        $role = $this->role('ROLE_EDITOR');
        $repository->add($role);

        self::assertSame($role, $repository->getById($role->getId()));
        self::assertSame($role, $repository->getByName($role->getName()));
        self::assertNull($repository->getById(RoleId::generate()));
        self::assertNull($repository->getByName(RoleName::fromString('ROLE_MISSING')));
    }

    public function test_get_by_ids_is_unpaginated_deduplicates_and_omits_missing_ids(): void
    {
        $repository = new InMemoryRoleRepository();
        $first = $this->role('ROLE_EDITOR');
        $second = $this->role('ROLE_ADMIN');
        $repository->add($first);
        $repository->add($second);

        self::assertSame(
            [$second, $first],
            $repository->getByIds([
                $second->getId(),
                RoleId::generate(),
                $first->getId(),
                RoleId::fromString($second->getId()->toString()),
            ])
        );
    }

    public function test_get_all_returns_a_typed_paginated_result_set(): void
    {
        $repository = new InMemoryRoleRepository();
        $first = $this->role('ROLE_EDITOR');
        $second = $this->role('ROLE_ADMIN');
        $third = $this->role('ROLE_AUDITOR');
        $repository->add($first);
        $repository->add($second);
        $repository->add($third);

        $resultSet = $repository->getAll(new Pagination(2, 2));

        self::assertSame(2, $resultSet->page());
        self::assertSame(2, $resultSet->perPage());
        self::assertSame(2, $resultSet->totalPages());
        self::assertSame(3, $resultSet->totalRecords());
        self::assertSame(Role::class, $resultSet->itemType());
        self::assertSame([$third], $resultSet->records()->toArray());
    }

    public function test_get_all_preserves_the_role_type_when_the_page_is_empty(): void
    {
        $resultSet = new InMemoryRoleRepository()->getAll(new Pagination());

        self::assertSame(Role::class, $resultSet->itemType());
        self::assertSame([], $resultSet->records()->toArray());
        self::assertSame(0, $resultSet->totalRecords());
    }

    public function test_add_and_replace_reject_non_authoritative_permission_membership(): void
    {
        $permissionId = PermissionId::generate();
        $permissions = new InMemoryPermissionRepository();
        $repository = new InMemoryRoleRepository();
        $missingMembership = Role::define(
            RoleId::generate(),
            RoleName::fromString('ROLE_MISSING_PERMISSION'),
            [$permissionId],
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        );

        try {
            $repository->add($missingMembership);
            self::fail('Role add must reject a missing Permission reference.');
        } catch (RuntimeException $runtimeException) {
            self::assertSame('Role permission membership is not authoritative.', $runtimeException->getMessage());
        }

        $current = $this->role('ROLE_CURRENT');
        $repository->add($current);
        self::assertFalse($repository->replace($current, $missingMembership));
        self::assertSame($current, $repository->getById($current->getId()));
    }

    private function role(string $name): Role
    {
        return Role::define(
            RoleId::generate(),
            RoleName::fromString($name),
            [],
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        );
    }
}
