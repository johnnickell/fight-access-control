<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\User;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\User\PasswordHash;
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
        $now = new DateTimeImmutable('2026-01-01T00:00:00+00:00');

        return new self(UserId::generate(), EmailAddress::fromString($email), $state, createdAt: $now, updatedAt: $now);
    }

    /**
     * Reconstitutes inconsistent pending registration state for fail-closed boundary tests
     */
    public static function pendingRegistrationState(
        User $user,
        ?PasswordHash $passwordHash,
        ?EmailAddress $pendingEmailChange
    ): User {
        return new self(
            $user->getId(),
            $user->getEmail(),
            UserState::PENDING_ACTIVATION,
            $user->getCreatedAt(),
            $user->getUpdatedAt(),
            $passwordHash,
            roleIds: $user->getRoleIds(),
            pendingEmailChange: $pendingEmailChange
        );
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
        $now = new DateTimeImmutable('2026-01-01T00:00:00+00:00');

        return new self(
            $id,
            EmailAddress::fromString($email),
            $state,
            $now,
            $now,
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
        $now = new DateTimeImmutable('2026-01-01T00:00:00+00:00');

        return new self(
            UserId::generate(),
            EmailAddress::fromString('reconstituted-role-assignments@example.test'),
            UserState::ACTIVE,
            $now,
            $now,
            null,
            1,
            0,
            $roleIds,
            $authorizationAssignmentRevision
        );
    }
}
