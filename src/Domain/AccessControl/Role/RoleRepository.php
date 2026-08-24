<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Role;

use Exception;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\Common\Domain\Repository\Pagination;
use Fight\Common\Domain\Repository\ResultSet;

/**
 * Provides authoritative role persistence.
 */
interface RoleRepository
{
    /**
     * Adds a role.
     *
     * Implementations atomically reject any membership whose Permission is no longer authoritative. Validation and
     * mutation occur under one adapter-owned fence held through the enclosing Unit of Work.
     *
     * @throws Exception When an error occurs
     */
    public function add(Role $role): void;

    /**
     * Retrieves a role by its stable identifier.
     *
     * @throws Exception When an error occurs
     */
    public function getById(RoleId $id): ?Role;

    /**
     * Retrieves a role by its canonical name.
     *
     * @throws Exception When an error occurs
     */
    public function getByName(RoleName $name): ?Role;

    /**
     * Retrieves every resolvable role for the requested identifiers without pagination.
     *
     * @phpstan-param list<RoleId> $ids
     *
     * @return list<Role>
     *
     * @throws Exception When an error occurs
     */
    public function getByIds(array $ids): array;

    /**
     * Retrieves one page of roles.
     *
     * @throws Exception When an error occurs
     */
    public function getAll(Pagination $pagination): ResultSet;

    /** @return list<Role> */
    public function getManaged(): array;

    /** @return list<Role> */
    public function getContainingPermission(PermissionId $id): array;

    /**
     * Replaces the expected role when it remains current and all replacement Permissions remain authoritative.
     *
     * Validation and mutation occur under one adapter-owned permission-reference fence held through the enclosing
     * Unit of Work and shared with PermissionRepository::remove().
     */
    public function replace(Role $expected, Role $replacement): bool;

    /**
     * Atomically removes the expected Role only when it remains current and unassigned.
     *
     * Validation and mutation occur under one adapter-owned role-reference fence held through the enclosing Unit of
     * Work and shared with UserRepository::replaceRoleAssignments(). Returns false when changed or assigned.
     */
    public function remove(Role $role): bool;
}
