<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Role;

use Exception;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\Common\Domain\Repository\Pagination;
use Fight\Common\Domain\Repository\ResultSet;

/**
 * Interface RoleRepository
 *
 * Provides authoritative role persistence.
 */
interface RoleRepository
{
    /**
     * Adds a role
     *
     * Implementations atomically reject duplicate Role IDs and canonical names as well as membership whose
     * Permission is no longer authoritative. Validation and mutation occur under adapter-owned fences held through
     * the enclosing Unit of Work.
     *
     * @throws Exception When an error occurs
     */
    public function add(Role $role): void;

    /**
     * Retrieves a role by its stable identifier
     *
     * The returned Role must have the requested ID; conflicting stored identities fail closed.
     *
     * @throws Exception When an error occurs
     */
    public function getById(RoleId $id): ?Role;

    /**
     * Retrieves the unique authoritative role by its canonical name
     *
     * Duplicate stored names and mismatched name-index results must fail closed, not select an arbitrary Role.
     *
     * @throws Exception When an error occurs
     */
    public function getByName(RoleName $name): ?Role;

    /**
     * Retrieves every resolvable role for the requested identifiers without pagination
     *
     * Conflicting stored IDs or names must fail closed, not return an arbitrary Role.
     *
     * @phpstan-param list<RoleId> $ids
     *
     * @return list<Role>
     *
     * @throws Exception When an error occurs
     */
    public function getByIds(array $ids): array;

    /**
     * Retrieves one page of roles
     *
     * @return ResultSet<Role>
     *
     * @throws Exception When an error occurs
     */
    public function getAll(Pagination $pagination): ResultSet;

    /**
     * Returns managed Roles
     *
     * @return list<Role>
     */
    public function getManaged(): array;

    /**
     * Returns Roles containing a Permission
     *
     * @return list<Role>
     */
    public function getContainingPermission(PermissionId $id): array;

    /**
     * Validates one Permission reference under the transaction-duration permission-reference fence
     *
     * Returns false when the Permission is no longer authoritative.
     *
     * @throws Exception When an error occurs
     */
    public function validatePermissionReference(PermissionId $permissionId): bool;

    /**
     * Replaces the expected role when it remains current and all replacement Permissions remain authoritative
     *
     * Validation and mutation occur under adapter-owned permission-reference and unique-name fences held through
     * the enclosing Unit of Work and shared with PermissionRepository::remove().
     */
    public function replace(Role $expected, Role $replacement): bool;

    /**
     * Removes the expected Role atomically only when it remains current and unassigned
     *
     * Validation and mutation occur under one adapter-owned role-reference fence held through the enclosing Unit of
     * Work and shared with UserRepository::replaceRoleAssignments(). Returns false when changed or assigned.
     */
    public function remove(Role $role): bool;
}
