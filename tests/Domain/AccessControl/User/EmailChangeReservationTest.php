<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\User;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\User\Exception\EmailChangeCancellationException;
use Fight\AccessControl\Domain\AccessControl\User\Exception\EmailChangeConfirmationException;
use Fight\AccessControl\Domain\AccessControl\User\Exception\EmailChangeExpirationException;
use Fight\AccessControl\Domain\AccessControl\User\Exception\EmailChangeRequestException;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(EmailChangeCancellationException::class)]
#[CoversClass(EmailChangeConfirmationException::class)]
#[CoversClass(EmailChangeExpirationException::class)]
#[CoversClass(EmailChangeRequestException::class)]
#[CoversClass(User::class)]
final class EmailChangeReservationTest extends TestCase
{
    /**
     * @return iterable<string, array{UserState}>
     */
    public static function ineligibleStates(): iterable
    {
        yield 'pending activation' => [UserState::PENDING_ACTIVATION];
        yield 'disabled' => [UserState::DISABLED];
        yield 'deleted' => [UserState::DELETED];
    }

    public function test_an_active_identity_reserves_a_destination_without_changing_its_canonical_email(): void
    {
        $user = UserFixture::withState('old@example.test', UserState::ACTIVE);

        $user->requestEmailChange(
            EmailAddress::fromString('New@Example.test'),
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        );

        self::assertSame('old@example.test', $user->getEmail()->canonical());
        self::assertSame('new@example.test', $user->getPendingEmailChange()?->canonical());
        self::assertSame(1, $user->getEmailChangeReservationRevision());
    }

    #[DataProvider('ineligibleStates')]
    public function test_only_an_active_identity_can_reserve_a_destination(UserState $state): void
    {
        $user = UserFixture::withState('old@example.test', $state);

        $this->expectException(EmailChangeRequestException::class);
        $user->requestEmailChange(
            EmailAddress::fromString('new@example.test'),
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        );
    }

    public function test_the_canonical_email_cannot_be_reserved_as_its_own_destination(): void
    {
        $user = UserFixture::withState('old@example.test', UserState::ACTIVE);

        $this->expectException(EmailChangeRequestException::class);
        $user->requestEmailChange(
            EmailAddress::fromString('OLD@example.test'),
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        );
    }

    public function test_a_second_destination_cannot_displace_a_live_reservation(): void
    {
        $user = UserFixture::withState('old@example.test', UserState::ACTIVE);
        $user->requestEmailChange(
            EmailAddress::fromString('first@example.test'),
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        );

        $this->expectException(EmailChangeRequestException::class);
        $user->requestEmailChange(
            EmailAddress::fromString('second@example.test'),
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        );
    }

    public function test_an_active_identity_cancels_only_its_pending_destination(): void
    {
        $user = UserFixture::withState('old@example.test', UserState::ACTIVE);
        $user->requestEmailChange(
            EmailAddress::fromString('new@example.test'),
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        );

        $user->cancelEmailChange(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));

        self::assertSame('old@example.test', $user->getEmail()->canonical());
        self::assertNull($user->getPendingEmailChange());
        self::assertSame(2, $user->getEmailChangeReservationRevision());
    }

    public function test_confirmation_promotes_the_destination_and_invalidates_authentication_authority(): void
    {
        $user = UserFixture::withState('old@example.test', UserState::ACTIVE);
        $user->requestEmailChange(
            EmailAddress::fromString('new@example.test'),
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        );

        $user->confirmEmailChange(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));

        self::assertSame('new@example.test', $user->getEmail()->canonical());
        self::assertNull($user->getPendingEmailChange());
        self::assertSame(2, $user->getAuthenticationVersion());
        self::assertSame(2, $user->getEmailChangeReservationRevision());
        self::assertSame(1, $user->getCanonicalEmailRevision());
    }

    public function test_an_identity_without_a_live_reservation_cannot_confirm_one(): void
    {
        $user = UserFixture::withState('old@example.test', UserState::ACTIVE);

        $this->expectException(EmailChangeConfirmationException::class);
        $user->confirmEmailChange(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
    }

    public function test_an_identity_without_a_live_reservation_cannot_cancel(): void
    {
        $user = UserFixture::withState('old@example.test', UserState::ACTIVE);

        $this->expectException(EmailChangeCancellationException::class);
        $user->cancelEmailChange(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
    }

    public function test_expiry_clears_only_the_pending_destination(): void
    {
        $user = UserFixture::withState('old@example.test', UserState::ACTIVE);
        $user->requestEmailChange(
            EmailAddress::fromString('new@example.test'),
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        );

        $user->expireEmailChange(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));

        self::assertSame('old@example.test', $user->getEmail()->canonical());
        self::assertNull($user->getPendingEmailChange());
        self::assertSame(2, $user->getEmailChangeReservationRevision());
    }

    public function test_an_identity_without_a_live_reservation_cannot_expire_one(): void
    {
        $user = UserFixture::withState('old@example.test', UserState::ACTIVE);

        $this->expectException(EmailChangeExpirationException::class);
        $user->expireEmailChange(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
    }
}
