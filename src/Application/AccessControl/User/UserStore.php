<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\User;

use Fight\AccessControl\Domain\AccessControl\User\User;

/**
 * Atomically reserves canonical email addresses for user identities.
 */
interface UserStore
{
    /**
     * Atomically stages a user only when its canonical email remains unreserved.
     *
     * A false result means another identity already owns the reservation.
     */
    public function reserve(User $user): bool;
}
