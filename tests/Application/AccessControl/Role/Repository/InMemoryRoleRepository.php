<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Role\Repository;

use Closure;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Role\Role;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\Role\RoleName;
use Fight\AccessControl\Domain\AccessControl\Role\RoleRepository;
use Fight\Common\Domain\Collection\ArrayList;
use Fight\Common\Domain\Repository\Pagination;
use Fight\Common\Domain\Repository\ResultSet;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryAuthorizationReferenceState;
use RuntimeException;

final class InMemoryRoleRepository implements RoleRepository
{
    /** @var list<Role> */
    private array $roles = [];

    private readonly InMemoryAuthorizationReferenceState $authorizationReferences;

    public function __construct(
        private readonly ?InMemoryUnitOfWork $unitOfWork = null,
        private readonly ?Closure $beforeReplace = null,
        private readonly ?Closure $beforeRemove = null,
        ?InMemoryAuthorizationReferenceState $authorizationReferences = null
    ) {
        $resolvedAuthorizationReferences = $authorizationReferences ?? new InMemoryAuthorizationReferenceState();
        if (
            $unitOfWork instanceof InMemoryUnitOfWork
            && !$authorizationReferences instanceof InMemoryAuthorizationReferenceState
        ) {
            $resolvedAuthorizationReferences = $unitOfWork->authorizationReferenceState();
        }

        $this->authorizationReferences = $resolvedAuthorizationReferences;
    }

    public function add(Role $role): void
    {
        $this->authorizationReferences->holdThroughCompletion();
        if (!$this->authorizationReferences->permissionsAreAuthoritative($role->getPermissionIds())) {
            throw new RuntimeException('Role permission membership is not authoritative.');
        }

        $this->roles[] = $role;
        $this->authorizationReferences->addRole($role);
        $this->unitOfWork?->onRollback(function () use ($role): void {
            array_pop($this->roles);
            $this->authorizationReferences->removeRole($role->getId());
        });
    }

    public function getById(RoleId $id): ?Role
    {
        foreach ($this->roles as $role) {
            if ($role->getId()->equals($id)) {
                return $role;
            }
        }

        return null;
    }

    public function getByName(RoleName $name): ?Role
    {
        foreach ($this->roles as $role) {
            if ($role->getName()->equals($name)) {
                return $role;
            }
        }

        return null;
    }

    /** @phpstan-param list<RoleId> $ids */
    public function getByIds(array $ids): array
    {
        $roles = [];
        $seen = [];

        foreach ($ids as $id) {
            $key = $id->toString();
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $role = $this->getById($id);
            if ($role instanceof Role) {
                $roles[] = $role;
            }
        }

        return $roles;
    }

    public function getAll(Pagination $pagination): ResultSet
    {
        $records = ArrayList::of(Role::class)->replace(array_slice(
            $this->roles,
            $pagination->offset(),
            $pagination->limit()
        ));

        return new ResultSet(
            $pagination->page(),
            $pagination->perPage(),
            count($this->roles),
            $records
        );
    }

    public function getManaged(): array
    {
        return array_values(array_filter(
            $this->roles,
            static fn(Role $role): bool => $role->isManaged()
        ));
    }

    public function getContainingPermission(PermissionId $id): array
    {
        return array_values(array_filter(
            $this->roles,
            static fn(Role $role): bool => $role->hasPermission($id)
        ));
    }

    public function replace(Role $expected, Role $replacement): bool
    {
        $this->authorizationReferences->holdThroughCompletion();
        $this->beforeReplace?->__invoke();
        if (!$this->authorizationReferences->permissionsAreAuthoritative($replacement->getPermissionIds())) {
            return false;
        }

        foreach ($this->roles as $index => $role) {
            if ($role !== $expected) {
                continue;
            }

            $this->roles[$index] = $replacement;
            $this->authorizationReferences->addRole($replacement);
            $this->unitOfWork?->onRollback(function () use ($expected, $index): void {
                $this->roles[$index] = $expected;
                $this->authorizationReferences->addRole($expected);
            });

            return true;
        }

        return false;
    }

    public function remove(Role $role): bool
    {
        $this->authorizationReferences->holdThroughCompletion();
        $this->beforeRemove?->__invoke();
        if ($this->authorizationReferences->hasUserRole($role->getId())) {
            return false;
        }

        foreach ($this->roles as $index => $candidate) {
            if ($candidate !== $role) {
                continue;
            }

            array_splice($this->roles, $index, 1);
            $this->authorizationReferences->removeRole($role->getId());
            $this->unitOfWork?->onRollback(function () use ($role, $index): void {
                array_splice($this->roles, $index, 0, [$role]);
                $this->authorizationReferences->addRole($role);
            });

            return true;
        }

        return false;
    }
}
