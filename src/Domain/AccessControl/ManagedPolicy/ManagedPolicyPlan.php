<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\ManagedPolicy;

use Fight\Common\Domain\Type\Arrayable;

/**
 * Class ManagedPolicyPlan
 *
 * Provides an immutable deterministic managed-policy reconciliation plan.
 */
final readonly class ManagedPolicyPlan implements Arrayable
{
    /**
     * Constructs ManagedPolicyPlan
     *
     * Constructs a managed-policy plan.
     *
     * @phpstan-param list<ManagedPermissionPlanItem> $permissions
     * @phpstan-param list<ManagedRolePlanItem> $roles
     */
    public function __construct(
        /** @var list<ManagedPermissionPlanItem> */
        private array $permissions,
        /** @var list<ManagedRolePlanItem> */
        private array $roles
    ) {
    }

    /**
     * Returns managed Permission plan items
     *
     * @return list<ManagedPermissionPlanItem>
     */
    public function getPermissions(): array
    {
        return $this->permissions;
    }

    /**
     * Returns managed Role plan items
     *
     * @return list<ManagedRolePlanItem>
     */
    public function getRoles(): array
    {
        return $this->roles;
    }

    /**
     * Returns the serialized plan
     *
     * @return array{permissions: list<array<string, mixed>>, roles: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'permissions' => array_map(
                static fn(ManagedPermissionPlanItem $item): array => [
                    ...$item->getDefinition()->toArray(),
                    'action' => $item->getAction()->value
                ],
                $this->permissions
            ),
            'roles'       => array_map(
                static fn(ManagedRolePlanItem $item): array => [
                    ...$item->getDefinition()->toArray(),
                    'action' => $item->getAction()->value
                ],
                $this->roles
            )
        ];
    }
}
