<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\User;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationCredential;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\Event\CurrentSessionLoggedOut;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionId;
use Fight\AccessControl\Domain\AccessControl\User\Event\RedactedCommandFailed;
use Fight\AccessControl\Domain\AccessControl\User\Event\UserActivated;
use Fight\AccessControl\Domain\AccessControl\User\Event\UserLoggedIn;
use Fight\AccessControl\Domain\AccessControl\User\Exception\EmailChangeConfirmationException;
use Fight\AccessControl\Domain\AccessControl\User\Exception\UserNotActiveException;
use Fight\AccessControl\Domain\AccessControl\User\Exception\UserNotPendingActivationException;
use Fight\AccessControl\Domain\AccessControl\User\PasswordHash;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ActivationCredential::class)]
#[CoversClass(CurrentSessionLoggedOut::class)]
#[CoversClass(PasswordHash::class)]
#[CoversClass(RedactedCommandFailed::class)]
#[CoversClass(UserActivated::class)]
#[CoversClass(UserLoggedIn::class)]
#[CoversClass(User::class)]
final class AuthenticationDomainTest extends TestCase
{
    public function test_that_activation_events_round_trip_without_secret_material(): void
    {
        $event = new UserActivated(
            UserId::generate(),
            RefreshSessionId::generate(),
            new DateTimeImmutable('2026-08-19T12:00:00+00:00')
        );

        self::assertSame($event->toArray(), UserActivated::fromArray($event->toArray())->toArray());
        self::assertInstanceOf(UserId::class, $event->getUserId());
        self::assertInstanceOf(RefreshSessionId::class, $event->getRefreshSessionId());
        self::assertSame('2026-08-19T12:00:00+00:00', $event->getActivatedAt()->format(DATE_ATOM));

        $this->expectException(DomainException::class);
        UserActivated::fromArray([]);
    }

    public function test_that_login_and_logout_events_round_trip_without_credentials(): void
    {
        $userId = UserId::generate();
        $refreshSessionId = RefreshSessionId::generate();
        $loggedIn = new UserLoggedIn(
            $userId,
            $refreshSessionId,
            new DateTimeImmutable('2026-08-19T12:00:00+00:00')
        );
        $loggedOut = new CurrentSessionLoggedOut($refreshSessionId);

        self::assertSame($loggedIn->toArray(), UserLoggedIn::fromArray($loggedIn->toArray())->toArray());
        self::assertSame($userId, $loggedIn->getUserId());
        self::assertSame($refreshSessionId, $loggedIn->getRefreshSessionId());
        self::assertSame('2026-08-19T12:00:00+00:00', $loggedIn->getLoggedInAt()->format(DATE_ATOM));
        self::assertSame($loggedOut->toArray(), CurrentSessionLoggedOut::fromArray($loggedOut->toArray())->toArray());
        self::assertSame($refreshSessionId, $loggedOut->getRefreshSessionId());

        try {
            UserLoggedIn::fromArray([]);
            self::fail('Expected missing login event data to be rejected.');
        } catch (DomainException $domainException) {
            self::assertInstanceOf(DomainException::class, $domainException);
        }

        $this->expectException(DomainException::class);
        CurrentSessionLoggedOut::fromArray([]);
    }

    public function test_that_redacted_failures_round_trip_only_allowlisted_context(): void
    {
        $event = new RedactedCommandFailed(
            'AuthenticationService::activate',
            ['user_id' => UserId::generate()->toString()],
            'Activation failed.'
        );

        self::assertSame($event->toArray(), RedactedCommandFailed::fromArray($event->toArray())->toArray());
        self::assertSame('AuthenticationService::activate', $event->getCommandClass());
        self::assertArrayHasKey('user_id', $event->getRedactedCommandData());
        self::assertSame('Activation failed.', $event->getErrorMessage());

        $this->expectException(DomainException::class);
        RedactedCommandFailed::fromArray([]);
    }

    public function test_that_redacted_failures_reject_non_array_context(): void
    {
        $this->expectException(DomainException::class);
        RedactedCommandFailed::fromArray([
            'command_class' => 'AuthenticationService::login',
            'command_data'  => 'not-an-array',
            'error_message' => 'Login failed.'
        ]);
    }

    public function test_that_only_a_pending_identity_can_establish_its_initial_password_hash(): void
    {
        $user = User::invite(
            UserId::generate(),
            EmailAddress::fromString('alice@example.test'),
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        );
        $passwordHash = $this->passwordHash();

        $user->activate($passwordHash, new DateTimeImmutable('2026-01-01T00:00:00+00:00'));

        self::assertSame(UserState::ACTIVE, $user->getState());
        self::assertSame($passwordHash, $user->getPasswordHash());
        self::assertSame(1, $user->getAuthenticationVersion());
        self::assertSame(1, $user->getAuthenticationAuthorityRevision());
        $this->expectException(UserNotPendingActivationException::class);
        $user->activate($this->passwordHash(), new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
    }

    public function test_that_successful_login_requires_an_active_identity_with_a_password(): void
    {
        $user = User::invite(
            UserId::generate(),
            EmailAddress::fromString('pending@example.test'),
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        );

        $this->expectException(UserNotActiveException::class);
        $user->recordSuccessfulLogin(new DateTimeImmutable('2026-01-01T00:00:00+00:00'), $this->passwordHash());
    }

    public function test_that_password_change_requires_active_authority_and_advances_version_once(): void
    {
        $user = User::invite(
            UserId::generate(),
            EmailAddress::fromString('change-password@example.test'),
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        );
        $user->activate($this->passwordHash(), new DateTimeImmutable('2026-01-01T00:00:00+00:00'));

        $replacementPasswordHash = PasswordHash::fromString(password_hash(
            'a sufficiently long changed password',
            PASSWORD_DEFAULT
        ));

        $user->changePassword($replacementPasswordHash, new DateTimeImmutable('2026-01-01T00:00:00+00:00'));

        self::assertSame($replacementPasswordHash, $user->getPasswordHash());
        self::assertSame(2, $user->getAuthenticationVersion());
        self::assertSame(2, $user->getAuthenticationAuthorityRevision());

        $pendingUser = User::invite(
            UserId::generate(),
            EmailAddress::fromString('pending-change@example.test'),
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        );
        $this->expectException(UserNotActiveException::class);
        $this->expectExceptionMessage('Only an active user can change an established password.');

        $pendingUser->changePassword($replacementPasswordHash, new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
    }

    public function test_that_business_transitions_own_exact_distinct_authentication_counters(): void
    {
        $createdAt = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $now = $createdAt->modify('+1 day');
        $user = User::invite(UserId::generate(), EmailAddress::fromString('authority@example.test'), $createdAt);
        $hash = $this->passwordHash();
        self::assertSame(0, $user->getAuthenticationAuthorityRevision());
        self::assertSame(1, $user->getAuthenticationVersion());

        $user->activate($hash, $createdAt);
        $recoveryAuthority = $user->getRecoveryAuthority();
        $user->recordSuccessfulLogin($now);
        self::assertSame(2, $user->getAuthenticationAuthorityRevision());
        self::assertSame(1, $user->getAuthenticationVersion());
        self::assertSame($hash, $user->getPasswordHash());
        self::assertSame($createdAt, $user->getUpdatedAt());
        self::assertEquals($recoveryAuthority, $user->getRecoveryAuthority());

        $rehashedPassword = $this->passwordHash();
        $user->recordSuccessfulLogin($now, $rehashedPassword);
        self::assertSame(3, $user->getAuthenticationAuthorityRevision());
        self::assertSame(1, $user->getAuthenticationVersion());
        self::assertSame($rehashedPassword, $user->getPasswordHash());
        self::assertSame($now, $user->getUpdatedAt());
        self::assertEquals($recoveryAuthority, $user->getRecoveryAuthority());

        $user->changePassword($rehashedPassword, $now);
        self::assertSame(4, $user->getAuthenticationAuthorityRevision());
        self::assertSame(2, $user->getAuthenticationVersion());
        $user->resetPassword($hash, $now);
        self::assertSame(5, $user->getAuthenticationAuthorityRevision());
        self::assertSame(3, $user->getAuthenticationVersion());
        self::assertSame($hash, $user->getPasswordHash());

        $user->requestEmailChange(EmailAddress::fromString('confirmed@example.test'), $now);
        self::assertSame(5, $user->getAuthenticationAuthorityRevision());
        $user->confirmEmailChange($now);
        self::assertSame(6, $user->getAuthenticationAuthorityRevision());
        self::assertSame(4, $user->getAuthenticationVersion());
        self::assertSame(1, $user->getCanonicalEmailRevision());
        self::assertSame(2, $user->getEmailChangeReservationRevision());
        self::assertSame('confirmed@example.test', $user->getEmail()->canonical());
        self::assertNull($user->getPendingEmailChange());
    }

    public function test_that_rejected_authentication_transitions_preserve_the_complete_user_snapshot(): void
    {
        $now = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $hash = $this->passwordHash();
        foreach (UserState::cases() as $state) {
            foreach (['login', 'rehash login', 'change', 'reset', 'confirm'] as $transition) {
                $user = UserFixture::withState('rejected@example.test', $state);
                $before = clone $user;
                try {
                    match ($transition) {
                        'login' => $user->recordSuccessfulLogin($now),
                        'rehash login' => $user->recordSuccessfulLogin($now, $hash),
                        'change' => $user->changePassword($hash, $now),
                        'reset' => $user->resetPassword($hash, $now),
                        'confirm' => $user->confirmEmailChange($now)
                    };
                    self::fail('An ineligible transition was accepted.');
                } catch (UserNotActiveException | EmailChangeConfirmationException) {
                    self::assertEquals($before, $user);
                }
            }
        }

        $user = User::invite(UserId::generate(), EmailAddress::fromString('activated@example.test'), $now);
        $user->activate($hash, $now);
        foreach ([UserState::ACTIVE, UserState::DISABLED, UserState::DELETED] as $state) {
            if ($state === UserState::DISABLED) {
                $user->disable($now);
            } elseif ($state === UserState::DELETED) {
                $user->delete($now);
            }

            $before = clone $user;
            try {
                $user->activate($hash, $now);
                self::fail('A non-pending identity was activated.');
            } catch (UserNotPendingActivationException) {
                self::assertEquals($before, $user);
            }
        }
    }

    public function test_that_lifecycle_transitions_keep_security_versions_distinct_from_login_revisions(): void
    {
        $now = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $user = User::invite(UserId::generate(), EmailAddress::fromString('lifecycle@example.test'), $now);
        $user->activate($this->passwordHash(), $now);
        $user->disable($now);
        self::assertSame(2, $user->getAuthenticationVersion());
        self::assertSame(1, $user->getAuthenticationAuthorityRevision());
        $user->enable($now);
        self::assertSame(2, $user->getAuthenticationVersion());
        self::assertSame(1, $user->getAuthenticationAuthorityRevision());
        $user->delete($now);
        self::assertSame(3, $user->getAuthenticationVersion());
        self::assertSame(1, $user->getAuthenticationAuthorityRevision());
        $user->restore(UserState::ACTIVE, $now);
        self::assertSame(3, $user->getAuthenticationVersion());
        self::assertSame(1, $user->getAuthenticationAuthorityRevision());
    }

    public function test_that_password_reset_requires_an_active_identity_with_an_established_password(): void
    {
        $user = User::invite(
            UserId::generate(),
            EmailAddress::fromString('pending-reset@example.test'),
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        );

        $this->expectException(UserNotActiveException::class);
        $this->expectExceptionMessage('Only an active user can reset an established password.');

        $user->resetPassword($this->passwordHash(), new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
    }

    public function test_that_password_hashes_and_activation_credentials_validate_transport_values(): void
    {
        self::assertSame('activate-once', ActivationCredential::fromString('activate-once')->toString());

        try {
            ActivationCredential::fromString('');
            self::fail('Expected an empty activation credential to be rejected.');
        } catch (DomainException $domainException) {
            self::assertInstanceOf(DomainException::class, $domainException);
        }

        $this->expectException(DomainException::class);
        PasswordHash::fromString('plain-password');
    }

    private function passwordHash(): PasswordHash
    {
        return PasswordHash::fromString(password_hash('a sufficiently long password', PASSWORD_DEFAULT));
    }
}
