<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User\Service;

use Fight\AccessControl\Application\AccessControl\User\Service\PasswordVerifier;
use Fight\AccessControl\Domain\AccessControl\User\PasswordHash;

final class HashPasswordVerifier implements PasswordVerifier
{
    private const string DUMMY_PASSWORD_HASH = '$2y$12$issugJljKAmsG2Nbc9X7/.B/87D141zg8uDG4QFn2/A7FCnLRHfm6';

    public function matchesDummy(string $secret): bool
    {
        return password_verify($secret, self::DUMMY_PASSWORD_HASH);
    }

    public function matches(string $secret, PasswordHash $passwordHash): bool
    {
        return password_verify($secret, $passwordHash->toString());
    }
}
