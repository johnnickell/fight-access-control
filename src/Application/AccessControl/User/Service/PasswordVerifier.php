<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\User\Service;

use Fight\AccessControl\Domain\AccessControl\User\PasswordHash;

/**
 * Verifies a submitted secret against a stored password hash.
 */
interface PasswordVerifier
{
    /**
     * Verifies a submitted secret against an application-owned dummy hash.
     */
    public function matchesDummy(string $secret): bool;

    /**
     * Determines whether the submitted secret matches the stored hash.
     */
    public function matches(string $secret, PasswordHash $passwordHash): bool;
}
