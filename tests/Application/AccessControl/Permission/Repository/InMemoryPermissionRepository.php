<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Permission\Repository;

use Closure;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionRepository;
use Fight\Common\Domain\Collection\ArrayList;
use Fight\Common\Domain\Repository\Pagination;
use Fight\Common\Domain\Repository\ResultSet;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryAuthorizationReferenceState;

final class InMemoryPermissionRepository implements PermissionRepository
{
    /** @var list<Permission> */
    private array $permissions = [];

    private readonly InMemoryAuthorizationReferenceState $authorizationReferences;

    public function __construct(
        private readonly ?InMemoryUnitOfWork $unitOfWork = null,
        private readonly ?Closure $beforeRemove = null,
        private readonly bool $removeSucceeds = true,
        ?InMemoryAuthorizationReferenceState $authorizationReferences = null,
        private readonly ?Closure $getByIdsResult = null
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

    public function add(Permission $permission): void
    {
        $this->permissions[] = $permission;
        $this->authorizationReferences->addPermission($permission->getId());
        $this->unitOfWork?->onRollback(function () use ($permission): void {
            array_pop($this->permissions);
            $this->authorizationReferences->removePermission($permission->getId());
        });
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
        if ($this->getByIdsResult instanceof Closure) {
            /** @var list<Permission> $result */
            $result = ($this->getByIdsResult)($ids);

            return $result;
        }

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

    public function getManaged(): array
    {
        return array_values(array_filter(
            $this->permissions,
            static fn(Permission $permission): bool => $permission->isManaged()
        ));
    }

    public function replace(Permission $expected, Permission $replacement): bool
    {
        foreach ($this->permissions as $index => $permission) {
            if ($permission !== $expected) {
                continue;
            }

            $this->permissions[$index] = $replacement;
            $this->unitOfWork?->onRollback(function () use ($expected, $index): void {
                $this->permissions[$index] = $expected;
            });

            return true;
        }

        return false;
    }

    public function remove(Permission $permission): bool
    {
        $this->authorizationReferences->holdThroughCompletion();
        $this->beforeRemove?->__invoke();
        if (
            !$this->removeSucceeds
            || $this->authorizationReferences->roleContainsPermission($permission->getId())
            || $this->authorizationReferences->agentContainsPermission($permission->getId())
        ) {
            return false;
        }

        foreach ($this->permissions as $index => $candidate) {
            if ($candidate !== $permission) {
                continue;
            }

            array_splice($this->permissions, $index, 1);
            $this->authorizationReferences->removePermission($permission->getId());
            $this->unitOfWork?->onRollback(function () use ($permission, $index): void {
                array_splice($this->permissions, $index, 0, [$permission]);
                $this->authorizationReferences->addPermission($permission->getId());
            });

            return true;
        }

        return false;
    }
}
