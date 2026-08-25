<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User;

use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AuditEvidence::class)]
final class ApplicationRecordsTest extends TestCase
{
    public function test_that_audit_evidence_never_contains_the_raw_credential(): void
    {
        $userId = UserId::generate();
        $evidence = AuditEvidence::record('Admin-42', 'user.invited', $userId);

        self::assertSame('Admin-42', $evidence->actorId());
        self::assertSame('user.invited', $evidence->action());
        self::assertSame($userId, $evidence->subjectId());
        self::assertSame([], $evidence->context());
    }
}
