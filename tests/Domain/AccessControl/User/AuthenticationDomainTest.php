<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\User;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\Event\CurrentSessionLoggedOut;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionId;
use Fight\AccessControl\Domain\AccessControl\User\ActivationCredential;
use Fight\AccessControl\Domain\AccessControl\User\Event\RedactedCommandFailed;
use Fight\AccessControl\Domain\AccessControl\User\Event\UserActivated;
use Fight\AccessControl\Domain\AccessControl\User\Event\UserLoggedIn;
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
            'error_message' => 'Login failed.',
        ]);
    }

    public function test_that_only_a_pending_identity_can_establish_its_initial_password_hash(): void
    {
        $user = User::invite(UserId::generate(), EmailAddress::fromString('alice@example.test'));
        $passwordHash = $this->passwordHash();

        $user->activate($passwordHash);

        self::assertSame(UserState::ACTIVE, $user->getState());
        self::assertSame($passwordHash, $user->getPasswordHash());
        $this->expectException(UserNotPendingActivationException::class);
        $user->activate($this->passwordHash());
    }

    public function test_that_password_rehash_requires_an_active_identity_with_a_password(): void
    {
        $user = User::invite(UserId::generate(), EmailAddress::fromString('pending@example.test'));

        $this->expectException(UserNotActiveException::class);
        $user->rehashPassword($this->passwordHash());
    }

    public function test_that_authentication_authority_revision_advancement_is_monotonic_and_domain_owned(): void
    {
        $user = User::invite(UserId::generate(), EmailAddress::fromString('authority-revision@example.test'));

        self::assertSame(0, $user->getAuthenticationAuthorityRevision());

        $user->advanceAuthenticationAuthorityRevision();

        self::assertSame(1, $user->getAuthenticationAuthorityRevision());

        $user->advanceAuthenticationAuthorityRevision();

        self::assertSame(2, $user->getAuthenticationAuthorityRevision());
    }

    public function test_that_password_reset_requires_an_active_identity_with_an_established_password(): void
    {
        $user = User::invite(UserId::generate(), EmailAddress::fromString('pending@example.test'));

        $this->expectException(UserNotActiveException::class);
        $this->expectExceptionMessage('Only an active user can reset an established password.');

        $user->resetPassword($this->passwordHash());
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
