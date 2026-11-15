<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\Audit;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentDeliveryDisposition;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentDeliveryId;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationId;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationKey;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationScope;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Delivery\AgentDeliveryFailure;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditAction;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditAgentDeliveryOutcome;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditCorrelation;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditSubject;
use Fight\AccessControl\Domain\AccessControl\Audit\SystemId;
use Fight\Common\Domain\Exception\DomainException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(AuditAgentDeliveryOutcome::class)]
#[CoversClass(AuditEvidence::class)]
final class AuditAgentDeliveryOutcomeTest extends TestCase
{
    /** @return iterable<string, array{AgentDeliveryDisposition, ?AgentDeliveryFailure, AuditAction}> */
    public static function outcomes(): iterable
    {
        yield 'delivered' => [AgentDeliveryDisposition::DELIVERED, null, AuditAction::AGENT_DELIVERY_CONFIRMED];
        yield 'expired' => [AgentDeliveryDisposition::EXPIRED, null, AuditAction::AGENT_DELIVERY_EXPIRED];
        yield 'exhausted' => [AgentDeliveryDisposition::TERMINAL, null, AuditAction::AGENT_DELIVERY_EXHAUSTED];
        foreach (AgentDeliveryFailure::cases() as $failure) {
            yield $failure->value => [
                match ($failure) {
                    AgentDeliveryFailure::TEMPORARY => AgentDeliveryDisposition::RETRYABLE,
                    default => AgentDeliveryDisposition::TERMINAL
                },
                $failure,
                AuditAction::AGENT_DELIVERY_FAILED
            ];
        }
    }

    #[DataProvider('outcomes')]
    public function test_typed_outcomes_round_trip_with_real_relationships_and_scoped_correlation(
        AgentDeliveryDisposition $after,
        ?AgentDeliveryFailure $failure,
        AuditAction $action
    ): void {
        $facts = new AuditAgentDeliveryOutcome(
            AgentCredentialId::generate(),
            0,
            AgentDeliveryId::generate(),
            0,
            1,
            AgentDeliveryDisposition::PENDING,
            $after,
            $failure
        );
        $evidence = AuditEvidence::record(
            SystemId::generate(),
            new AuditSubject(AgentId::generate()),
            $action,
            new DateTimeImmutable('2026-11-15T10:00:00.123456Z'),
            $facts,
            new AuditCorrelation(null, new AgentOperationKey(
                new AgentOperationScope('example', 'user', 'owner'),
                AgentOperationId::generate()
            ))
        );
        self::assertEquals($facts, AuditAgentDeliveryOutcome::fromArray($facts->toArray()));
        self::assertSame($facts, $evidence->getFacts());
        self::assertEquals($evidence, AuditEvidence::fromArray($evidence->toArray()));
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function invalidFields(): iterable
    {
        yield 'negative credential revision' => ['credential_revision', -1];
        yield 'negative before' => ['before_revision', -1];
        yield 'overflow before' => ['before_revision', PHP_INT_MAX];
        yield 'skipped revision' => ['after_revision', 3];
        yield 'no revision advance' => ['after_revision', 0];
        yield 'terminal predecessor' => ['before', 'terminal'];
        yield 'unknown predecessor' => ['before', 'unknown'];
        yield 'unknown successor' => ['after', 'unknown'];
        yield 'temporary claim' => ['after', 'pending'];
        yield 'retirement' => ['after', 'retired'];
        yield 'failure on success' => ['failure', 'temporary'];
        yield 'unknown failure' => ['failure', 'diagnostic'];
        yield 'coercive credential revision' => ['credential_revision', '0'];
        yield 'coercive before revision' => ['before_revision', '0'];
        yield 'coercive after revision' => ['after_revision', '1'];
        yield 'coercive failure' => ['failure', []];
        yield 'coercive credential' => ['credential_id', []];
        yield 'coercive delivery' => ['delivery_id', []];
        yield 'coercive predecessor' => ['before', []];
        yield 'coercive successor' => ['after', []];
    }

    #[DataProvider('invalidFields')]
    public function test_hydration_rejects_incoherent_unknown_and_coercive_facts(string $field, mixed $value): void
    {
        $data = $this->facts()->toArray();
        $data[$field] = $value;
        $this->expectException(DomainException::class);
        AuditAgentDeliveryOutcome::fromArray($data);
    }

    public function test_complete_shape_is_required_without_legacy_defaults_or_extra_payloads(): void
    {
        foreach (array_keys($this->facts()->toArray()) as $field) {
            $data = $this->facts()->toArray();
            unset($data[$field]);
            try {
                AuditAgentDeliveryOutcome::fromArray($data);
                self::fail('Every persisted field is required.');
            } catch (DomainException) {
                self::assertTrue(true);
            }
        }

        $this->expectException(DomainException::class);
        AuditAgentDeliveryOutcome::fromArray($this->facts()->toArray() + ['diagnostics' => 'not admitted']);
    }

    public function test_failure_classification_cannot_claim_retry_or_exhaustion_incorrectly(): void
    {
        foreach ([['retryable', null], ['terminal', 'temporary'], ['expired', 'temporary']] as [$after, $failure]) {
            $data = $this->facts()->toArray();
            $data['after'] = $after;
            $data['failure'] = $failure;
            try {
                AuditAgentDeliveryOutcome::fromArray($data);
                self::fail('The transition must admit its actual outcome.');
            } catch (DomainException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_action_mismatch_is_rejected(): void
    {
        $this->expectException(DomainException::class);
        $this->facts()->assertOutcome(AuditAction::AGENT_DELIVERY_FAILED);
    }

    public function test_delivery_evidence_requires_scoped_operation_correlation(): void
    {
        $this->expectException(DomainException::class);
        AuditEvidence::record(
            SystemId::generate(),
            new AuditSubject(AgentId::generate()),
            AuditAction::AGENT_DELIVERY_CONFIRMED,
            new DateTimeImmutable(),
            $this->facts(),
            null
        );
    }

    private function facts(): AuditAgentDeliveryOutcome
    {
        return new AuditAgentDeliveryOutcome(
            AgentCredentialId::generate(),
            0,
            AgentDeliveryId::generate(),
            0,
            1,
            AgentDeliveryDisposition::PENDING,
            AgentDeliveryDisposition::DELIVERED,
            null
        );
    }
}
