<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\Audit;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Audit\AnonymousId;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditAction;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditAgentNameOutcome;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditCorrelation;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditSubject;
use Fight\AccessControl\Domain\AccessControl\Audit\SystemId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\MessageId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(AuditAgentNameOutcome::class)]
#[CoversClass(AuditEvidence::class)]
final class AuditAgentNameOutcomeTest extends TestCase
{
    public function test_name_evidence_round_trips_typed_origin_exact_time_and_message_without_name_or_revision(): void
    {
        $id = AgentId::generate();
        $time = new DateTimeImmutable('2026-09-27T12:00:03.123456+02:00');
        $correlation = new AuditCorrelation(MessageId::generate(), null);
        $ids = [];
        foreach ([UserId::generate(), AgentId::fromString($id->toString())] as $actor) {
            $record = AuditEvidence::record(
                $actor,
                new AuditSubject($id),
                AuditAction::AGENT_NAME_CHANGED,
                $time,
                new AuditAgentNameOutcome(),
                $correlation
            );
            $copy = AuditEvidence::fromArray($record->toArray());
            self::assertSame($record->toArray(), $copy->toArray());
            self::assertSame($actor::class, $copy->actorId()::class);
            self::assertTrue($actor->equals($copy->actorId()));
            self::assertSame(AgentId::class, $copy->getSubject()->getId()::class);
            self::assertTrue($id->equals($copy->getSubject()->getId()));
            self::assertEquals($time, $copy->getOccurredAt());
            self::assertSame($correlation->toArray(), $copy->getCorrelation()->toArray());
            self::assertSame(['name_changed' => true], $copy->getFacts()->toArray());
            $ids[] = $copy->getId()->toString();
        }

        self::assertNotSame($ids[0], $ids[1]);
    }

    /** @return iterable<string, array{array<array-key, mixed>}> */
    public static function invalidFacts(): iterable
    {
        yield 'missing' => [[]];
        yield 'no change' => [['name_changed' => false]];
        yield 'coerced' => [['name_changed' => 1]];
        yield 'raw name' => [['name_changed' => true, 'name' => 'Private name']];
        yield 'invented revision' => [['name_changed' => true, 'revision' => 1]];
    }

    /** @param array<array-key, mixed> $facts */
    #[DataProvider('invalidFacts')]
    public function test_name_facts_reject_payload_capture_and_unconfirmed_changes(array $facts): void
    {
        $this->expectException(DomainException::class);
        AuditAgentNameOutcome::fromArray($facts);
    }

    public function test_name_facts_cannot_be_relabelled_or_attributed_to_new_actor_kinds(): void
    {
        foreach (['subject', 'action', 'system', 'anonymous'] as $case) {
            try {
                AuditEvidence::record(
                    match ($case) {
                        'system' => SystemId::generate(),
                        'anonymous' => AnonymousId::generate(),
                        default => UserId::generate()
                    },
                    new AuditSubject($case === 'subject' ? UserId::generate() : AgentId::generate()),
                    $case === 'action' ? AuditAction::AGENT_PROVISIONED : AuditAction::AGENT_NAME_CHANGED,
                    new DateTimeImmutable('2026-09-27T12:00:00+00:00'),
                    new AuditAgentNameOutcome(),
                    null
                );
                self::fail('Mismatched name evidence must reject.');
            } catch (DomainException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
