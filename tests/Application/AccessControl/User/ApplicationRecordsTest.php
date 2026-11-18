<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationCredential;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrant;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationOrigin;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditAction;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditGrantState;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditSubject;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditUserOutcome;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditUserRevisions;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AuditEvidence::class)]
final class ApplicationRecordsTest extends TestCase
{
    public function test_that_audit_evidence_retains_admitted_outcome_facts_without_the_raw_credential(): void
    {
        $at = new DateTimeImmutable('2030-01-01T12:00:00Z');
        $actorId = UserId::generate();
        $user = User::invite(UserId::generate(), EmailAddress::fromString('recipient@example.test'), $at);
        $grant = ActivationGrant::issue(
            ActivationOrigin::PRIVATE_INVITATION,
            $user->getId(),
            ActivationCredential::fromString('synthetic-credential-not-admitted'),
            $at,
            $at->modify('+7 days'),
            $user->getEmail(),
            'synthetic-ciphertext-not-admitted'
        );
        $facts = new AuditUserOutcome(
            null,
            AuditUserRevisions::fromUser($user),
            null,
            AuditGrantState::fromGrant($grant)
        );
        $evidence = AuditEvidence::record(
            $actorId,
            new AuditSubject($user->getId()),
            AuditAction::USER_INVITED,
            $at,
            $facts,
            null
        );
        self::assertSame($actorId, $evidence->actorId());
        self::assertSame(AuditAction::USER_INVITED, $evidence->action());
        self::assertSame($user->getId(), $evidence->getSubject()->getId());
        self::assertSame($facts, $evidence->getFacts());
        self::assertSame($evidence->toArray(), AuditEvidence::fromArray($evidence->toArray())->toArray());
        $serialized = json_encode($evidence->toArray(), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('synthetic-credential', $serialized);
        self::assertStringNotContainsString('synthetic-ciphertext', $serialized);
        self::assertStringNotContainsString('recipient@', $serialized);
    }
}
