<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User\Repository;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshCredential;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSession;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionId;
use Fight\AccessControl\Domain\AccessControl\Role\Role;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\Role\RoleName;
use Fight\AccessControl\Domain\AccessControl\User\PasswordHash;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use Fight\Test\AccessControl\Application\AccessControl\RefreshSession\Repository\InMemoryRefreshSessionRepository;
use Fight\Test\AccessControl\Application\AccessControl\Role\Repository\InMemoryRoleRepository;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversNothing]
final class UserRepositoryTimestampContractTest extends TestCase
{
    /** @phpstan-return iterable<string, array{string, string}> */
    public static function transitions(): iterable
    {
        foreach (
            [
            'authentication', 'coupled', 'roles', 'reservation', 'confirmation',
            'invitation', 'registration', 'lifecycle', 'recovery validation'
            ] as $transition
        ) {
            foreach (['unchanged', 'expected creation', 'expected update', 'replacement creation'] as $mismatch) {
                if ($transition === 'recovery validation' && $mismatch === 'replacement creation') {
                    continue;
                }

                yield $transition.' '.$mismatch => [$transition, $mismatch];
            }
        }
    }

    #[DataProvider('transitions')]
    public function test_expected_state_comparison_preserves_subsecond_timestamp_values(
        string $transition,
        string $mismatch
    ): void {
        $now = new DateTimeImmutable('2026-11-05T00:00:00.000001+00:00');
        $later = $now->modify('+1 hour');
        $user = User::invite(UserId::generate(), EmailAddress::fromString('timestamps@example.test'), $now);
        if (!in_array($transition, ['invitation', 'registration'], true)) {
            $user->activate(PasswordHash::fromString(password_hash('fixture-password', PASSWORD_DEFAULT)), $now);
        }

        if ($transition === 'confirmation') {
            $user->requestEmailChange(EmailAddress::fromString('pending@example.test'), $now);
        }

        $references = new InMemoryAuthorizationReferenceState();
        $repository = new InMemoryUserRepository(authorizationReferences: $references);
        $sessions = new InMemoryRefreshSessionRepository();
        $repository->bindRefreshSessionRepository($sessions);
        $roles = new InMemoryRoleRepository(authorizationReferences: $references);
        $roleId = RoleId::generate();
        $roles->add(Role::define($roleId, RoleName::fromString('ROLE_TIMESTAMP'), [], $now));
        $repository->add($user);
        $expected = $user;
        if ($mismatch === 'expected creation' || $mismatch === 'expected update') {
            $expected = self::withTimestamps(
                $user,
                $mismatch === 'expected creation' ? $now->modify('+1 microsecond') : $now,
                $mismatch === 'expected update' ? $now->modify('+1 microsecond') : $now
            );
        }

        $replacement = clone $expected;
        $newEmail = EmailAddress::fromString('new@example.test');
        match ($transition) {
            'authentication', 'coupled' => $replacement->recordSuccessfulLogin($later),
            'roles' => $replacement->assignRole($roleId, $later),
            'reservation' => $replacement->requestEmailChange(EmailAddress::fromString('pending@example.test'), $later),
            'confirmation' => $replacement->confirmEmailChange($later),
            'invitation' => $replacement->correctPendingInvitationEmail($newEmail, $later),
            'registration' => $replacement->recoverPendingRegistrationEmail($newEmail, $later),
            'lifecycle' => $replacement->disable($later),
            'recovery validation' => null,
            default => self::fail('Unknown transition fixture.')
        };
        if ($mismatch === 'replacement creation') {
            $replacement = self::withTimestamps(
                $replacement,
                $now->modify('+1 microsecond'),
                $replacement->getUpdatedAt()
            );
        }

        $session = RefreshSession::start(
            RefreshSessionId::generate(),
            $user->getId(),
            RefreshCredential::fromString(str_repeat('a', 64)),
            $later,
            $later->modify('+1 day'),
            $later->modify('+2 days'),
            $replacement->getAuthenticationVersion(),
            false
        );
        $result = match ($transition) {
            'authentication' => $repository->replaceAuthenticationAuthority($expected, $replacement),
            'coupled' => $repository->replaceAuthenticationAuthorityAndAddRefreshSession(
                $expected,
                $replacement,
                $session
            ),
            'roles' => $repository->replaceRoleAssignments($expected, $replacement),
            'reservation' => $repository->replaceEmailChangeReservation($expected, $replacement),
            'confirmation' => $repository->replaceEmailChangeConfirmation($expected, $replacement),
            'invitation' => $repository->replacePendingInvitationEmail($expected, $replacement),
            'registration' => $repository->replacePendingRegistration($expected, $replacement),
            'lifecycle' => $repository->replaceLifecycleState($expected, $replacement),
            default => $repository->validateRecoveryAuthority($expected)
        };
        self::assertSame($mismatch === 'unchanged', $result);
        self::assertSame(
            $mismatch === 'unchanged' && $transition !== 'recovery validation' ? $replacement : $user,
            $repository->getById($user->getId())
        );
        self::assertSame($mismatch === 'unchanged' && $transition === 'coupled' ? [$session] : [], $sessions->all());
    }

    private static function withTimestamps(
        User $source,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt
    ): User {
        $class = new ReflectionClass(User::class);
        $user = $class->newInstanceWithoutConstructor();
        $constructor = $class->getConstructor();
        self::assertNotNull($constructor);
        $constructor->invoke(
            $user,
            $source->getId(),
            $source->getEmail(),
            $source->getState(),
            $createdAt,
            $updatedAt,
            $source->getPasswordHash(),
            $source->getAuthenticationVersion(),
            $source->getAuthenticationAuthorityRevision(),
            $source->getRoleIds(),
            $source->getAuthorizationAssignmentRevision(),
            $source->getPendingEmailChange(),
            $source->getEmailChangeReservationRevision(),
            $source->getCanonicalEmailRevision()
        );

        return $user;
    }
}
