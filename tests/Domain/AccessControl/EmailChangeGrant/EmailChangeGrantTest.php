<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\EmailChangeGrant;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeCredential;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeDelivery;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeDeliveryId;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeDeliveryStatus;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeGrant;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Exception\EmailChangeDeliveryNotRetryableException;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Exception\EmailChangeGrantException;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EmailChangeCredential::class)]
#[CoversClass(EmailChangeDelivery::class)]
#[CoversClass(EmailChangeDeliveryNotRetryableException::class)]
#[CoversClass(EmailChangeDeliveryStatus::class)]
#[CoversClass(EmailChangeGrant::class)]
#[CoversClass(EmailChangeGrantException::class)]
final class EmailChangeGrantTest extends TestCase
{
    public function test_confirmation_authority_is_hashed_expiring_and_single_use(): void
    {
        $grant = EmailChangeGrant::issue(
            UserId::generate(),
            EmailChangeCredential::fromString('change-once'),
            new DateTimeImmutable('2026-08-22T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-22T13:00:00+00:00'),
            EmailAddress::fromString('new@example.test'),
            'ciphertext:change-once'
        );

        self::assertTrue($grant->matchesCredential(EmailChangeCredential::fromString('change-once')));
        self::assertFalse($grant->matchesCredential(EmailChangeCredential::fromString('unrelated')));
        self::assertTrue($grant->isUsableAt(new DateTimeImmutable('2026-08-22T12:59:59+00:00')));
        self::assertFalse($grant->isUsableAt(new DateTimeImmutable('2026-08-22T13:00:00+00:00')));

        $consumed = $grant->consume(new DateTimeImmutable('2026-08-22T12:30:00+00:00'));

        self::assertTrue($consumed->isConsumed());
        self::assertSame('2026-08-22T12:30:00+00:00', $consumed->getConsumedAt()?->format(DATE_ATOM));
        self::assertFalse($consumed->isUsableAt(new DateTimeImmutable('2026-08-22T12:31:00+00:00')));
        self::assertFalse($consumed->getDelivery()->isRecoverable());

        $this->expectException(EmailChangeGrantException::class);
        $consumed->consume(new DateTimeImmutable('2026-08-22T12:31:00+00:00'));
    }

    public function test_issued_authority_owns_the_destination_and_delivery_generation(): void
    {
        $userId = UserId::generate();
        $grant = EmailChangeGrant::issue(
            $userId,
            EmailChangeCredential::fromString('change-once'),
            new DateTimeImmutable('2026-08-22T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-22T13:00:00+00:00'),
            EmailAddress::fromString('new@example.test'),
            'ciphertext:change-once'
        );

        self::assertSame($userId, $grant->getUserId());
        self::assertSame(0, $grant->getRevision());
        self::assertSame($userId, $grant->getDelivery()->getUserId());
        self::assertSame('new@example.test', $grant->getDelivery()->getEmail()->canonical());
        self::assertSame('ciphertext:change-once', $grant->getDelivery()->getCiphertext());
        self::assertSame($grant->getExpiresAt(), $grant->getDelivery()->getExpiresAt());
        self::assertTrue($grant->getDelivery()->getId()->equals(EmailChangeDeliveryId::fromString(
            $grant->getDelivery()->getId()->toString()
        )));
        self::assertTrue($grant->getId()->equals($grant->getId()::fromString($grant->getId()->toString())));
        $terminalDelivery = $grant->getDelivery()->invalidate();
        self::assertSame($terminalDelivery, $terminalDelivery->invalidate());
    }

    public function test_expiry_must_follow_issuance(): void
    {
        $this->expectException(EmailChangeGrantException::class);
        EmailChangeGrant::issue(
            UserId::generate(),
            EmailChangeCredential::fromString('change-once'),
            new DateTimeImmutable('2026-08-22T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-22T12:00:00+00:00'),
            EmailAddress::fromString('new@example.test'),
            'ciphertext:change-once'
        );
    }

    public function test_a_credential_must_not_be_empty(): void
    {
        $this->expectException(DomainException::class);
        EmailChangeCredential::fromString('');
    }

    public function test_delivery_ciphertext_must_not_be_empty(): void
    {
        $this->expectException(EmailChangeGrantException::class);
        EmailChangeDelivery::create(
            EmailChangeDeliveryId::generate(),
            UserId::generate(),
            EmailAddress::fromString('new@example.test'),
            '',
            new DateTimeImmutable('2026-08-22T13:00:00+00:00')
        );
    }

    public function test_delivery_work_has_fenced_retryable_status_transitions(): void
    {
        $grant = EmailChangeGrant::issue(
            UserId::generate(),
            EmailChangeCredential::fromString('change-once'),
            new DateTimeImmutable('2026-08-22T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-22T13:00:00+00:00'),
            EmailAddress::fromString('new@example.test'),
            'ciphertext:change-once'
        );

        self::assertSame(EmailChangeDeliveryStatus::PENDING, $grant->getDelivery()->getStatus());
        self::assertTrue($grant->getDelivery()->isRetryable());
        $claimed = $grant->claimDelivery();
        self::assertSame(EmailChangeDeliveryStatus::CLAIMED, $claimed->getDelivery()->getStatus());
        self::assertFalse($claimed->getDelivery()->isRetryable());
        $failed = $claimed->failDelivery();
        self::assertSame(EmailChangeDeliveryStatus::FAILED, $failed->getDelivery()->getStatus());
        self::assertTrue($failed->getDelivery()->isRetryable());
        $reclaimed = $failed->claimDelivery();
        self::assertSame(EmailChangeDeliveryStatus::CLAIMED, $reclaimed->getDelivery()->getStatus());
        $confirmed = $reclaimed->confirmDelivery();
        self::assertSame(EmailChangeDeliveryStatus::CONFIRMED, $confirmed->getDelivery()->getStatus());
        self::assertFalse($confirmed->getDelivery()->isRecoverable());
        self::assertFalse($confirmed->getDelivery()->isRetryable());

        foreach (
            [
                $claimed->claimDelivery(...),
                $grant->confirmDelivery(...),
                $grant->failDelivery(...),
            ] as $invalidTransition
        ) {
            try {
                $invalidTransition();
                self::fail('An invalid delivery status transition was accepted.');
            } catch (EmailChangeDeliveryNotRetryableException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_issued_authority_can_be_revoked_once_and_destroys_delivery_material(): void
    {
        $grant = EmailChangeGrant::issue(
            UserId::generate(),
            EmailChangeCredential::fromString('change-once'),
            new DateTimeImmutable('2026-08-22T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-22T13:00:00+00:00'),
            EmailAddress::fromString('new@example.test'),
            'ciphertext:change-once'
        );

        self::assertTrue($grant->isIssued());
        $revoked = $grant->revoke(new DateTimeImmutable('2026-08-22T12:30:00+00:00'));

        self::assertFalse($revoked->isIssued());
        self::assertTrue($revoked->isRevoked());
        self::assertSame('2026-08-22T12:30:00+00:00', $revoked->getRevokedAt()?->format(DATE_ATOM));
        self::assertSame(1, $revoked->getRevision());
        self::assertFalse($revoked->getDelivery()->isRecoverable());
        self::assertFalse($revoked->isUsableAt(new DateTimeImmutable('2026-08-22T12:31:00+00:00')));

        $this->expectException(EmailChangeGrantException::class);
        $revoked->revoke(new DateTimeImmutable('2026-08-22T12:31:00+00:00'));
    }

    public function test_expiry_is_early_safe_terminal_and_repeat_safe(): void
    {
        $grant = EmailChangeGrant::issue(
            UserId::generate(),
            EmailChangeCredential::fromString('change-once'),
            new DateTimeImmutable('2026-08-22T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-22T13:00:00+00:00'),
            EmailAddress::fromString('new@example.test'),
            'ciphertext:change-once'
        );

        self::assertSame($grant, $grant->expireAt(new DateTimeImmutable('2026-08-22T12:59:59+00:00')));
        $expired = $grant->expireAt(new DateTimeImmutable('2026-08-22T13:00:00+00:00'));

        self::assertTrue($expired->isExpired());
        self::assertFalse($expired->isIssued());
        self::assertSame('2026-08-22T13:00:00+00:00', $expired->getExpiredAt()?->format(DATE_ATOM));
        self::assertSame(1, $expired->getRevision());
        self::assertFalse($expired->getDelivery()->isRecoverable());
        self::assertSame($expired, $expired->expireAt(new DateTimeImmutable('2026-08-22T13:01:00+00:00')));
    }
}
