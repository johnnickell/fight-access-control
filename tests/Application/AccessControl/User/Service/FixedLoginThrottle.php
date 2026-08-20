<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User\Service;

use Fight\AccessControl\Application\AccessControl\User\Service\LoginThrottle;
use Fight\Common\Domain\Value\Internet\EmailAddress;

final readonly class FixedLoginThrottle implements LoginThrottle
{
    public function __construct(private bool $allowed)
    {
    }

    public function allows(EmailAddress $email): bool
    {
        return $this->allowed;
    }
}
