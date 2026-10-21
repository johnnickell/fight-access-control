<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\PasswordResetGrant\Repository;

use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrant;

final class InMemoryPasswordResetGrantState
{
    /** @var list<PasswordResetGrant> */
    public array $grants = [];
}
