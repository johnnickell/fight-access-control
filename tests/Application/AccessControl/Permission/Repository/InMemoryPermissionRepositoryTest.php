<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Permission\Repository;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentName;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\AccessControl\Domain\AccessControl\Role\Role;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\Role\RoleName;
use Fight\Common\Domain\Repository\Pagination;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Repository\InMemoryAgentRepository;
use Fight\Test\AccessControl\Application\AccessControl\Role\Repository\InMemoryRoleRepository;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryAuthorizationReferenceState;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

#[CoversNothing]
final class InMemoryPermissionRepositoryTest extends TestCase
{
    public function test_remove_has_a_canonical_repository_owned_signature(): void
    {
        $remove = new ReflectionMethod(InMemoryPermissionRepository::class, 'remove');

        self::assertSame(1, $remove->getNumberOfParameters());
    }

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

    public function test_remove_rejects_a_permission_directly_referenced_by_an_agent(): void
    {
        $authorizationReferences = new InMemoryAuthorizationReferenceState();
        $permissions = new InMemoryPermissionRepository(
            authorizationReferences: $authorizationReferences
        );
        $permission = $this->permission('VIEW_USERS');
        $permissions->add($permission);
        $agents = new InMemoryAgentRepository(
            authorizationReferences: $authorizationReferences
        );
        $agent = Agent::provision(
            AgentId::generate(),
            AgentName::fromString('Production deployment'),
            AgentCredentialId::generate(),
            'encrypted:current-secret',
            new DateTimeImmutable('2026-08-25T12:00:00+00:00')
        )->grantPermission(
            $permission->getId(),
            new DateTimeImmutable('2026-08-25T13:00:00+00:00')
        );
        $agents->add($agent);

        self::assertFalse($permissions->remove($permission));
        self::assertSame($permission, $permissions->getById($permission->getId()));
    }

    public function test_remove_preserves_unreferenced_success_and_role_reference_rejection(): void
    {
        foreach (['unreferenced', 'role'] as $case) {
            $authorizationReferences = new InMemoryAuthorizationReferenceState();
            $permissions = new InMemoryPermissionRepository(
                authorizationReferences: $authorizationReferences
            );
            $permission = $this->permission('VIEW_USERS');
            $permissions->add($permission);
            if ($case === 'role') {
                $roles = new InMemoryRoleRepository(
                    authorizationReferences: $authorizationReferences
                );
                $roles->add(Role::define(
                    RoleId::generate(),
                    RoleName::fromString('ROLE_VIEWER'),
                    [$permission->getId()],
                    new DateTimeImmutable('2026-08-25T12:00:00+00:00')
                ));
            }

            self::assertSame($case === 'unreferenced', $permissions->remove($permission));
            self::assertSame(
                $case === 'unreferenced' ? null : $permission,
                $permissions->getById($permission->getId())
            );
        }
    }

    public function test_remove_rejects_an_agent_reference_introduced_at_the_held_final_boundary(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $permission = $this->permission('VIEW_USERS');
        $agents = new InMemoryAgentRepository($unitOfWork);
        $agent = Agent::provision(
            AgentId::generate(),
            AgentName::fromString('Production deployment'),
            AgentCredentialId::generate(),
            'encrypted:current-secret',
            new DateTimeImmutable('2026-08-25T12:00:00+00:00')
        );
        $agents->add($agent);
        $permissions = new InMemoryPermissionRepository(
            $unitOfWork,
            beforeRemove: static function () use ($agent, $agents, $permission, $unitOfWork): void {
                self::assertTrue($unitOfWork->authorizationReferenceState()->isReferenceFenceHeld());
                self::assertTrue($agents->replacePermissionAssignments(
                    $agent,
                    $agent->grantPermission(
                        $permission->getId(),
                        new DateTimeImmutable('2026-08-25T13:00:00+00:00')
                    )
                ));
            }
        );
        $permissions->add($permission);

        $removed = $unitOfWork->commitTransactional(
            static fn(): bool => $permissions->remove($permission)
        );

        self::assertFalse($removed);
        self::assertSame($permission, $permissions->getById($permission->getId()));
        self::assertTrue($agents->getById($agent->getId())?->hasPermission($permission->getId()));
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
