<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Role\Repository;

use Fight\AccessControl\Domain\AccessControl\Role\Role;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\Role\RoleName;
use Fight\AccessControl\Domain\AccessControl\Role\RoleRepository;
use Fight\Common\Domain\Collection\ArrayList;
use Fight\Common\Domain\Repository\Pagination;
use Fight\Common\Domain\Repository\ResultSet;

final class InMemoryRoleRepository implements RoleRepository
{
    /** @var list<Role> */
    private array $roles = [];

    public function add(Role $role): void
    {
        $this->roles[] = $role;
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
}
