<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Role\Repository;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\AccessControl\Domain\AccessControl\Role\Role;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\Role\RoleName;
use Fight\AccessControl\Domain\AccessControl\Role\RoleRepository;
use Fight\AccessControl\Domain\AccessControl\Role\RoleRevision;
use Fight\Test\AccessControl\Application\AccessControl\Permission\Repository\InMemoryPermissionRepository;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(Role::class)]
final class InMemoryRoleRevisionConformanceTest extends RoleRevisionConformance
{
    private InMemoryUnitOfWork $unitOfWork;

    private InMemoryRoleRepositoryState $state;

    /** @var list<Permission> */
    private array $permissions = [];

    protected function setUp(): void
    {
        $this->unitOfWork = new InMemoryUnitOfWork();
        $this->state = new InMemoryRoleRepositoryState();
    }

    protected function repository(): RoleRepository
    {
        return new InMemoryRoleRepository($this->unitOfWork, state: $this->state);
    }

    protected function restart(RoleRepository $repository): RoleRepository
    {
        $storedRoles = $this->state->roles;
        $this->unitOfWork = new InMemoryUnitOfWork();
        $this->state = new InMemoryRoleRepositoryState();
        $permissions = new InMemoryPermissionRepository($this->unitOfWork);
        foreach ($this->permissions as $permission) {
            $permissions->add(clone $permission);
        }

        foreach ($storedRoles as $index => $role) {
            $restored = Role::reconstitute(
                RoleId::fromString($role->getId()->toString()),
                RoleName::fromString($role->getName()->toString()),
                array_map(
                    static fn(PermissionId $id): PermissionId => PermissionId::fromString($id->toString()),
                    $role->getPermissionIds()
                ),
                $role->isManaged(),
                new DateTimeImmutable($role->getCreatedAt()->format('Y-m-d\TH:i:s.uP')),
                new DateTimeImmutable($role->getUpdatedAt()->format('Y-m-d\TH:i:s.uP')),
                RoleRevision::fromString($role->getRevision()->toString())
            );
            $this->state->roles[$index] = $restored;
            $this->unitOfWork->authorizationReferenceState()->addRole($restored);
        }

        return $this->repository();
    }

    protected function permission(): PermissionId
    {
        $permission = Permission::define(
            PermissionId::generate(),
            PermissionName::fromString('READ_CASES'),
            new DateTimeImmutable('2030-01-01Z')
        );
        $this->permissions[] = $permission;
        new InMemoryPermissionRepository($this->unitOfWork)->add($permission);

        return $permission->getId();
    }

    protected function transaction(callable $operation): void
    {
        $this->unitOfWork->commitTransactional($operation);
    }
}
