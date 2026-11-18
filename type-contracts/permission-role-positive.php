<?php

declare(strict_types=1);

use Fight\AccessControl\Domain\AccessControl\Authorization\AuthenticatedUserPrincipal;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\Command\ReconcileManagedPolicy;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\Event\ManagedPolicyReconciled;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\ManagedPolicy;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\ManagedPolicyPlan;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\Query\PreviewManagedPolicy;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionRepository;
use Fight\AccessControl\Domain\AccessControl\Role\Command\RenameCustomRole;
use Fight\AccessControl\Domain\AccessControl\Role\Role;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\Role\RoleRepository;
use Fight\Common\Domain\Repository\Pagination;

use function PHPStan\dumpType;

static function (
    Role $role,
    RoleRepository $roleRepository,
    RoleId $roleId,
    PermissionRepository $permissionRepository,
    PermissionId $permissionId,
    Pagination $pagination,
    AuthenticatedUserPrincipal $principal,
    ManagedPolicy $policy,
    ManagedPolicyPlan $plan,
    ManagedPolicyReconciled $event,
    RenameCustomRole $rename,
    PreviewManagedPolicy $preview,
    ReconcileManagedPolicy $reconcile
): void {
    dumpType($role->getPermissionIds());
    dumpType($role->getRevision());
    dumpType($roleRepository->getById($roleId));
    dumpType($roleRepository->getByIds([$roleId]));
    dumpType($roleRepository->getAll($pagination));
    dumpType($permissionRepository->getById($permissionId));
    dumpType($permissionRepository->getByIds([$permissionId]));
    dumpType($permissionRepository->getAll($pagination));
    dumpType($principal->getRoles());
    dumpType($principal->getPermissions());
    dumpType($policy->getPermissions());
    dumpType($policy->getRoles());
    dumpType($plan->getPermissions());
    dumpType($plan->getRoles());
    dumpType($plan->toArray());
    dumpType($event->getPlan());
    dumpType($event->toArray());
    dumpType($rename->toArray());
    dumpType($preview->toArray());
    dumpType($reconcile->toArray());
    dumpType(array_map(static fn(PermissionId $id): string => $id->toString(), $role->getPermissionIds()));
};
