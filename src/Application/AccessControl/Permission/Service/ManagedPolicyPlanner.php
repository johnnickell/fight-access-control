<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Permission\Service;

use Fight\AccessControl\Domain\AccessControl\Permission\Exception\ManagedPolicyDefinitionException;
use Fight\AccessControl\Domain\AccessControl\Permission\ManagedPermissionDefinition;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionRepository;
use Fight\AccessControl\Domain\AccessControl\Permission\Query\ManagedPermissionPlanItem;
use Fight\AccessControl\Domain\AccessControl\Permission\Query\ManagedPolicyChangeAction;
use Fight\AccessControl\Domain\AccessControl\Permission\Query\ManagedPolicyPlan;
use Fight\AccessControl\Domain\AccessControl\Permission\Query\ManagedRolePlanItem;
use Fight\AccessControl\Domain\AccessControl\Role\ManagedRoleDefinition;
use Fight\AccessControl\Domain\AccessControl\Role\Role;
use Fight\AccessControl\Domain\AccessControl\Role\RoleRepository;
use Fight\AccessControl\Domain\AccessControl\User\UserRepository;

/**
 * Produces the complete deterministic plan shared by preview and apply.
 */
final readonly class ManagedPolicyPlanner
{
    /**
     * Creates the shared managed-policy planner.
     */
    public function __construct(
        private PermissionRepository $permissionRepository,
        private RoleRepository $roleRepository,
        private UserRepository $userRepository
    ) {
    }

    /**
     * @phpstan-param list<ManagedPermissionDefinition> $permissions
     * @phpstan-param list<ManagedRoleDefinition> $roles
     * @phpstan-param list<PermissionId> $referencedPermissionIds
     */
    public function plan(array $permissions, array $roles, array $referencedPermissionIds): ManagedPolicyPlan
    {
        $permissionItems = [];
        $desiredPermissionIds = [];
        foreach ($permissions as $definition) {
            $desiredPermissionIds[$definition->getId()->toString()] = true;
            $permissionItems[] = $this->planPermission($definition);
        }

        $roleItems = [];
        $desiredRoleIds = [];
        foreach ($roles as $definition) {
            $desiredRoleIds[$definition->getId()->toString()] = true;
            $roleItems[] = $this->planRole($definition);
        }

        foreach ($this->roleRepository->getManaged() as $role) {
            if (!isset($desiredRoleIds[$role->getId()->toString()])) {
                if ($this->userRepository->hasRoleAssignment($role->getId())) {
                    throw new ManagedPolicyDefinitionException(sprintf(
                        'Managed role "%s" cannot be removed because a user references it.',
                        $role->getName()->toString()
                    ));
                }

                $roleItems[] = new ManagedRolePlanItem(
                    new ManagedRoleDefinition($role->getId(), $role->getName(), $role->getPermissionIds()),
                    ManagedPolicyChangeAction::REMOVE
                );
            }
        }

        $codeReferences = array_fill_keys(
            array_map(static fn(PermissionId $id): string => $id->toString(), $referencedPermissionIds),
            true
        );
        foreach ($this->permissionRepository->getManaged() as $permission) {
            $key = $permission->getId()->toString();
            if (isset($desiredPermissionIds[$key])) {
                continue;
            }

            if (isset($codeReferences[$key])) {
                throw new ManagedPolicyDefinitionException(sprintf(
                    'Managed permission "%s" cannot be removed because consumer code references it.',
                    $permission->getName()->toString()
                ));
            }

            foreach ($this->roleRepository->getContainingPermission($permission->getId()) as $role) {
                if (
                    !$role->isManaged()
                    || isset($desiredRoleIds[$role->getId()->toString()])
                ) {
                    $desired = $this->desiredRole($roles, $role);
                    if (
                        !$desired instanceof ManagedRoleDefinition
                        || $this->definitionContains($desired, $permission->getId())
                    ) {
                        throw new ManagedPolicyDefinitionException(sprintf(
                            'Managed permission "%s" cannot be removed because role "%s" references it.',
                            $permission->getName()->toString(),
                            $role->getName()->toString()
                        ));
                    }
                }
            }

            $permissionItems[] = new ManagedPermissionPlanItem(
                new ManagedPermissionDefinition(
                    $permission->getId(),
                    $permission->getName(),
                    $permission->getManagedTier()
                ),
                ManagedPolicyChangeAction::REMOVE
            );
        }

        usort($permissionItems, static fn(ManagedPermissionPlanItem $left, ManagedPermissionPlanItem $right): int =>
            $left->getDefinition()->getName()->toString() <=> $right->getDefinition()->getName()->toString());
        usort($roleItems, static fn(ManagedRolePlanItem $left, ManagedRolePlanItem $right): int =>
            $left->getDefinition()->getName()->toString() <=> $right->getDefinition()->getName()->toString());

        return new ManagedPolicyPlan($permissionItems, $roleItems);
    }

    /**
     * Plans one desired managed permission.
     */
    private function planPermission(ManagedPermissionDefinition $definition): ManagedPermissionPlanItem
    {
        $byId = $this->permissionRepository->getById($definition->getId());
        $byName = $this->permissionRepository->getByName($definition->getName());
        if ($byName instanceof Permission && !$byName->getId()->equals($definition->getId())) {
            throw new ManagedPolicyDefinitionException(sprintf(
                'Permission name "%s" belongs to persisted identifier "%s".',
                $definition->getName()->toString(),
                $byName->getId()->toString()
            ));
        }

        if ($byId instanceof Permission && !$byId->isManaged()) {
            throw new ManagedPolicyDefinitionException('A custom permission cannot be claimed by managed policy.');
        }

        $action = match (true) {
            !$byId instanceof Permission => ManagedPolicyChangeAction::CREATE,
            !$byId->getName()->equals($definition->getName()),
            $byId->getTier() !== $definition->getTier() => ManagedPolicyChangeAction::RECONCILE,
            default => ManagedPolicyChangeAction::UNCHANGED,
        };

        return new ManagedPermissionPlanItem($definition, $action);
    }

    /**
     * Plans one desired managed role.
     */
    private function planRole(ManagedRoleDefinition $definition): ManagedRolePlanItem
    {
        $byId = $this->roleRepository->getById($definition->getId());
        $byName = $this->roleRepository->getByName($definition->getName());
        if ($byName instanceof Role && !$byName->getId()->equals($definition->getId())) {
            throw new ManagedPolicyDefinitionException(sprintf(
                'Role name "%s" belongs to persisted identifier "%s".',
                $definition->getName()->toString(),
                $byName->getId()->toString()
            ));
        }

        if ($byId instanceof Role && !$byId->isManaged()) {
            throw new ManagedPolicyDefinitionException('A custom role cannot be claimed by managed policy.');
        }

        $action = match (true) {
            !$byId instanceof Role => ManagedPolicyChangeAction::CREATE,
            !$byId->getName()->equals($definition->getName()),
            !$this->sameMembership($byId, $definition) => ManagedPolicyChangeAction::RECONCILE,
            default => ManagedPolicyChangeAction::UNCHANGED,
        };

        return new ManagedRolePlanItem($definition, $action);
    }

    /** @phpstan-param list<ManagedRoleDefinition> $definitions */
    private function desiredRole(array $definitions, Role $role): ?ManagedRoleDefinition
    {
        foreach ($definitions as $definition) {
            if ($definition->getId()->equals($role->getId())) {
                return $definition;
            }
        }

        return null;
    }

    /**
     * Determines whether a desired role retains one permission.
     */
    private function definitionContains(ManagedRoleDefinition $role, PermissionId $permissionId): bool
    {
        return array_any(
            $role->getPermissionIds(),
            static fn(PermissionId $candidate): bool => $candidate->equals($permissionId)
        );
    }

    /**
     * Compares exact permission membership without depending on ordering.
     */
    private function sameMembership(Role $role, ManagedRoleDefinition $definition): bool
    {
        $current = array_map(static fn(PermissionId $id): string => $id->toString(), $role->getPermissionIds());
        $desired = array_map(static fn(PermissionId $id): string => $id->toString(), $definition->getPermissionIds());
        sort($current);
        sort($desired);

        return $current === $desired;
    }
}
