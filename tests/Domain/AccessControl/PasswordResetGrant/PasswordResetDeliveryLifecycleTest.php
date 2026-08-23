<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\PasswordResetGrant;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetDelivery;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetDeliveryId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Value\Internet\EmailAddress;
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
        self::assertSame('alice@example.test', $delivery->getEmail()->canonical());
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

    public function test_that_delivery_rejects_empty_ciphertext(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('The password-reset delivery ciphertext must not be empty.');

        PasswordResetDelivery::create(
            PasswordResetDeliveryId::generate(),
            UserId::generate(),
            EmailAddress::fromString('alice@example.test'),
            '',
            new DateTimeImmutable('2026-08-20T13:00:00+00:00')
        );
    }

    public function test_that_factory_reconstitution_and_transitions_preserve_the_runtime_subtype(): void
    {
        $id = PasswordResetDeliveryId::generate();
        $userId = UserId::generate();
        $email = EmailAddress::fromString('alice@example.test');
        $expiresAt = new DateTimeImmutable('2026-08-20T13:00:00+00:00');
        $created = ExtensiblePasswordResetDelivery::create($id, $userId, $email, 'ciphertext', $expiresAt);
        $reconstituted = ExtensiblePasswordResetDelivery::reconstitute(
            $id,
            $userId,
            $email,
            'ciphertext',
            $expiresAt
        );

        self::assertInstanceOf(ExtensiblePasswordResetDelivery::class, $created);
        self::assertInstanceOf(ExtensiblePasswordResetDelivery::class, $reconstituted->confirm());
        self::assertInstanceOf(ExtensiblePasswordResetDelivery::class, $created->invalidate());
        self::assertInstanceOf(
            ExtensiblePasswordResetDelivery::class,
            $created->expireAt($expiresAt)
        );
    }

    private function delivery(): PasswordResetDelivery
    {
        return PasswordResetDelivery::create(
            PasswordResetDeliveryId::fromString('018f6300-4c42-7c43-9f19-9dfac6f7b001'),
            UserId::generate(),
            EmailAddress::fromString('alice@example.test'),
            'ciphertext',
            new DateTimeImmutable('2026-08-20T13:00:00+00:00')
        );
    }
}
