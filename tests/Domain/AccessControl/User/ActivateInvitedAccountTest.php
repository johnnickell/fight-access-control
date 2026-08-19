<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\User;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSession;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionId;
use Fight\AccessControl\Domain\AccessControl\User\ActivationCredential;
use Fight\AccessControl\Domain\AccessControl\User\Command\ActivateInvitedAccount;
use Fight\AccessControl\Domain\AccessControl\User\Event\RedactedCommandFailed;
use Fight\AccessControl\Domain\AccessControl\User\Event\UserActivated;
use Fight\AccessControl\Domain\AccessControl\User\Exception\UserNotPendingActivationException;
use Fight\AccessControl\Domain\AccessControl\User\PasswordHash;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ActivateInvitedAccount::class)]
#[CoversClass(ActivationCredential::class)]
#[CoversClass(PasswordHash::class)]
#[CoversClass(RefreshSession::class)]
#[CoversClass(RedactedCommandFailed::class)]
#[CoversClass(UserActivated::class)]
#[CoversClass(User::class)]
final class ActivateInvitedAccountTest extends TestCase
{
    public function test_that_the_activation_command_round_trips_and_rejects_missing_data(): void
    {
        $passwordHash = $this->passwordHash();
        $credential = ActivationCredential::fromString('activate-once');
        $command = new ActivateInvitedAccount(UserId::generate(), $credential, $passwordHash);

        self::assertSame($command->toArray(), ActivateInvitedAccount::fromArray($command->toArray())->toArray());
        self::assertSame($credential, $command->getActivationCredential());
        self::assertSame($passwordHash, $command->getPasswordHash());
        $this->expectException(DomainException::class);
        ActivateInvitedAccount::fromArray([]);
    }

    public function test_that_the_activation_event_round_trips_and_rejects_missing_data(): void
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

    public function test_that_a_redacted_command_failure_round_trips_without_command_secrets(): void
    {
        $event = new RedactedCommandFailed(
            ActivateInvitedAccount::class,
            ['user_id' => UserId::generate()->toString()],
            'The activation grant cannot activate this invited account.'
        );

        self::assertSame($event->toArray(), RedactedCommandFailed::fromArray($event->toArray())->toArray());
        self::assertSame(ActivateInvitedAccount::class, $event->getCommandClass());
        self::assertArrayHasKey('user_id', $event->getRedactedCommandData());
        self::assertSame('The activation grant cannot activate this invited account.', $event->getErrorMessage());
        $this->expectException(DomainException::class);
        RedactedCommandFailed::fromArray([]);
    }

    public function test_that_a_redacted_command_failure_rejects_non_array_command_data(): void
    {
        $this->expectException(DomainException::class);
        RedactedCommandFailed::fromArray([
            'command_class' => ActivateInvitedAccount::class,
            'command_data'  => 'not-an-array',
            'error_message' => 'Activation failed.',
        ]);
    }

    public function test_that_a_first_refresh_session_keeps_its_identity_and_activation_time(): void
    {
        $id = RefreshSessionId::generate();
        $userId = UserId::generate();
        $activatedAt = new DateTimeImmutable('2026-08-19T12:00:00+00:00');
        $session = RefreshSession::start($id, $userId, $activatedAt);

        self::assertSame($id, $session->getId());
        self::assertSame($userId, $session->getUserId());
        self::assertSame($activatedAt, $session->getActivatedAt());
        self::assertSame(1, $session->getAuthenticationVersion());
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

    public function test_that_a_password_hash_rejects_non_password_hash_values(): void
    {
        $this->expectException(DomainException::class);
        PasswordHash::fromString('initial-password');
    }

    public function test_that_an_activation_credential_rejects_an_empty_value(): void
    {
        $this->expectException(DomainException::class);
        ActivationCredential::fromString('');
    }

    private function passwordHash(): PasswordHash
    {
        $passwordHash = password_hash('initial-password', PASSWORD_DEFAULT);

        return PasswordHash::fromString($passwordHash);
    }
}
