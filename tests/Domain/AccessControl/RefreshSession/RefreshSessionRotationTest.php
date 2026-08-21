<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\RefreshSession;

use DateInterval;
use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshCredential;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSession;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RefreshSession::class)]
final class RefreshSessionRotationTest extends TestCase
{
    private const string CURRENT_CREDENTIAL = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    private const string ROTATED_CREDENTIAL = 'abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789';

    private const string SECOND_ROTATED_CREDENTIAL = '1111111111111111111111111111111111111111111111111111111111111111';

    public function test_that_rotation_advances_activity_and_idle_expiry_without_extending_absolute_expiry(): void
    {
        $sessionId = RefreshSessionId::generate();
        $userId = UserId::generate();
        $session = RefreshSession::start(
            $sessionId,
            $userId,
            RefreshCredential::fromString(self::CURRENT_CREDENTIAL),
            new DateTimeImmutable('2026-08-19T11:00:00+00:00'),
            new DateTimeImmutable('2026-08-20T11:00:00+00:00'),
            new DateTimeImmutable('2026-08-21T11:00:00+00:00'),
            3,
            false
        );

        $rotated = $session->rotate(
            RefreshCredential::fromString(self::ROTATED_CREDENTIAL),
            new DateTimeImmutable('2026-08-19T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-20T12:00:00+00:00')
        );

        self::assertNotSame($session, $rotated);
        self::assertSame($sessionId, $rotated->getId());
        self::assertSame($userId, $rotated->getUserId());
        self::assertTrue($rotated->matchesCredential(RefreshCredential::fromString(self::ROTATED_CREDENTIAL)));
        self::assertFalse($rotated->matchesCredential(RefreshCredential::fromString(self::CURRENT_CREDENTIAL)));
        self::assertEquals(
            new DateTimeImmutable('2026-08-19T12:00:00+00:00'),
            $rotated->getLastActivityAt()
        );
        self::assertEquals(
            new DateTimeImmutable('2026-08-20T12:00:00+00:00'),
            $rotated->getIdleExpiresAt()
        );
        self::assertEquals(
            new DateTimeImmutable('2026-08-21T11:00:00+00:00'),
            $rotated->getAbsoluteExpiresAt()
        );
        self::assertSame(3, $rotated->getAuthenticationVersion());
        self::assertFalse($rotated->isRemembered());
        self::assertFalse($rotated->isRevoked());
        self::assertSame(0, $session->getRevision());
        self::assertSame(1, $rotated->getRevision());
    }

    public function test_that_revocation_returns_an_immutable_next_revision(): void
    {
        $session = RefreshSession::start(
            RefreshSessionId::generate(),
            UserId::generate(),
            RefreshCredential::fromString(self::CURRENT_CREDENTIAL),
            new DateTimeImmutable('2026-08-19T11:00:00+00:00'),
            new DateTimeImmutable('2026-08-20T11:00:00+00:00'),
            new DateTimeImmutable('2026-08-21T11:00:00+00:00'),
            1,
            false
        );

        $revoked = $session->revoke();

        self::assertNotSame($session, $revoked);
        self::assertFalse($session->isRevoked());
        self::assertSame(0, $session->getRevision());
        self::assertTrue($revoked->isRevoked());
        self::assertSame(1, $revoked->getRevision());
        self::assertSame($session->getCredentialDigest(), $revoked->getCredentialDigest());
    }

    public function test_that_rotation_retains_used_digests_but_only_the_latest_is_conflict_eligible(): void
    {
        $session = RefreshSession::start(
            RefreshSessionId::generate(),
            UserId::generate(),
            RefreshCredential::fromString(self::CURRENT_CREDENTIAL),
            new DateTimeImmutable('2026-08-19T11:00:00+00:00'),
            new DateTimeImmutable('2026-08-20T11:00:00+00:00'),
            new DateTimeImmutable('2026-08-21T11:00:00+00:00'),
            1,
            false
        );
        $firstRotation = $session->rotate(
            RefreshCredential::fromString(self::ROTATED_CREDENTIAL),
            new DateTimeImmutable('2026-08-19T11:30:00+00:00'),
            new DateTimeImmutable('2026-08-20T11:30:00+00:00')
        );
        $secondRotation = $firstRotation->rotate(
            RefreshCredential::fromString(self::SECOND_ROTATED_CREDENTIAL),
            new DateTimeImmutable('2026-08-19T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-20T12:00:00+00:00')
        );
        $currentCredential = RefreshCredential::fromString(self::CURRENT_CREDENTIAL);
        $rotatedCredential = RefreshCredential::fromString(self::ROTATED_CREDENTIAL);

        self::assertTrue($secondRotation->matchesUsedCredential($currentCredential));
        self::assertTrue($secondRotation->matchesUsedCredential($rotatedCredential));
        self::assertFalse($secondRotation->matchesUsedCredential(
            RefreshCredential::fromString(self::SECOND_ROTATED_CREDENTIAL)
        ));
        self::assertFalse($secondRotation->matchesMostRecentlyUsedCredentialWithin(
            $currentCredential,
            new DateTimeImmutable('2026-08-19T12:00:01+00:00'),
            new DateInterval('PT5S')
        ));
        self::assertTrue($secondRotation->matchesMostRecentlyUsedCredentialWithin(
            $rotatedCredential,
            new DateTimeImmutable('2026-08-19T12:00:01+00:00'),
            new DateInterval('PT5S')
        ));
        self::assertFalse($secondRotation->matchesMostRecentlyUsedCredentialWithin(
            $rotatedCredential,
            new DateTimeImmutable('2026-08-19T12:00:05+00:00'),
            new DateInterval('PT5S')
        ));
        self::assertSame(2, $secondRotation->getRevision());
    }

    public function test_that_rotation_rejects_an_idle_deadline_beyond_the_absolute_lifetime(): void
    {
        $session = RefreshSession::start(
            RefreshSessionId::generate(),
            UserId::generate(),
            RefreshCredential::fromString(self::CURRENT_CREDENTIAL),
            new DateTimeImmutable('2026-08-19T11:00:00+00:00'),
            new DateTimeImmutable('2026-08-20T11:00:00+00:00'),
            new DateTimeImmutable('2026-08-21T11:00:00+00:00'),
            1,
            false
        );

        $this->expectException(InvalidArgumentException::class);
        $session->rotate(
            RefreshCredential::fromString(self::ROTATED_CREDENTIAL),
            new DateTimeImmutable('2026-08-19T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-21T12:00:00+00:00')
        );
    }

    public function test_that_rotation_rejects_activity_at_the_idle_expiry(): void
    {
        $session = RefreshSession::start(
            RefreshSessionId::generate(),
            UserId::generate(),
            RefreshCredential::fromString(self::CURRENT_CREDENTIAL),
            new DateTimeImmutable('2026-08-19T11:00:00+00:00'),
            new DateTimeImmutable('2026-08-19T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-21T11:00:00+00:00'),
            1,
            false
        );

        $this->expectException(InvalidArgumentException::class);
        $session->rotate(
            RefreshCredential::fromString(self::ROTATED_CREDENTIAL),
            new DateTimeImmutable('2026-08-19T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-20T12:00:00+00:00')
        );
    }

    public function test_that_rotation_rejects_activity_after_the_idle_expiry(): void
    {
        $session = RefreshSession::start(
            RefreshSessionId::generate(),
            UserId::generate(),
            RefreshCredential::fromString(self::CURRENT_CREDENTIAL),
            new DateTimeImmutable('2026-08-19T11:00:00+00:00'),
            new DateTimeImmutable('2026-08-19T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-21T11:00:00+00:00'),
            1,
            false
        );

        $this->expectException(InvalidArgumentException::class);
        $session->rotate(
            RefreshCredential::fromString(self::ROTATED_CREDENTIAL),
            new DateTimeImmutable('2026-08-19T12:00:01+00:00'),
            new DateTimeImmutable('2026-08-20T12:00:00+00:00')
        );
    }

    public function test_that_rotation_rejects_activity_at_the_absolute_expiry(): void
    {
        $session = RefreshSession::start(
            RefreshSessionId::generate(),
            UserId::generate(),
            RefreshCredential::fromString(self::CURRENT_CREDENTIAL),
            new DateTimeImmutable('2026-08-19T11:00:00+00:00'),
            new DateTimeImmutable('2026-08-21T11:00:00+00:00'),
            new DateTimeImmutable('2026-08-21T11:00:00+00:00'),
            1,
            false
        );

        $this->expectException(InvalidArgumentException::class);
        $session->rotate(
            RefreshCredential::fromString(self::ROTATED_CREDENTIAL),
            new DateTimeImmutable('2026-08-21T11:00:00+00:00'),
            new DateTimeImmutable('2026-08-21T11:00:01+00:00')
        );
    }

    public function test_that_rotation_rejects_activity_earlier_than_the_previous_activity(): void
    {
        $session = RefreshSession::start(
            RefreshSessionId::generate(),
            UserId::generate(),
            RefreshCredential::fromString(self::CURRENT_CREDENTIAL),
            new DateTimeImmutable('2026-08-19T11:00:00+00:00'),
            new DateTimeImmutable('2026-08-20T11:00:00+00:00'),
            new DateTimeImmutable('2026-08-21T11:00:00+00:00'),
            1,
            false
        );
        $rotated = $session->rotate(
            RefreshCredential::fromString(self::ROTATED_CREDENTIAL),
            new DateTimeImmutable('2026-08-19T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-20T12:00:00+00:00')
        );

        $this->expectException(InvalidArgumentException::class);
        $rotated->rotate(
            RefreshCredential::fromString(self::SECOND_ROTATED_CREDENTIAL),
            new DateTimeImmutable('2026-08-19T11:59:59+00:00'),
            new DateTimeImmutable('2026-08-20T12:00:00+00:00')
        );
    }
}
