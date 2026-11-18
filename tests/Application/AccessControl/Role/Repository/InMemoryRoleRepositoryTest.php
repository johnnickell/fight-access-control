<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Role\Repository;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionTier;
use Fight\AccessControl\Domain\AccessControl\Role\Role;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\Role\RoleName;
use Fight\Common\Domain\Repository\Pagination;
use Fight\Test\AccessControl\Application\AccessControl\Permission\Repository\InMemoryPermissionRepository;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryAuthorizationReferenceState;
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
        $roleRepository = new InMemoryRoleRepository();
        $role = $this->role('ROLE_EDITOR');
        $roleRepository->add($role);

        self::assertSame($role, $roleRepository->getById($role->getId()));
        self::assertSame($role, $roleRepository->getByName($role->getName()));
        self::assertNull($roleRepository->getById(RoleId::generate()));
        self::assertNull($roleRepository->getByName(RoleName::fromString('ROLE_MISSING')));
    }

    public function test_get_by_ids_is_unpaginated_deduplicates_and_omits_missing_ids(): void
    {
        $roleRepository = new InMemoryRoleRepository();
        $first = $this->role('ROLE_EDITOR');
        $second = $this->role('ROLE_ADMIN');
        $roleRepository->add($first);
        $roleRepository->add($second);

        self::assertSame(
            [$second, $first],
            $roleRepository->getByIds([
                $second->getId(),
                RoleId::generate(),
                $first->getId(),
                RoleId::fromString($second->getId()->toString())
            ])
        );
    }

    public function test_get_all_returns_a_typed_paginated_result_set(): void
    {
        $roleRepository = new InMemoryRoleRepository();
        $first = $this->role('ROLE_EDITOR');
        $second = $this->role('ROLE_ADMIN');
        $third = $this->role('ROLE_AUDITOR');
        $roleRepository->add($first);
        $roleRepository->add($second);
        $roleRepository->add($third);

        $resultSet = $roleRepository->getAll(new Pagination(2, 2));

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
        $roleRepository = new InMemoryRoleRepository();
        $missingMembership = Role::define(
            RoleId::generate(),
            RoleName::fromString('ROLE_MISSING_PERMISSION'),
            [$permissionId],
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        );

        try {
            $roleRepository->add($missingMembership);
            self::fail('Role add must reject a missing Permission reference.');
        } catch (RuntimeException $runtimeException) {
            self::assertSame('Role permission membership is not authoritative.', $runtimeException->getMessage());
        }

        $current = $this->role('ROLE_CURRENT');
        $roleRepository->add($current);
        self::assertFalse($roleRepository->replace($current, $missingMembership));
        self::assertSame($current, $roleRepository->getById($current->getId()));
    }

    public function test_custom_membership_writes_reject_protected_authority_but_managed_roles_retain_it(): void
    {
        $permission = Permission::defineManaged(
            PermissionId::generate(),
            PermissionName::fromString('PROTECTED_ACTION'),
            PermissionTier::SUPER_ADMIN_ONLY,
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        );
        $references = new InMemoryAuthorizationReferenceState();
        $permissionRepository = new InMemoryPermissionRepository(authorizationReferences: $references);
        $permissionRepository->add($permission);

        $roleRepository = new InMemoryRoleRepository(authorizationReferences: $references);
        $current = $this->role('ROLE_EDITOR');
        $roleRepository->add($current);
        $customWithProtected = $current->grantPermissionToCustom(
            $permission->getId(),
            new DateTimeImmutable('2026-01-02T00:00:00+00:00')
        );

        self::assertFalse($roleRepository->validateCustomPermissionGrant($permission));
        self::assertFalse($roleRepository->replace($current, $customWithProtected));
        self::assertSame($current, $roleRepository->getById($current->getId()));
        try {
            $roleRepository->add($customWithProtected);
            self::fail('Custom membership cannot include a protected Permission.');
        } catch (RuntimeException $runtimeException) {
            self::assertSame('Role permission membership is not authoritative.', $runtimeException->getMessage());
        }

        $managed = Role::defineManaged(
            RoleId::generate(),
            RoleName::fromString('ROLE_SUPER_ADMIN'),
            [$permission->getId()],
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        );
        $roleRepository->add($managed);
        self::assertSame($managed, $roleRepository->getById($managed->getId()));
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
