<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\User;

use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
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

    /**
     * Reconstitutes a user at an explicit authentication version.
     */
    public static function withIdAndAuthenticationVersion(
        UserId $id,
        string $email,
        UserState $state,
        int $authenticationVersion
    ): User {
        return new self(
            $id,
            EmailAddress::fromString($email),
            $state,
            null,
            $authenticationVersion
        );
    }

    /**
     * Reconstitutes a user with persisted role-assignment authority.
     *
     * @phpstan-param list<RoleId> $roleIds
     */
    public static function withRoleAssignments(array $roleIds, int $authorizationAssignmentRevision): User
    {
        return new self(
            UserId::generate(),
            EmailAddress::fromString('reconstituted-role-assignments@example.test'),
            UserState::ACTIVE,
            null,
            1,
            0,
            $roleIds,
            $authorizationAssignmentRevision
        );
    }
}
