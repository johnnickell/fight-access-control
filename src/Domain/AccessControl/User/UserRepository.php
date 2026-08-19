<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\User;

use Exception;

/**
 * Interface UserRepository
 */
interface UserRepository
{
    /**
     * Retrieves a user by its stable identifier.
     *
     * @throws Exception When an error occurs
     */
    public function getById(UserId $id): ?User;

    /**
     * Adds a User.
     *
     * Implementations must reject a duplicate canonical email atomically.
     *
     * @throws Exception When an error occurs
     */
    public function add(User $user): void;
}
