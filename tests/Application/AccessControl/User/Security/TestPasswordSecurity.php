<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User\Security;

use Fight\Common\Application\Auth\Security\PasswordHasher;
use Fight\Common\Application\Auth\Security\PasswordValidator;

final readonly class TestPasswordSecurity implements PasswordHasher, PasswordValidator
{
    public function __construct(private bool $rehash = false)
    {
    }

    public function hash(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    public function validate(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return $this->rehash;
    }
}
