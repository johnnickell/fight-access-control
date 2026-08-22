<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\PasswordResetGrant;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\Command\RequestPasswordReset;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\Event\PasswordResetRequested;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetCredential;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetDeliveryId;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrant;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrantId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RequestPasswordReset::class)]
#[CoversClass(PasswordResetCredential::class)]
#[CoversClass(PasswordResetGrant::class)]
#[CoversClass(PasswordResetGrantId::class)]
#[CoversClass(PasswordResetRequested::class)]
final class RequestPasswordResetTest extends TestCase
{
    public function test_that_the_request_command_round_trips_its_canonical_payload(): void
    {
        $command = new RequestPasswordReset(EmailAddress::fromString('Alice@example.test'));

        self::assertSame('Alice@example.test', $command->getEmail()->toString());
        self::assertSame(
            ['email' => 'Alice@example.test'],
            RequestPasswordReset::fromArray($command->toArray())->toArray()
        );
    }

    public function test_that_the_request_command_rejects_a_missing_email(): void
    {
        $this->expectException(DomainException::class);

        RequestPasswordReset::fromArray([]);
    }

    public function test_that_password_reset_authority_is_hashed_purpose_bound_and_expiring(): void
    {
        $userId = UserId::generate();
        $credential = PasswordResetCredential::fromString('reset-once');
        $grant = PasswordResetGrant::issue(
            $userId,
            $credential,
            new DateTimeImmutable('2026-08-20T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-20T13:00:00+00:00'),
            EmailAddress::fromString('alice@example.test'),
            'ciphertext'
        );

        self::assertSame('reset-once', $credential->toString());
        self::assertInstanceOf(PasswordResetGrantId::class, $grant->getId());
        self::assertSame($userId, $grant->getUserId());
        self::assertSame('password_reset', $grant->purpose());
        self::assertSame(
            '05c9d62bb6aa0ab0704c4d5203707b27eacd59b88cc02a64e3c2fee2fb72d890',
            $grant->getCredentialHash()
        );
        self::assertTrue($grant->matchesCredential(PasswordResetCredential::fromString('reset-once')));
        self::assertFalse($grant->matchesCredential(PasswordResetCredential::fromString('wrong-reset')));
        self::assertSame('2026-08-20T13:00:00+00:00', $grant->getExpiresAt()->format(DATE_ATOM));
    }

    public function test_that_password_reset_authority_rejects_empty_credentials_and_non_future_expiry(): void
    {
        try {
            PasswordResetCredential::fromString('');
            self::fail('An empty password-reset credential was accepted.');
        } catch (DomainException $domainException) {
            self::assertSame('The password-reset credential must not be empty.', $domainException->getMessage());
        }

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('The password-reset grant expiry must be later than its issuance time.');

        PasswordResetGrant::issue(
            UserId::generate(),
            PasswordResetCredential::fromString('reset-once'),
            new DateTimeImmutable('2026-08-20T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-20T12:00:00+00:00'),
            EmailAddress::fromString('alice@example.test'),
            'ciphertext'
        );
    }

    public function test_that_password_reset_authority_can_only_be_revoked_once(): void
    {
        $grant = PasswordResetGrant::issue(
            UserId::generate(),
            PasswordResetCredential::fromString('reset-once'),
            new DateTimeImmutable('2026-08-20T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-20T13:00:00+00:00'),
            EmailAddress::fromString('alice@example.test'),
            'ciphertext'
        );
        $revoked = $grant->revoke(new DateTimeImmutable('2026-08-20T12:15:00+00:00'));

        self::assertTrue($grant->isIssued());
        self::assertFalse($grant->isRevoked());
        self::assertNull($grant->getRevokedAt());
        self::assertFalse($revoked->isIssued());
        self::assertTrue($revoked->isRevoked());
        self::assertSame('2026-08-20T12:15:00+00:00', $revoked->getRevokedAt()?->format(DATE_ATOM));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The password-reset grant is no longer issued.');

        $revoked->revoke(new DateTimeImmutable('2026-08-20T12:30:00+00:00'));
    }

    public function test_that_expired_password_reset_authority_cannot_be_consumed(): void
    {
        $grant = PasswordResetGrant::issue(
            UserId::generate(),
            PasswordResetCredential::fromString('reset-once'),
            new DateTimeImmutable('2026-08-20T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-20T13:00:00+00:00'),
            EmailAddress::fromString('alice@example.test'),
            'ciphertext'
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The password-reset grant is no longer usable.');

        $grant->consume(new DateTimeImmutable('2026-08-20T13:00:00+00:00'));
    }

    public function test_that_consumption_destroys_owned_delivery_ciphertext(): void
    {
        $grant = PasswordResetGrant::issue(
            UserId::generate(),
            PasswordResetCredential::fromString('reset-once'),
            new DateTimeImmutable('2026-08-20T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-20T13:00:00+00:00'),
            EmailAddress::fromString('alice@example.test'),
            'ciphertext'
        );

        $consumed = $grant->consume(new DateTimeImmutable('2026-08-20T12:15:00+00:00'));

        self::assertNull($consumed->getDelivery()->getCiphertext());
        self::assertFalse($consumed->getDelivery()->isRecoverable());
    }

    public function test_that_the_password_reset_requested_event_round_trips_without_secret_material(): void
    {
        $userId = UserId::generate();
        $passwordResetDeliveryId = PasswordResetDeliveryId::generate();
        $event = new PasswordResetRequested(
            $userId,
            $passwordResetDeliveryId,
            new DateTimeImmutable('2026-08-20T12:00:00+00:00')
        );

        self::assertSame($userId, $event->getUserId());
        self::assertSame($passwordResetDeliveryId, $event->getPasswordResetDeliveryId());
        self::assertSame('2026-08-20T12:00:00+00:00', $event->getIssuedAt()->format(DATE_ATOM));
        self::assertSame(
            [
                'user_id' => $userId->toString(),
                'password_reset_delivery_id' => $passwordResetDeliveryId->toString(),
                'issued_at' => '2026-08-20T12:00:00+00:00',
            ],
            PasswordResetRequested::fromArray($event->toArray())->toArray()
        );
        self::assertStringNotContainsString('reset-once', implode('|', $event->toArray()));
    }

    public function test_that_the_password_reset_requested_event_rejects_missing_data(): void
    {
        $this->expectException(DomainException::class);

        PasswordResetRequested::fromArray([]);
    }
}
