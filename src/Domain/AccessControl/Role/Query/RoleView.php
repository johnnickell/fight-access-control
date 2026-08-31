<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Role\Query;

use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Role\Role;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\Role\RoleName;
use Fight\Common\Domain\Type\Arrayable;

/**
 * Provides a safe immutable view of a role.
 */
final readonly class RoleView implements Arrayable
{
    /**
     * Constructs the safe role view.
     *
     * @phpstan-param list<PermissionId> $permissionIds
     */
    public function __construct(
        private RoleId $roleId,
        private RoleName $name,
        private bool $managed,
        /** @var list<PermissionId> */
        private array $permissionIds
    ) {
    }

    /**
     * Creates a safe view without exposing aggregate or persistence authority.
     */
    public static function fromRole(Role $role): self
    {
        return new self(
            $role->getId(),
            $role->getName(),
            $role->isManaged(),
            $role->getPermissionIds()
        );
    }

    /**
     * Returns the stable role identifier.
     */
    public function getRoleId(): RoleId
    {
        return $this->roleId;
    }

    /**
     * Returns the canonical role name.
     */
    public function getName(): RoleName
    {
        return $this->name;
    }

    /**
     * Returns whether version-controlled policy owns this role.
     */
    public function isManaged(): bool
    {
        return $this->managed;
    }

    /**
     * Returns an immutable snapshot of permission membership.
     *
     * @return list<PermissionId>
     */
    public function getPermissionIds(): array
    {
        return $this->permissionIds;
    }

    /**
     * Returns the canonical safe array representation.
     *
     * @return array{
     *     role_id: string,
     *     name: string,
     *     managed: bool,
     *     permission_ids: list<string>
     * }
     */
    public function toArray(): array
    {
        return [
            'role_id' => $this->roleId->toString(),
            'name' => $this->name->toString(),
            'managed' => $this->managed,
            'permission_ids' => array_map(
                static fn(PermissionId $permissionId): string => $permissionId->toString(),
                $this->permissionIds
            ),
        ];
    }
}
