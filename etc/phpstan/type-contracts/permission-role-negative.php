<?php

declare(strict_types=1);

use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\ManagedPermissionDefinition;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\ManagedPermissionPlanItem;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\ManagedPolicy;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\ManagedPolicyPlan;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionRepository;
use Fight\AccessControl\Domain\AccessControl\Role\Query\RoleView;
use Fight\AccessControl\Domain\AccessControl\Role\Role;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\Role\RoleName;
use Fight\AccessControl\Domain\AccessControl\Role\RoleRepository;
use Fight\AccessControl\Domain\AccessControl\Role\RoleRevision;

static function (
    RoleRepository $roleRepository,
    PermissionRepository $permissionRepository,
    RoleId $roleId,
    PermissionId $permissionId,
    RoleName $name,
    RoleRevision $revision,
    ManagedPermissionDefinition $definition,
    ManagedPermissionPlanItem $item,
    ManagedPolicyPlan $plan,
    DateTimeImmutable $now
): void {
    $roleRepository->getById($permissionId);
    $permissionRepository->getById($roleId);
    Role::define($roleId, $name, [$roleId], $now);
    new RoleView($roleId, $name, false, [$roleId], $revision);
    new ManagedPolicy([$item], []);
    new ManagedPolicyPlan([$definition], []);
    (static function (Role $role): void {
    })($roleRepository->getById($roleId));
    (static function (Permission $permission): void {
    })($permissionRepository->getById($permissionId));
    array_map(static fn(PermissionId $id): string => $id->toString(), $plan->toArray()['roles'][0]['permission_ids']);
};
