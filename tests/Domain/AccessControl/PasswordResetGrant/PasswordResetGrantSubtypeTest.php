<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\PasswordResetGrant;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetCredential;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetDeliveryId;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrant;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrantId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PasswordResetGrant::class)]
final class PasswordResetGrantSubtypeTest extends TestCase
{
    public function test_that_factory_reconstitution_and_all_replacements_preserve_the_runtime_subtype(): void
    {
        $userId = UserId::generate();
        $issuedAt = new DateTimeImmutable('2026-08-20T12:00:00+00:00');
        $expiresAt = new DateTimeImmutable('2026-08-20T13:00:00+00:00');
        $email = EmailAddress::fromString('alice@example.test');
        $grant = ExtensiblePasswordResetGrant::issue(
            $userId,
            PasswordResetCredential::fromString('reset-once'),
            $issuedAt,
            $expiresAt,
            $email,
            'ciphertext'
        );
        $reconstituted = ExtensiblePasswordResetGrant::reconstitute(
            PasswordResetGrantId::generate(),
            $userId,
            hash('sha256', 'reset-reconstituted'),
            $expiresAt,
            ExtensiblePasswordResetDelivery::create(
                PasswordResetDeliveryId::generate(),
                $userId,
                $email,
                'ciphertext',
                $expiresAt
            )
        );

        self::assertInstanceOf(ExtensiblePasswordResetGrant::class, $grant);
        self::assertInstanceOf(ExtensiblePasswordResetGrant::class, $reconstituted);
        self::assertInstanceOf(ExtensiblePasswordResetGrant::class, $grant->confirmDelivery());
        self::assertInstanceOf(ExtensiblePasswordResetGrant::class, $grant->expireDeliveryAt($expiresAt));
        self::assertInstanceOf(ExtensiblePasswordResetGrant::class, $grant->invalidateDelivery());
        self::assertInstanceOf(ExtensiblePasswordResetGrant::class, $grant->consume($issuedAt));
        self::assertInstanceOf(ExtensiblePasswordResetGrant::class, $grant->revoke($issuedAt));
    }
}
