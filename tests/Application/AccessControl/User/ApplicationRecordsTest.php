<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\User\ActivationDeliveryWork;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ActivationDeliveryWork::class)]
#[CoversClass(AuditEvidence::class)]
final class ApplicationRecordsTest extends TestCase
{
    public function test_that_delivery_work_retains_its_typed_owner_and_expiry(): void
    {
        $userId = UserId::generate();
        $expiresAt = new DateTimeImmutable('2026-08-25T12:00:00+00:00');
        $work = ActivationDeliveryWork::create($userId, 'alice@example.test', 'ciphertext', $expiresAt);

        self::assertSame($userId, $work->userId());
        self::assertSame('alice@example.test', $work->email());
        self::assertSame('ciphertext', $work->ciphertext());
        self::assertSame($expiresAt, $work->expiresAt());
    }

    public function test_that_audit_evidence_never_contains_the_raw_credential(): void
    {
        $userId = UserId::generate();
        $evidence = AuditEvidence::record('Admin-42', 'user.invited', $userId);

        self::assertSame('Admin-42', $evidence->actorId());
        self::assertSame('user.invited', $evidence->action());
        self::assertSame($userId, $evidence->userId());
        self::assertSame([], $evidence->context());
    }
}
