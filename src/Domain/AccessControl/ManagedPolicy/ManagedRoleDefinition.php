<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\ManagedPolicy;

use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\Exception\ManagedPolicyDefinitionException;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\Role\RoleName;
use Throwable;

/**
 * Class ManagedRoleDefinition
 *
 * Defines one version-controlled managed role and its exact permission membership.
 */
final readonly class ManagedRoleDefinition
{
    /**
     * Constructs ManagedRoleDefinition
     *
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
                throw new ManagedPolicyDefinitionException(sprintf(
                    'Managed role "%s" contains duplicate permission "%s".',
                    $name->toString(),
                    $key
                ));
            }

            $seen[$key] = true;
        }
    }

    /**
     * Creates a definition from its serialized representation
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        foreach (['id', 'name', 'permission_ids'] as $key) {
            if (!array_key_exists($key, $data)) {
                throw new ManagedPolicyDefinitionException(sprintf(
                    'Missing required managed role key "%s".',
                    $key
                ));
            }
        }

        if (!is_string($data['id']) || !is_string($data['name'])) {
            throw new ManagedPolicyDefinitionException('Managed role id and name must be strings.');
        }

        if (!is_array($data['permission_ids']) || !array_is_list($data['permission_ids'])) {
            throw new ManagedPolicyDefinitionException('Managed role permission_ids must be a list.');
        }

        foreach ($data['permission_ids'] as $permissionId) {
            if (!is_string($permissionId)) {
                throw new ManagedPolicyDefinitionException('Managed role permission identifiers must be strings.');
            }
        }

        try {
            return new self(
                RoleId::fromString($data['id']),
                RoleName::fromString($data['name']),
                array_map(
                    PermissionId::fromString(...),
                    $data['permission_ids']
                )
            );
        } catch (Throwable $throwable) {
            throw new ManagedPolicyDefinitionException(
                'Invalid managed role definition: '.$throwable->getMessage(),
                0,
                $throwable
            );
        }
    }

    /**
     * Returns the stable role identifier
     */
    public function getId(): RoleId
    {
        return $this->id;
    }

    /**
     * Returns the canonical role name
     */
    public function getName(): RoleName
    {
        return $this->name;
    }

    /**
     * Returns the exact managed permission membership
     *
     * @return list<PermissionId>
     */
    public function getPermissionIds(): array
    {
        return $this->permissionIds;
    }

    /**
     * Returns the serialized definition
     *
     * @return array{id: string, name: string, permission_ids: list<string>}
     */
    public function toArray(): array
    {
        return [
            'id'             => $this->id->toString(),
            'name'           => $this->name->toString(),
            'permission_ids' => array_map(
                static fn(PermissionId $permissionId): string => $permissionId->toString(),
                $this->permissionIds
            )
        ];
    }
}
