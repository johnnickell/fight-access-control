<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\ManagedPolicy;

use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\Exception\ManagedPolicyDefinitionException;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;

/**
 * Owns one complete version-controlled authorization policy.
 */
final readonly class ManagedPolicy
{
    /**
     * @phpstan-param list<ManagedPermissionDefinition> $permissions
     * @phpstan-param list<ManagedRoleDefinition> $roles
     * @phpstan-param list<PermissionId> $referencedPermissionIds
     */
    public function __construct(
        /** @var list<ManagedPermissionDefinition> */
        private array $permissions,
        /** @var list<ManagedRoleDefinition> */
        private array $roles,
        /** @var list<PermissionId> */
        private array $referencedPermissionIds = []
    ) {
        $permissionIds = $this->assertUniqueDefinitions($permissions, 'permission');
        $this->assertUniqueDefinitions($roles, 'role');

        foreach ($roles as $role) {
            foreach ($role->getPermissionIds() as $permissionId) {
                if (!isset($permissionIds[$permissionId->toString()])) {
                    throw new ManagedPolicyDefinitionException(sprintf(
                        'Managed role "%s" references unknown managed permission "%s".',
                        $role->getName()->toString(),
                        $permissionId->toString()
                    ));
                }
            }
        }

        $references = [];
        foreach ($referencedPermissionIds as $permissionId) {
            $key = $permissionId->toString();
            if (isset($references[$key])) {
                throw new ManagedPolicyDefinitionException(sprintf(
                    'Consumer code permission reference "%s" is duplicated.',
                    $key
                ));
            }

            $references[$key] = true;
        }
    }

    /**
     * Creates a policy from its serialized representation.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        foreach (['permissions', 'roles', 'referenced_permission_ids'] as $key) {
            if (!array_key_exists($key, $data)) {
                throw new ManagedPolicyDefinitionException(sprintf(
                    'Missing required managed policy key "%s".',
                    $key
                ));
            }

            if (!is_array($data[$key])) {
                throw new ManagedPolicyDefinitionException(sprintf(
                    'Managed policy %s must be an array.',
                    $key
                ));
            }
        }

        return new self(
            array_map(
                static fn(mixed $definition): ManagedPermissionDefinition =>
                    ManagedPermissionDefinition::fromArray((array) $definition),
                array_values($data['permissions'])
            ),
            array_map(
                static fn(mixed $definition): ManagedRoleDefinition => ManagedRoleDefinition::fromArray(
                    (array) $definition
                ),
                array_values($data['roles'])
            ),
            array_map(
                static fn(mixed $id): PermissionId => PermissionId::fromString((string) $id),
                array_values($data['referenced_permission_ids'])
            )
        );
    }

    /** @return list<ManagedPermissionDefinition> */
    public function getPermissions(): array
    {
        return $this->permissions;
    }

    /** @return list<ManagedRoleDefinition> */
    public function getRoles(): array
    {
        return $this->roles;
    }

    /** @return list<PermissionId> */
    public function getReferencedPermissionIds(): array
    {
        return $this->referencedPermissionIds;
    }

    /**
     * Returns the canonical serialized policy.
     *
     * @return array{
     *     permissions: list<array{id: string, name: string, tier: string}>,
     *     roles: list<array{id: string, name: string, permission_ids: list<string>}>,
     *     referenced_permission_ids: list<string>
     * }
     */
    public function toArray(): array
    {
        return [
            'permissions' => array_map(
                static fn(ManagedPermissionDefinition $definition): array => $definition->toArray(),
                $this->permissions
            ),
            'roles' => array_map(
                static fn(ManagedRoleDefinition $definition): array => $definition->toArray(),
                $this->roles
            ),
            'referenced_permission_ids' => array_map(
                static fn(PermissionId $id): string => $id->toString(),
                $this->referencedPermissionIds
            ),
        ];
    }

    /**
     * Rejects duplicate stable identities and canonical names.
     *
     * @param list<ManagedPermissionDefinition>|list<ManagedRoleDefinition> $definitions
     *
     * @return array<string, true>
     */
    private function assertUniqueDefinitions(array $definitions, string $type): array
    {
        $ids = [];
        $names = [];

        foreach ($definitions as $definition) {
            $id = $definition->getId()->toString();
            $name = $definition->getName()->toString();

            if (isset($ids[$id])) {
                throw new ManagedPolicyDefinitionException(sprintf(
                    'Managed %s identifier "%s" is duplicated.',
                    $type,
                    $id
                ));
            }

            if (isset($names[$name])) {
                throw new ManagedPolicyDefinitionException(sprintf(
                    'Managed %s name "%s" is duplicated.',
                    $type,
                    $name
                ));
            }

            $ids[$id] = true;
            $names[$name] = true;
        }

        return $ids;
    }
}
