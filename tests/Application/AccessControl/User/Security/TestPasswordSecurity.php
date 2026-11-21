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

    public static function fixtureHash(string $password): string
    {
        // Keep real salted hashes and verification without exercising production work factors.
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]);
    }

    public function hash(string $password): string
    {
        return self::fixtureHash($password);
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
