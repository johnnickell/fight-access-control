<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\User;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\User\ActivationGrant;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(User::class)]
#[CoversClass(ActivationGrant::class)]
#[CoversClass(UserId::class)]
final class InvitationTest extends TestCase
{
    public function test_an_invited_user_has_a_canonical_email_and_pending_state(): void
    {
        $user = User::invite(UserId::generate(), EmailAddress::fromString('Alice@Example.Test'));

        self::assertSame('alice@example.test', $user->getEmail()->canonical());
        self::assertNotSame('', $user->getId()->toString());
        self::assertSame(UserState::PENDING_ACTIVATION, $user->getState());
    }

    public function test_an_activation_grant_retains_only_a_hash_of_its_raw_credential(): void
    {
        $grant = ActivationGrant::issue(
            UserId::generate(),
            'activate-once',
            new DateTimeImmutable('2026-08-18T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-25T12:00:00+00:00')
        );

        self::assertSame('activation', $grant->purpose());
        self::assertInstanceOf(UserId::class, $grant->getUserId());
        self::assertSame(hash('sha256', 'activate-once'), $grant->getCredentialHash());
        self::assertNotSame('activate-once', $grant->getCredentialHash());
        self::assertSame('2026-08-25T12:00:00+00:00', $grant->getExpiresAt()->format(DATE_ATOM));
    }

    public function test_an_issued_activation_grant_can_be_consumed_only_once_before_expiry(): void
    {
        $grant = ActivationGrant::issue(
            UserId::generate(),
            'activate-once',
            new DateTimeImmutable('2026-08-18T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-25T12:00:00+00:00')
        );
        $consumed = $grant->consume(new DateTimeImmutable('2026-08-20T12:00:00+00:00'));

        self::assertTrue($grant->isIssued());
        self::assertTrue($grant->isUsableAt(new DateTimeImmutable('2026-08-20T12:00:00+00:00')));
        self::assertTrue($consumed->isConsumed());
        self::assertFalse($consumed->isIssued());
        self::assertFalse($consumed->isUsableAt(new DateTimeImmutable('2026-08-20T12:00:00+00:00')));
        self::assertSame('2026-08-20T12:00:00+00:00', $consumed->getConsumedAt()?->format(DATE_ATOM));

        $this->expectException(LogicException::class);

        $consumed->consume(new DateTimeImmutable('2026-08-20T12:01:00+00:00'));
    }

    public function test_an_activation_grant_cannot_be_consumed_after_expiry_or_revocation(): void
    {
        $grant = ActivationGrant::issue(
            UserId::generate(),
            'activate-once',
            new DateTimeImmutable('2026-08-18T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-25T12:00:00+00:00')
        );
        $revoked = $grant->revoke(new DateTimeImmutable('2026-08-20T12:00:00+00:00'));

        self::assertTrue($revoked->isRevoked());
        self::assertFalse($revoked->isUsableAt(new DateTimeImmutable('2026-08-20T12:00:00+00:00')));
        self::assertSame('2026-08-20T12:00:00+00:00', $revoked->getRevokedAt()?->format(DATE_ATOM));

        $this->expectException(LogicException::class);

        $revoked->consume(new DateTimeImmutable('2026-08-20T12:01:00+00:00'));
    }

    public function test_a_consumed_activation_grant_cannot_be_revoked_again(): void
    {
        $grant = ActivationGrant::issue(
            UserId::generate(),
            'activate-once',
            new DateTimeImmutable('2026-08-18T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-25T12:00:00+00:00')
        );
        $consumed = $grant->consume(new DateTimeImmutable('2026-08-20T12:00:00+00:00'));

        $this->expectException(LogicException::class);

        $consumed->revoke(new DateTimeImmutable('2026-08-20T12:01:00+00:00'));
    }

    public function test_an_expired_activation_grant_is_not_usable_or_consumable(): void
    {
        $grant = ActivationGrant::issue(
            UserId::generate(),
            'activate-once',
            new DateTimeImmutable('2026-08-18T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-25T12:00:00+00:00')
        );

        self::assertFalse($grant->isUsableAt(new DateTimeImmutable('2026-08-25T12:00:00+00:00')));

        $this->expectException(LogicException::class);

        $grant->consume(new DateTimeImmutable('2026-08-25T12:00:00+00:00'));
    }

    public function test_an_activation_grant_rejects_an_expiry_at_or_before_its_issuance_time(): void
    {
        $issuedAt = new DateTimeImmutable('2026-08-18T12:00:00+00:00');

        $this->expectException(InvalidArgumentException::class);

        ActivationGrant::issue(UserId::generate(), 'activate-once', $issuedAt, $issuedAt);
    }
}
