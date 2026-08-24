<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Role;

use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Role\Exception\ManagedRoleDefinitionException;

/**
 * Defines one version-controlled managed role and its exact permission membership.
 */
final readonly class ManagedRoleDefinition
{
    /**
     * Constructs a managed role definition.
     *
     * @phpstan-param list<PermissionId> $permissionIds
     */
    public function __construct(
        private RoleId $id,
        private RoleName $name,
        /** @var list<PermissionId> */
        private array $permissionIds
    ) {
        $seen = [];

        foreach ($permissionIds as $permissionId) {
            $key = $permissionId->toString();
            if (isset($seen[$key])) {
                throw new ManagedRoleDefinitionException(sprintf(
                    'Managed role "%s" contains duplicate permission "%s".',
                    $name->toString(),
                    $key
                ));
            }

            $seen[$key] = true;
        }
    }

    /**
     * Creates a definition from its serialized representation.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        foreach (['id', 'name', 'permission_ids'] as $key) {
            if (!array_key_exists($key, $data)) {
                throw new ManagedRoleDefinitionException(sprintf(
                    'Missing required managed role key "%s".',
                    $key
                ));
            }
        }

        if (!is_array($data['permission_ids'])) {
            throw new ManagedRoleDefinitionException('Managed role permission_ids must be an array.');
        }

        return new self(
            RoleId::fromString((string) $data['id']),
            RoleName::fromString((string) $data['name']),
            array_map(
                static fn(mixed $permissionId): PermissionId => PermissionId::fromString((string) $permissionId),
                array_values($data['permission_ids'])
            )
        );
    }

    /**
     * Returns the stable role identifier.
     */
    public function getId(): RoleId
    {
        return $this->id;
    }

    /**
     * Returns the canonical role name.
     */
    public function getName(): RoleName
    {
        return $this->name;
    }

    /**
     * Returns the exact managed permission membership.
     *
     * @return list<PermissionId>
     */
    public function getPermissionIds(): array
    {
        return $this->permissionIds;
    }

    /**
     * Returns the serialized definition.
     *
     * @return array{id: string, name: string, permission_ids: list<string>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id->toString(),
            'name' => $this->name->toString(),
            'permission_ids' => array_map(
                static fn(PermissionId $permissionId): string => $permissionId->toString(),
                $this->permissionIds
            ),
        ];
    }
}
