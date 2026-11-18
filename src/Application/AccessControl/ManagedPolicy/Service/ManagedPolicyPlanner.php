<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\ManagedPolicy\Service;

use Fight\AccessControl\Domain\AccessControl\Agent\AgentRepository;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\ManagedPermissionDefinition;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\ManagedPermissionPlanItem;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\ManagedPolicy;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\ManagedPolicyChangeAction;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\ManagedPolicyPlan;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\ManagedRoleDefinition;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\ManagedRolePlanItem;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionRepository;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionTier;
use Fight\AccessControl\Domain\AccessControl\Role\Role;
use Fight\AccessControl\Domain\AccessControl\Role\RoleName;
use Fight\AccessControl\Domain\AccessControl\Role\RoleRepository;
use Fight\AccessControl\Domain\AccessControl\User\UserRepository;
use Fight\Common\Application\Error\Exception\RuleViolation;
use UnexpectedValueException;

/**
 * Class ManagedPolicyPlanner
 *
 * Produces the complete deterministic plan shared by preview and apply.
 */
final readonly class ManagedPolicyPlanner
{
    /**
     * Constructs ManagedPolicyPlanner
     *
     * Creates the shared managed-policy planner.
     */
    public function __construct(
        private PermissionRepository $permissionRepository,
        private RoleRepository $roleRepository,
        private UserRepository $userRepository,
        private AgentRepository $agentRepository
    ) {
    }

    /**
     * Builds the complete desired managed policy against authoritative state
     */
    public function plan(ManagedPolicy $policy): ManagedPolicyPlan
    {
        $permissions = $policy->getPermissions();
        $roles = $policy->getRoles();
        $referencedPermissionIds = $policy->getReferencedPermissionIds();
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

        foreach ($this->validateRoles($this->roleRepository->getManaged()) as $role) {
            if (!isset($desiredRoleIds[$role->getId()->toString()])) {
                if ($this->userRepository->hasRoleAssignment($role->getId())) {
                    throw new RuleViolation(sprintf(
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
        foreach ($this->validateManagedPermissions($this->permissionRepository->getManaged()) as $permission) {
            $key = $permission->getId()->toString();
            if (isset($desiredPermissionIds[$key])) {
                continue;
            }

            if (isset($codeReferences[$key])) {
                throw new RuleViolation(sprintf(
                    'Managed permission "%s" cannot be removed because consumer code references it.',
                    $permission->getName()->toString()
                ));
            }

            if ($this->permissionRepository->hasFeatureReference($permission->getId())) {
                throw new RuleViolation(sprintf(
                    'Managed permission "%s" cannot be removed because a Feature references it.',
                    $permission->getName()->toString()
                ));
            }

            if ($this->agentRepository->hasPermissionAssignment($permission->getId())) {
                throw new RuleViolation(sprintf(
                    'Managed permission "%s" cannot be removed because an Agent references it.',
                    $permission->getName()->toString()
                ));
            }

            foreach (
                $this->validateRoles(
                    $this->roleRepository->getContainingPermission($permission->getId()),
                    $permission->getId()
                ) as $role
            ) {
                if (
                    !$role->isManaged()
                    || isset($desiredRoleIds[$role->getId()->toString()])
                ) {
                    $desired = $this->desiredRole($roles, $role);
                    if (
                        !$desired instanceof ManagedRoleDefinition
                        || $this->definitionContains($desired, $permission->getId())
                    ) {
                        throw new RuleViolation(sprintf(
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
     * Builds one desired managed permission
     */
    private function planPermission(ManagedPermissionDefinition $definition): ManagedPermissionPlanItem
    {
        $byId = $this->permissionRepository->getById($definition->getId());
        $byName = $this->permissionRepository->getByName($definition->getName());
        if ($byId !== null && !$byId->getId()->equals($definition->getId())) {
            throw new UnexpectedValueException('Permission lookup returned a different identity.');
        }

        if ($byName !== null && !$byName->getName()->equals($definition->getName())) {
            throw new UnexpectedValueException('Permission lookup returned a different name.');
        }

        if ($byName instanceof Permission && !$byName->getId()->equals($definition->getId())) {
            throw new RuleViolation(sprintf(
                'Permission name "%s" belongs to persisted identifier "%s".',
                $definition->getName()->toString(),
                $byName->getId()->toString()
            ));
        }

        if ($byId instanceof Permission && !$byId->isManaged()) {
            throw new RuleViolation('A custom permission cannot be claimed by managed policy.');
        }

        if (
            $byId instanceof Permission
            && $byId->getTier() !== PermissionTier::SUPER_ADMIN_ONLY
            && $definition->getTier() === PermissionTier::SUPER_ADMIN_ONLY
        ) {
            foreach (
                $this->validateRoles(
                    $this->roleRepository->getContainingPermission($definition->getId()),
                    $definition->getId()
                ) as $role
            ) {
                if (!$role->isManaged() || $role->getName()->toString() !== Role::SUPER_ADMIN_NAME) {
                    throw new RuleViolation('Protected promotion conflicts with Role membership.');
                }
            }

            if ($this->agentRepository->hasPermissionAssignment($definition->getId())) {
                throw new RuleViolation('Protected promotion conflicts with Agent membership.');
            }
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
     * Builds one desired managed role
     */
    private function planRole(ManagedRoleDefinition $definition): ManagedRolePlanItem
    {
        $byId = $this->roleRepository->getById($definition->getId());
        $byName = $this->roleRepository->getByName($definition->getName());
        if ($byId !== null && !$byId->getId()->equals($definition->getId())) {
            throw new UnexpectedValueException('Role lookup returned a different identity.');
        }

        if ($byName !== null && !$byName->getName()->equals($definition->getName())) {
            throw new UnexpectedValueException('Role lookup returned a different name.');
        }

        $designated = $this->roleRepository->getByName(RoleName::fromString(Role::SUPER_ADMIN_NAME));
        $byId?->assertConsistentWithSuperAdmin($designated);
        $byName?->assertConsistentWithSuperAdmin($designated);
        if ($byName instanceof Role && !$byName->getId()->equals($definition->getId())) {
            throw new RuleViolation(sprintf(
                'Role name "%s" belongs to persisted identifier "%s".',
                $definition->getName()->toString(),
                $byName->getId()->toString()
            ));
        }

        if ($byId instanceof Role && !$byId->isManaged()) {
            throw new RuleViolation('A custom role cannot be claimed by managed policy.');
        }

        $action = match (true) {
            !$byId instanceof Role => ManagedPolicyChangeAction::CREATE,
            !$byId->getName()->equals($definition->getName()),
            !$this->sameMembership($byId, $definition) => ManagedPolicyChangeAction::RECONCILE,
            default => ManagedPolicyChangeAction::UNCHANGED,
        };

        return new ManagedRolePlanItem($definition, $action);
    }

    /**
     * Returns the complete validated managed Permission inventory
     *
     * @param array<array-key, mixed> $permissions
     *
     * @return list<Permission>
     */
    private function validateManagedPermissions(array $permissions): array
    {
        if (!array_is_list($permissions)) {
            throw new UnexpectedValueException('Managed Permission inventory is not a list.');
        }

        $ids = [];
        $names = [];
        foreach ($permissions as $permission) {
            if (!$permission instanceof Permission || !$permission->isManaged()) {
                throw new UnexpectedValueException('Managed Permission inventory contains an invalid member.');
            }

            $id = $permission->getId()->toString();
            $name = $permission->getName()->toString();
            if (isset($ids[$id]) || isset($names[$name])) {
                throw new UnexpectedValueException('Managed Permission inventory contains duplicate identities.');
            }

            $ids[$id] = true;
            $names[$name] = true;
        }

        return $permissions;
    }

    /**
     * Returns validated managed Roles or exact Permission references
     *
     * @param array<array-key, mixed> $roles
     *
     * @return list<Role>
     */
    private function validateRoles(array $roles, ?PermissionId $permissionId = null): array
    {
        if (!array_is_list($roles)) {
            throw new UnexpectedValueException('Role inventory is not a list.');
        }

        $designated = null;
        if ($roles !== []) {
            $designated = $this->roleRepository->getByName(RoleName::fromString(Role::SUPER_ADMIN_NAME));
        }

        $ids = [];
        $names = [];
        foreach ($roles as $role) {
            if (!$role instanceof Role) {
                throw new UnexpectedValueException('Role inventory contains an invalid member.');
            }

            $role->assertConsistentWithSuperAdmin($designated);
            if ($permissionId === null && !$role->isManaged()) {
                throw new UnexpectedValueException('Managed Role inventory contains a custom Role.');
            }

            if (
                $permissionId !== null && !array_any(
                    $role->getPermissionIds(),
                    static fn(PermissionId $id): bool => $id->equals($permissionId)
                )
            ) {
                throw new UnexpectedValueException('Role reference inventory contains an unrelated Role.');
            }

            $id = $role->getId()->toString();
            $name = $role->getName()->toString();
            if (isset($ids[$id]) || isset($names[$name])) {
                throw new UnexpectedValueException('Role inventory contains duplicate identities.');
            }

            $ids[$id] = true;
            $names[$name] = true;
        }

        return $roles;
    }

    /**
     * Finds the definition for a managed Role
     *
     * @phpstan-param list<ManagedRoleDefinition> $definitions
     */
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
     * Determines whether a desired role retains one permission
     */
    private function definitionContains(ManagedRoleDefinition $role, PermissionId $permissionId): bool
    {
        return array_any(
            $role->getPermissionIds(),
            static fn(PermissionId $candidate): bool => $candidate->equals($permissionId)
        );
    }

    /**
     * Checks exact permission membership without depending on ordering
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
