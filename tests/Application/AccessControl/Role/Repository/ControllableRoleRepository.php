<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Role\Repository;

use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Role\Role;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\Role\RoleName;
use Fight\AccessControl\Domain\AccessControl\Role\RoleRepository;
use Fight\Common\Domain\Collection\ArrayList;
use Fight\Common\Domain\Repository\Pagination;
use Fight\Common\Domain\Repository\ResultSet;
use Throwable;

final class ControllableRoleRepository implements RoleRepository
{
    public function __construct(
        private ?Role $role = null,
        private readonly bool $replaceSucceeds = true,
        private readonly bool $removeSucceeds = true,
        private readonly ?Throwable $getFailure = null,
        private readonly bool $roleRemainsAuthoritative = true
    ) {
    }

    public function add(Role $role): void
    {
        $this->role = $role;
    }

    public function getById(RoleId $id): ?Role
    {
        if ($this->getFailure instanceof Throwable) {
            throw $this->getFailure;
        }

        return $this->role?->getId()->equals($id) === true ? $this->role : null;
    }

    public function getByName(RoleName $name): ?Role
    {
        return $this->role?->getName()->equals($name) === true ? $this->role : null;
    }

    public function getByIds(array $ids): array
    {
        if (!$this->roleRemainsAuthoritative || !$this->role instanceof Role) {
            return [];
        }

        foreach ($ids as $id) {
            if ($this->role->getId()->equals($id)) {
                return [$this->role];
            }
        }

        return [];
    }

    public function getAll(Pagination $pagination): ResultSet
    {
        $roles = $this->role instanceof Role ? [$this->role] : [];

        return new ResultSet(1, $pagination->perPage(), count($roles), ArrayList::of(Role::class)->replace($roles));
    }

    public function getManaged(): array
    {
        return $this->role instanceof Role && $this->role->isManaged() ? [$this->role] : [];
    }

    public function getContainingPermission(PermissionId $id): array
    {
        return $this->role instanceof Role && $this->role->hasPermission($id) ? [$this->role] : [];
    }

    public function replace(Role $expected, Role $replacement): bool
    {
        if (!$this->replaceSucceeds || $this->role !== $expected) {
            return false;
        }

        $this->role = $replacement;

        return true;
    }

    public function remove(Role $role): bool
    {
        if (
            $this->removeSucceeds
            && $this->role === $role
        ) {
            $this->role = null;

            return true;
        }

        return false;
    }
}
