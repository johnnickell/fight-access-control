<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\ActivationGrant;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationDelivery;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationDeliveryId;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationDeliveryStatus;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Exception\ActivationDeliveryNotRetryableException;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ActivationDelivery::class)]
#[CoversClass(ActivationDeliveryStatus::class)]
final class ActivationDeliveryLifecycleTest extends TestCase
{
    public function test_that_confirmed_delivery_and_terminal_expiry_destroy_recoverable_ciphertext(): void
    {
        $userId = UserId::generate();
        $expiresAt = new DateTimeImmutable('2026-08-25T12:00:00+00:00');
        $confirmed = ActivationDelivery::create(
            ActivationDeliveryId::generate(),
            $userId,
            EmailAddress::fromString('alice@example.test'),
            'ciphertext',
            $expiresAt
        );
        $expired = ActivationDelivery::create(
            ActivationDeliveryId::generate(),
            $userId,
            EmailAddress::fromString('alice@example.test'),
            'ciphertext',
            $expiresAt
        );

        $confirmed = $confirmed->claim()->confirm();

        $expired = $expired->expireAt(new DateTimeImmutable('2026-08-25T11:59:59+00:00'));
        $expired = $expired->expireAt(new DateTimeImmutable('2026-08-25T12:00:00+00:00'));

        self::assertSame(ActivationDeliveryStatus::CONFIRMED, $confirmed->getStatus());
        self::assertNull($confirmed->getCiphertext());
        self::assertSame(ActivationDeliveryStatus::EXPIRED, $expired->getStatus());
        self::assertNull($expired->getCiphertext());
    }

    public function test_that_failed_delivery_remains_recoverable_until_a_retry_confirms_it(): void
    {
        $work = ActivationDelivery::create(
            ActivationDeliveryId::generate(),
            UserId::generate(),
            EmailAddress::fromString('alice@example.test'),
            'ciphertext',
            new DateTimeImmutable('2026-08-25T12:00:00+00:00')
        );

        $work = $work->claim()->fail();

        self::assertSame(ActivationDeliveryStatus::FAILED, $work->getStatus());
        self::assertSame('ciphertext', $work->getCiphertext());
        self::assertTrue($work->isRetryable());

        $work = $work->requestRetry()->claim()->confirm();

        self::assertSame(ActivationDeliveryStatus::CONFIRMED, $work->getStatus());
        self::assertNull($work->getCiphertext());
    }

    public function test_that_only_failed_delivery_can_be_requested_for_retry_and_pending_work_can_be_claimed(): void
    {
        $work = ActivationDelivery::create(
            ActivationDeliveryId::generate(),
            UserId::generate(),
            EmailAddress::fromString('alice@example.test'),
            'ciphertext',
            new DateTimeImmutable('2026-08-25T12:00:00+00:00')
        );

        $claimed = $work->claim();
        self::assertSame(ActivationDeliveryStatus::CLAIMED, $claimed->getStatus());
        self::assertFalse($claimed->isRetryable());

        try {
            $claimed->claim();
            self::fail('Claimed delivery work was claimed concurrently.');
        } catch (ActivationDeliveryNotRetryableException) {
            self::assertSame(ActivationDeliveryStatus::CLAIMED, $claimed->getStatus());
        }

        $failed = $claimed->fail();
        $pending = $failed->requestRetry();
        self::assertSame(ActivationDeliveryStatus::PENDING, $pending->getStatus());

        $this->expectException(ActivationDeliveryNotRetryableException::class);
        $pending->requestRetry();
    }

    public function test_that_terminal_delivery_work_cannot_be_marked_failed(): void
    {
        $work = ActivationDelivery::create(
            ActivationDeliveryId::generate(),
            UserId::generate(),
            EmailAddress::fromString('alice@example.test'),
            'ciphertext',
            new DateTimeImmutable('2026-08-25T12:00:00+00:00')
        );
        $work = $work->expireAt(new DateTimeImmutable('2026-08-25T12:00:00+00:00'));

        $this->expectException(ActivationDeliveryNotRetryableException::class);
        $work->fail();
    }

    public function test_that_pending_delivery_cannot_bypass_a_successful_claim_for_an_outcome(): void
    {
        $pending = ActivationDelivery::create(
            ActivationDeliveryId::generate(),
            UserId::generate(),
            EmailAddress::fromString('alice@example.test'),
            'ciphertext',
            new DateTimeImmutable('2026-08-25T12:00:00+00:00')
        );

        try {
            $pending->confirm();
            self::fail('Pending delivery was confirmed without a claim.');
        } catch (ActivationDeliveryNotRetryableException) {
            self::assertSame(ActivationDeliveryStatus::PENDING, $pending->getStatus());
            self::assertSame('ciphertext', $pending->getCiphertext());
        }

        try {
            $pending->fail();
            self::fail('Pending delivery was failed without a claim.');
        } catch (ActivationDeliveryNotRetryableException) {
            self::assertSame(ActivationDeliveryStatus::PENDING, $pending->getStatus());
            self::assertSame('ciphertext', $pending->getCiphertext());
        }
    }

    public function test_that_failed_delivery_must_retry_and_be_claimed_before_confirmation(): void
    {
        $failed = ActivationDelivery::create(
            ActivationDeliveryId::generate(),
            UserId::generate(),
            EmailAddress::fromString('alice@example.test'),
            'ciphertext',
            new DateTimeImmutable('2026-08-25T12:00:00+00:00')
        )->claim()->fail();

        try {
            $failed->confirm();
            self::fail('Failed delivery was confirmed without a retry claim.');
        } catch (ActivationDeliveryNotRetryableException) {
            self::assertSame(ActivationDeliveryStatus::FAILED, $failed->getStatus());
            self::assertSame('ciphertext', $failed->getCiphertext());
        }

        $this->expectException(ActivationDeliveryNotRetryableException::class);
        $failed->fail();
    }

    public function test_that_confirmed_delivery_cannot_record_another_outcome(): void
    {
        $confirmed = ActivationDelivery::create(
            ActivationDeliveryId::generate(),
            UserId::generate(),
            EmailAddress::fromString('alice@example.test'),
            'ciphertext',
            new DateTimeImmutable('2026-08-25T12:00:00+00:00')
        )->claim()->confirm();

        try {
            $confirmed->confirm();
            self::fail('Confirmed delivery was confirmed again without a claim.');
        } catch (ActivationDeliveryNotRetryableException) {
            self::assertSame(ActivationDeliveryStatus::CONFIRMED, $confirmed->getStatus());
            self::assertNull($confirmed->getCiphertext());
        }

        $this->expectException(ActivationDeliveryNotRetryableException::class);
        $confirmed->fail();
    }

    public function test_that_terminal_delivery_work_cannot_be_confirmed(): void
    {
        $work = ActivationDelivery::create(
            ActivationDeliveryId::generate(),
            UserId::generate(),
            EmailAddress::fromString('alice@example.test'),
            'ciphertext',
            new DateTimeImmutable('2026-08-25T12:00:00+00:00')
        );
        $work = $work->expireAt(new DateTimeImmutable('2026-08-25T12:00:00+00:00'));

        $this->expectException(ActivationDeliveryNotRetryableException::class);
        $work->confirm();
    }

    public function test_that_failed_delivery_expires_terminally_and_destroys_its_ciphertext(): void
    {
        $work = ActivationDelivery::create(
            ActivationDeliveryId::generate(),
            UserId::generate(),
            EmailAddress::fromString('alice@example.test'),
            'ciphertext',
            new DateTimeImmutable('2026-08-25T12:00:00+00:00')
        );
        $work = $work->claim()->fail();

        $work = $work->expireAt(new DateTimeImmutable('2026-08-25T12:00:00+00:00'));

        self::assertSame(ActivationDeliveryStatus::EXPIRED, $work->getStatus());
        self::assertNull($work->getCiphertext());
    }

    public function test_that_delivery_exposes_typed_identity_and_can_be_invalidated_idempotently(): void
    {
        $deliveryId = ActivationDeliveryId::generate();
        $userId = UserId::generate();
        $expiresAt = new DateTimeImmutable('2026-08-25T12:00:00+00:00');
        $work = ActivationDelivery::create(
            $deliveryId,
            $userId,
            EmailAddress::fromString('alice@example.test'),
            'ciphertext',
            $expiresAt
        );

        self::assertSame($deliveryId, $work->getId());
        self::assertSame($userId, $work->getUserId());
        self::assertSame('alice@example.test', $work->getEmail()->canonical());
        self::assertSame($expiresAt, $work->getExpiresAt());
        $invalidated = $work->invalidate();
        self::assertNull($invalidated->getCiphertext());
        self::assertSame($invalidated, $invalidated->invalidate());
    }

    public function test_that_delivery_rejects_empty_ciphertext(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('The activation delivery ciphertext must not be empty.');

        ActivationDelivery::create(
            ActivationDeliveryId::generate(),
            UserId::generate(),
            EmailAddress::fromString('alice@example.test'),
            '',
            new DateTimeImmutable('2026-08-25T12:00:00+00:00')
        );
    }

    public function test_that_factories_reconstitution_and_transitions_preserve_runtime_subtypes(): void
    {
        $id = ActivationDeliveryId::generate();
        $userId = UserId::generate();
        $email = EmailAddress::fromString('alice@example.test');
        $expiresAt = new DateTimeImmutable('2026-08-25T12:00:00+00:00');
        $created = ExtensibleActivationDelivery::create($id, $userId, $email, 'ciphertext', $expiresAt);
        $reconstituted = ExtensibleActivationDelivery::reconstitute(
            $id,
            $userId,
            $email,
            'ciphertext',
            $expiresAt,
            ActivationDeliveryStatus::PENDING
        );

        self::assertInstanceOf(ExtensibleActivationDelivery::class, $created);
        $failed = $reconstituted->claim()->fail();
        self::assertInstanceOf(ExtensibleActivationDelivery::class, $failed);
        $confirmed = $failed->requestRetry()->claim()->confirm();
        self::assertInstanceOf(ExtensibleActivationDelivery::class, $confirmed);
        self::assertInstanceOf(ExtensibleActivationDelivery::class, $created->invalidate());
        self::assertInstanceOf(
            ExtensibleActivationDelivery::class,
            $created->expireAt($expiresAt)
        );
    }
}
