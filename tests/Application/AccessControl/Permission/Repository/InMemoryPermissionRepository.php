<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Permission\Repository;

use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionRepository;
use Fight\Common\Domain\Collection\ArrayList;
use Fight\Common\Domain\Repository\Pagination;
use Fight\Common\Domain\Repository\ResultSet;

final class InMemoryPermissionRepository implements PermissionRepository
{
    /** @var list<Permission> */
    private array $permissions = [];

    public function add(Permission $permission): void
    {
        $this->permissions[] = $permission;
    }

    public function getById(PermissionId $id): ?Permission
    {
        foreach ($this->permissions as $permission) {
            if ($permission->getId()->equals($id)) {
                return $permission;
            }
        }

        return null;
    }

    public function getByName(PermissionName $name): ?Permission
    {
        foreach ($this->permissions as $permission) {
            if ($permission->getName()->equals($name)) {
                return $permission;
            }
        }

        return null;
    }

    /** @phpstan-param list<PermissionId> $ids */
    public function getByIds(array $ids): array
    {
        $permissions = [];
        $seen = [];

        foreach ($ids as $id) {
            $key = $id->toString();
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $permission = $this->getById($id);
            if ($permission instanceof Permission) {
                $permissions[] = $permission;
            }
        }

        return $permissions;
    }

    public function getAll(Pagination $pagination): ResultSet
    {
        $records = ArrayList::of(Permission::class)->replace(array_slice(
            $this->permissions,
            $pagination->offset(),
            $pagination->limit()
        ));

        return new ResultSet(
            $pagination->page(),
            $pagination->perPage(),
            count($this->permissions),
            $records
        );
    }
}
