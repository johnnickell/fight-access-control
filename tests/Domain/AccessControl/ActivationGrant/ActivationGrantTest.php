<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\ActivationGrant;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationCredential;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationDeliveryId;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationDeliveryStatus;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrant;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrantId;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Exception\ActivationGrantException;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(User::class)]
#[CoversClass(ActivationCredential::class)]
#[CoversClass(ActivationGrant::class)]
#[CoversClass(ActivationGrantException::class)]
#[CoversClass(UserId::class)]
final class ActivationGrantTest extends TestCase
{
    public function test_that_owned_delivery_transitions_preserve_the_aggregate_generation(): void
    {
        $grant = ActivationGrant::issue(
            UserId::generate(),
            ActivationCredential::fromString('activate-once'),
            new DateTimeImmutable('2026-08-18T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-25T12:00:00+00:00'),
            EmailAddress::fromString('alice@example.test'),
            'ciphertext'
        );

        $failed = $grant->claimDelivery()->failDelivery();
        self::assertSame($grant->getId(), $failed->getId());
        self::assertTrue($failed->getDelivery()->isRetryable());
        self::assertFalse($grant->expireDeliveryAt(new DateTimeImmutable('2026-08-25T11:00:00+00:00'))
            ->getDelivery()->getStatus() === ActivationDeliveryStatus::EXPIRED);
        self::assertSame(
            ActivationDeliveryStatus::EXPIRED,
            $failed->expireDeliveryAt(new DateTimeImmutable('2026-08-25T12:00:00+00:00'))
                ->getDelivery()
                ->getStatus()
        );
        self::assertSame(
            ActivationDeliveryStatus::CONFIRMED,
            $grant->claimDelivery()->confirmDelivery()->getDelivery()->getStatus()
        );
    }

    public function test_an_invited_user_has_a_canonical_email_and_pending_state(): void
    {
        $user = User::invite(
            UserId::generate(),
            EmailAddress::fromString('Alice@Example.Test'),
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        );

        self::assertSame('alice@example.test', $user->getEmail()->canonical());
        self::assertNotSame('', $user->getId()->toString());
        self::assertSame(UserState::PENDING_ACTIVATION, $user->getState());
        self::assertSame(1, $user->getAuthenticationVersion());
    }

    public function test_an_activation_grant_retains_only_a_hash_of_its_raw_credential(): void
    {
        $grant = ActivationGrant::issue(
            UserId::generate(),
            ActivationCredential::fromString('activate-once'),
            new DateTimeImmutable('2026-08-18T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-25T12:00:00+00:00'),
            EmailAddress::fromString('alice@example.test'),
            'ciphertext'
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
            ActivationCredential::fromString('activate-once'),
            new DateTimeImmutable('2026-08-18T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-25T12:00:00+00:00'),
            EmailAddress::fromString('alice@example.test'),
            'ciphertext'
        );
        $consumed = $grant->consume(new DateTimeImmutable('2026-08-20T12:00:00+00:00'));

        self::assertTrue($grant->isIssued());
        self::assertTrue($grant->isUsableAt(new DateTimeImmutable('2026-08-20T12:00:00+00:00')));
        self::assertTrue($consumed->isConsumed());
        self::assertFalse($consumed->isIssued());
        self::assertFalse($consumed->isUsableAt(new DateTimeImmutable('2026-08-20T12:00:00+00:00')));
        self::assertSame('2026-08-20T12:00:00+00:00', $consumed->getConsumedAt()?->format(DATE_ATOM));

        $this->expectException(ActivationGrantException::class);

        $consumed->consume(new DateTimeImmutable('2026-08-20T12:01:00+00:00'));
    }

    public function test_an_activation_grant_cannot_be_consumed_after_expiry_or_revocation(): void
    {
        $grant = ActivationGrant::issue(
            UserId::generate(),
            ActivationCredential::fromString('activate-once'),
            new DateTimeImmutable('2026-08-18T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-25T12:00:00+00:00'),
            EmailAddress::fromString('alice@example.test'),
            'ciphertext'
        );
        $revoked = $grant->revoke(new DateTimeImmutable('2026-08-20T12:00:00+00:00'));

        self::assertTrue($revoked->isRevoked());
        self::assertFalse($revoked->isUsableAt(new DateTimeImmutable('2026-08-20T12:00:00+00:00')));
        self::assertSame('2026-08-20T12:00:00+00:00', $revoked->getRevokedAt()?->format(DATE_ATOM));

        $this->expectException(ActivationGrantException::class);

        $revoked->consume(new DateTimeImmutable('2026-08-20T12:01:00+00:00'));
    }

    public function test_a_consumed_activation_grant_cannot_be_revoked_again(): void
    {
        $grant = ActivationGrant::issue(
            UserId::generate(),
            ActivationCredential::fromString('activate-once'),
            new DateTimeImmutable('2026-08-18T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-25T12:00:00+00:00'),
            EmailAddress::fromString('alice@example.test'),
            'ciphertext'
        );
        $consumed = $grant->consume(new DateTimeImmutable('2026-08-20T12:00:00+00:00'));

        $this->expectException(ActivationGrantException::class);

        $consumed->revoke(new DateTimeImmutable('2026-08-20T12:01:00+00:00'));
    }

    public function test_an_expired_activation_grant_is_not_usable_or_consumable(): void
    {
        $grant = ActivationGrant::issue(
            UserId::generate(),
            ActivationCredential::fromString('activate-once'),
            new DateTimeImmutable('2026-08-18T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-25T12:00:00+00:00'),
            EmailAddress::fromString('alice@example.test'),
            'ciphertext'
        );

        self::assertFalse($grant->isUsableAt(new DateTimeImmutable('2026-08-25T12:00:00+00:00')));

        $this->expectException(ActivationGrantException::class);

        $grant->consume(new DateTimeImmutable('2026-08-25T12:00:00+00:00'));
    }

    public function test_an_activation_grant_rejects_an_expiry_at_or_before_its_issuance_time(): void
    {
        $issuedAt = new DateTimeImmutable('2026-08-18T12:00:00+00:00');

        $this->expectException(ActivationGrantException::class);

        ActivationGrant::issue(
            UserId::generate(),
            ActivationCredential::fromString('activate-once'),
            $issuedAt,
            $issuedAt,
            EmailAddress::fromString('alice@example.test'),
            'ciphertext'
        );
    }

    public function test_that_factory_reconstitution_and_all_replacements_preserve_the_runtime_subtype(): void
    {
        $userId = UserId::generate();
        $issuedAt = new DateTimeImmutable('2026-08-18T12:00:00+00:00');
        $expiresAt = new DateTimeImmutable('2026-08-25T12:00:00+00:00');
        $grant = ExtensibleActivationGrant::issue(
            $userId,
            ActivationCredential::fromString('activate-once'),
            $issuedAt,
            $expiresAt,
            EmailAddress::fromString('alice@example.test'),
            'ciphertext'
        );
        $reconstituted = ExtensibleActivationGrant::reconstitute(
            ActivationGrantId::generate(),
            $userId,
            hash('sha256', 'activate-reconstituted'),
            $expiresAt,
            ExtensibleActivationDelivery::create(
                ActivationDeliveryId::generate(),
                $userId,
                EmailAddress::fromString('alice@example.test'),
                'ciphertext',
                $expiresAt
            )
        );

        self::assertInstanceOf(ExtensibleActivationGrant::class, $grant);
        self::assertInstanceOf(ExtensibleActivationGrant::class, $reconstituted);
        $failed = $grant->claimDelivery()->failDelivery();
        self::assertInstanceOf(ExtensibleActivationGrant::class, $failed);
        self::assertInstanceOf(
            ExtensibleActivationGrant::class,
            $failed->requestDeliveryRetry()->claimDelivery()->confirmDelivery()
        );
        self::assertInstanceOf(
            ExtensibleActivationGrant::class,
            $reconstituted->expireDeliveryAt($expiresAt)
        );
        self::assertInstanceOf(ExtensibleActivationGrant::class, $grant->consume($issuedAt));
        self::assertInstanceOf(ExtensibleActivationGrant::class, $grant->revoke($issuedAt));
    }
}
