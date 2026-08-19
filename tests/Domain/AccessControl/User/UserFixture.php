<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\User;

use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Domain\Value\Internet\EmailAddress;

/**
 * Reconstitutes lifecycle-state fixtures without adding production lifecycle commands.
 */
final class UserFixture extends User
{
    /**
     * Creates a user fixture in the requested lifecycle state.
     */
    public static function withState(string $email, UserState $state): User
    {
        return new self(UserId::generate(), EmailAddress::fromString($email), $state);
    }
}
