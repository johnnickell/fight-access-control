<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Permission;

use Exception;
use Fight\Common\Domain\Repository\Pagination;
use Fight\Common\Domain\Repository\ResultSet;

/**
 * Provides authoritative permission persistence.
 */
interface PermissionRepository
{
    /**
     * Adds a permission.
     *
     * @throws Exception When an error occurs
     */
    public function add(Permission $permission): void;

    /**
     * Retrieves a permission by its stable identifier.
     *
     * @throws Exception When an error occurs
     */
    public function getById(PermissionId $id): ?Permission;

    /**
     * Retrieves a permission by its canonical name.
     *
     * @throws Exception When an error occurs
     */
    public function getByName(PermissionName $name): ?Permission;

    /**
     * Retrieves every resolvable permission for the requested identifiers without pagination.
     *
     * @phpstan-param list<PermissionId> $ids
     *
     * @return list<Permission>
     *
     * @throws Exception When an error occurs
     */
    public function getByIds(array $ids): array;

    /**
     * Retrieves one page of permissions.
     *
     * @throws Exception When an error occurs
     */
    public function getAll(Pagination $pagination): ResultSet;

    /** @return list<Permission> */
    public function getManaged(): array;

    /**
     * Replaces the expected permission when it remains current.
     */
    public function replace(Permission $expected, Permission $replacement): bool;

    /**
     * Atomically removes the expected Permission only when it remains current and unreferenced.
     *
     * Validation and mutation occur under one adapter-owned permission-reference fence held through the enclosing
     * Unit of Work and shared with RoleRepository reference-changing writes. Returns false when changed or referenced.
     */
    public function remove(Permission $permission): bool;
}
