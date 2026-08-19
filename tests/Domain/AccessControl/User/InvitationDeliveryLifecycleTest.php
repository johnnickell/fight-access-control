<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\User;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\User\Exception\InvitationDeliveryNotRetryableException;
use Fight\AccessControl\Domain\AccessControl\User\InvitationDelivery;
use Fight\AccessControl\Domain\AccessControl\User\InvitationDeliveryStatus;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(InvitationDelivery::class)]
#[CoversClass(InvitationDeliveryStatus::class)]
final class InvitationDeliveryLifecycleTest extends TestCase
{
    public function test_that_confirmed_delivery_and_terminal_expiry_destroy_recoverable_ciphertext(): void
    {
        $userId = UserId::generate();
        $expiresAt = new DateTimeImmutable('2026-08-25T12:00:00+00:00');
        $confirmed = InvitationDelivery::create($userId, 'alice@example.test', 'ciphertext', $expiresAt);
        $expired = InvitationDelivery::create($userId, 'alice@example.test', 'ciphertext', $expiresAt);

        $confirmed->confirm();
        $confirmed->confirm();

        $expired->expireAt(new DateTimeImmutable('2026-08-25T11:59:59+00:00'));
        $expired->expireAt(new DateTimeImmutable('2026-08-25T12:00:00+00:00'));

        self::assertSame(InvitationDeliveryStatus::CONFIRMED, $confirmed->getStatus());
        self::assertNull($confirmed->ciphertext());
        self::assertSame(InvitationDeliveryStatus::EXPIRED, $expired->getStatus());
        self::assertNull($expired->ciphertext());
    }

    public function test_that_failed_delivery_remains_recoverable_until_a_retry_confirms_it(): void
    {
        $work = InvitationDelivery::create(
            UserId::generate(),
            'alice@example.test',
            'ciphertext',
            new DateTimeImmutable('2026-08-25T12:00:00+00:00')
        );

        $work->fail();
        $work->fail();

        self::assertSame(InvitationDeliveryStatus::FAILED, $work->getStatus());
        self::assertSame('ciphertext', $work->ciphertext());
        self::assertTrue($work->isRetryable());

        $work->confirm();

        self::assertSame(InvitationDeliveryStatus::CONFIRMED, $work->getStatus());
        self::assertNull($work->ciphertext());
    }

    public function test_that_terminal_delivery_work_cannot_be_marked_failed(): void
    {
        $work = InvitationDelivery::create(
            UserId::generate(),
            'alice@example.test',
            'ciphertext',
            new DateTimeImmutable('2026-08-25T12:00:00+00:00')
        );
        $work->expireAt(new DateTimeImmutable('2026-08-25T12:00:00+00:00'));

        $this->expectException(InvitationDeliveryNotRetryableException::class);
        $work->fail();
    }

    public function test_that_terminal_delivery_work_cannot_be_confirmed(): void
    {
        $work = InvitationDelivery::create(
            UserId::generate(),
            'alice@example.test',
            'ciphertext',
            new DateTimeImmutable('2026-08-25T12:00:00+00:00')
        );
        $work->expireAt(new DateTimeImmutable('2026-08-25T12:00:00+00:00'));

        $this->expectException(InvitationDeliveryNotRetryableException::class);
        $work->confirm();
    }

    public function test_that_failed_delivery_expires_terminally_and_destroys_its_ciphertext(): void
    {
        $work = InvitationDelivery::create(
            UserId::generate(),
            'alice@example.test',
            'ciphertext',
            new DateTimeImmutable('2026-08-25T12:00:00+00:00')
        );
        $work->fail();

        $work->expireAt(new DateTimeImmutable('2026-08-25T12:00:00+00:00'));

        self::assertSame(InvitationDeliveryStatus::EXPIRED, $work->getStatus());
        self::assertNull($work->ciphertext());
    }
}
