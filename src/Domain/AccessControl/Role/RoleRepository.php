<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Role;

use Exception;
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
}
