<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\User;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\User\PasswordResetDelivery;
use Fight\AccessControl\Domain\AccessControl\User\PasswordResetDeliveryId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PasswordResetDelivery::class)]
#[CoversClass(PasswordResetDeliveryId::class)]
final class PasswordResetDeliveryLifecycleTest extends TestCase
{
    public function test_that_confirmation_destroys_recoverable_ciphertext_idempotently(): void
    {
        $delivery = $this->delivery();

        self::assertInstanceOf(UserId::class, $delivery->getUserId());
        self::assertInstanceOf(PasswordResetDeliveryId::class, $delivery->getId());
        self::assertSame('alice@example.test', $delivery->getEmail());
        self::assertSame('2026-08-20T13:00:00+00:00', $delivery->getExpiresAt()->format(DATE_ATOM));

        $confirmed = $delivery->confirm();

        self::assertFalse($confirmed->isRecoverable());
        self::assertNull($confirmed->getCiphertext());
        self::assertSame($delivery->getId(), $confirmed->getId());
        self::assertSame($confirmed, $confirmed->confirm());
    }

    public function test_that_terminal_expiry_destroys_ciphertext_only_at_or_after_expiry(): void
    {
        $delivery = $this->delivery();

        self::assertSame(
            $delivery,
            $delivery->expireAt(new DateTimeImmutable('2026-08-20T12:59:59+00:00'))
        );
        self::assertTrue($delivery->isRecoverable());

        $expired = $delivery->expireAt(new DateTimeImmutable('2026-08-20T13:00:00+00:00'));

        self::assertFalse($expired->isRecoverable());
        self::assertNull($expired->getCiphertext());
        self::assertSame(
            $expired,
            $expired->expireAt(new DateTimeImmutable('2026-08-20T13:00:01+00:00'))
        );
    }

    private function delivery(): PasswordResetDelivery
    {
        return PasswordResetDelivery::create(
            PasswordResetDeliveryId::fromString('018f6300-4c42-7c43-9f19-9dfac6f7b001'),
            UserId::generate(),
            'alice@example.test',
            'ciphertext',
            new DateTimeImmutable('2026-08-20T13:00:00+00:00')
        );
    }
}
