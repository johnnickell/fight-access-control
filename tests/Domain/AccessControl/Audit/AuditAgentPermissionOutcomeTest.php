<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\Audit;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditAction;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditAgentPermissionOutcome;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditSubject;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(AuditAgentPermissionOutcome::class)]
#[CoversClass(AuditEvidence::class)]
final class AuditAgentPermissionOutcomeTest extends TestCase
{
    public static function invalidFacts(): iterable
    {
        $id = PermissionId::generate()->toString();
        $valid = [
            'before_revision'        => 1,
            'after_revision'         => 2,
            'granted_permission_ids' => [$id],
            'revoked_permission_ids' => []
        ];
        yield 'missing fields' => [[]];
        yield 'extra fields' => [$valid + ['diagnostic' => 'not admitted']];
        yield 'coercive revision' => [array_replace($valid, ['before_revision' => '1'])];
        yield 'invalid successor type' => [array_replace($valid, ['after_revision' => '2'])];
        yield 'zero revision' => [array_replace($valid, ['before_revision' => 0, 'after_revision' => 1])];
        yield 'overflow' => [array_replace($valid, [
            'before_revision' => PHP_INT_MAX, 'after_revision' => PHP_INT_MAX
        ])];
        yield 'skipped revision' => [array_replace($valid, ['after_revision' => 3])];
        yield 'no change' => [array_replace($valid, ['granted_permission_ids' => []])];
        yield 'duplicate relationship' => [array_replace($valid, ['granted_permission_ids' => [$id, $id]])];
        yield 'overlapping relationships' => [array_replace($valid, ['revoked_permission_ids' => [$id]])];
        yield 'not an array' => [array_replace($valid, ['granted_permission_ids' => $id])];
        yield 'invalid revoked type' => [array_replace($valid, ['revoked_permission_ids' => $id])];
        yield 'not a list' => [array_replace($valid, ['granted_permission_ids' => ['key' => $id]])];
        yield 'not an identity' => [array_replace($valid, ['granted_permission_ids' => [42]])];
    }

    #[DataProvider('invalidFacts')]
    public function test_reconstitution_rejects_incomplete_coercive_or_untruthful_facts(array $facts): void
    {
        $this->expectException(DomainException::class);

        AuditAgentPermissionOutcome::fromArray($facts);
    }

    public function test_action_admission_retains_only_meaningful_action_specific_relationships(): void
    {
        $id = PermissionId::generate();
        $granted = new AuditAgentPermissionOutcome(1, 2, [$id], []);
        $revoked = new AuditAgentPermissionOutcome(2, 3, [], [$id]);
        $replaced = new AuditAgentPermissionOutcome(3, 4, [PermissionId::generate()], [$id]);
        foreach (
            [
                [AuditAction::AGENT_PERMISSION_GRANTED, $granted],
                [AuditAction::AGENT_PERMISSION_REVOKED, $revoked],
                [AuditAction::AGENT_PERMISSIONS_REPLACED, $replaced]
            ] as [$action, $facts]
        ) {
            $evidence = AuditEvidence::record(
                UserId::generate(),
                new AuditSubject(AgentId::generate()),
                $action,
                new DateTimeImmutable('2026-11-13T12:00:00+00:00'),
                $facts,
                null
            );
            self::assertSame($facts, $evidence->getFacts());
            self::assertSame($evidence->toArray(), AuditEvidence::fromArray($evidence->toArray())->toArray());
        }
    }

    public function test_wrong_action_or_subject_cannot_admit_assignment_facts(): void
    {
        foreach (
            [
                [AuditAction::AGENT_PERMISSION_REVOKED, new AuditSubject(AgentId::generate())],
                [AuditAction::AGENT_CREDENTIAL_ROTATED, new AuditSubject(AgentId::generate())],
                [AuditAction::AGENT_PERMISSION_GRANTED, new AuditSubject(UserId::generate())]
            ] as [$action, $subject]
        ) {
            try {
                AuditEvidence::record(
                    UserId::generate(),
                    $subject,
                    $action,
                    new DateTimeImmutable('2026-11-13T12:00:00+00:00'),
                    new AuditAgentPermissionOutcome(1, 2, [PermissionId::generate()], []),
                    null
                );
                self::fail('Unrelated facts cannot enter evidence.');
            } catch (DomainException $failure) {
                self::assertNotSame('', $failure->getMessage());
            }
        }
    }

    public function test_new_evidence_identity_is_not_a_message_deduplication_key(): void
    {
        $actor = UserId::generate();
        $subject = new AuditSubject(AgentId::generate());
        $facts = new AuditAgentPermissionOutcome(1, 2, [PermissionId::generate()], []);
        $time = new DateTimeImmutable('2026-11-13T12:00:00+00:00');
        $first = AuditEvidence::record($actor, $subject, AuditAction::AGENT_PERMISSION_GRANTED, $time, $facts, null);
        $second = AuditEvidence::record($actor, $subject, AuditAction::AGENT_PERMISSION_GRANTED, $time, $facts, null);

        self::assertFalse($first->getId()->equals($second->getId()));
    }
}
