<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\Identity;

use Closure;
use Fight\AccessControl\Domain\Identity\EmailAddress;
use Fight\AccessControl\Domain\Identity\User;
use Fight\AccessControl\Domain\Identity\UserId;
use Fight\AccessControl\Domain\Identity\UserState;

/**
 * Reconstitutes lifecycle-state fixtures without adding production lifecycle commands.
 */
final class UserFixture
{
    /**
     * Creates a user fixture in the requested lifecycle state.
     */
    public static function withState(string $email, UserState $state): User
    {
        $canonicalEmail = User::invite($email)->email();
        $reconstitute = Closure::bind(
            static fn(
                EmailAddress $canonicalEmail,
                UserState $state
            ): User => new User(UserId::fromString('user-fixture'), $canonicalEmail, $state),
            null,
            User::class
        );

        return $reconstitute($canonicalEmail, $state);
    }
}
