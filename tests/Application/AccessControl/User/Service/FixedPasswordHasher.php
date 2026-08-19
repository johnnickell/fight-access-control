<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User\Service;

use Fight\Common\Application\Auth\Security\PasswordHasher;
use RuntimeException;

final readonly class FixedPasswordHasher implements PasswordHasher
{
    public function __construct(private ?RuntimeException $failure = null)
    {
    }

    public function hash(string $password): string
    {
        if ($this->failure instanceof RuntimeException) {
            throw $this->failure;
        }

        return 'hash:'.$password;
    }
}
