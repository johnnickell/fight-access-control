<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\Audit;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentDeliveryId;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationId;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationKey;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationScope;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Exception\AgentOperationRejectedException;
use Fight\AccessControl\Domain\AccessControl\Audit\AnonymousId;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditAction;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditAgentMaintenanceOutcome;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditCorrelation;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditSubject;
use Fight\AccessControl\Domain\AccessControl\Audit\SystemId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(AuditAgentMaintenanceOutcome::class)]
#[CoversClass(AuditEvidence::class)]
final class AuditAgentMaintenanceOutcomeTest extends TestCase
{
    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidRepresentations(): iterable
    {
        $valid = self::payload();
        foreach (array_keys($valid) as $field) {
            $missing = $valid;
            unset($missing[$field]);
            yield 'missing '.$field => [$missing];
            yield 'coercive '.$field => [array_replace($valid, [$field => []])];
        }

        yield 'unknown field' => [$valid + ['snapshot' => 'forbidden']];
        yield 'unsupported failure' => [array_replace($valid, ['failure' => 'diagnostic'])];
        yield 'negative predecessor' => [array_replace($valid, ['revision_before' => -1])];
        yield 'overflow predecessor' => [array_replace($valid, ['revision_before' => PHP_INT_MAX])];
        yield 'unchanged revision' => [array_replace($valid, ['revision_after' => 3])];
        yield 'skipped revision' => [array_replace($valid, ['revision_after' => 5])];
    }

    #[DataProvider('invalidRepresentations')]
    public function test_hydration_rejects_missing_coercive_and_nontransition_facts(array $data): void
    {
        $this->expectException(DomainException::class);
        AuditAgentMaintenanceOutcome::fromArray($data);
    }

    public function test_key_version_retains_its_own_strict_label_admission(): void
    {
        $this->expectException(AgentOperationRejectedException::class);
        AuditAgentMaintenanceOutcome::fromArray(array_replace(self::payload(), [
            'key_version_after' => 'unsafe/provider/path'
        ]));
    }

    /** @return iterable<string, array{AuditAction, array<string, mixed>}> */
    public static function contradictoryOutcomes(): iterable
    {
        $valid = self::payload();
        yield 'same key rewrap' => [
            AuditAction::AGENT_MATERIAL_REWRAPPED, array_replace($valid, ['key_version_after' => 'key-v1'])
        ];
        yield 'no source rewrap' => [
            AuditAction::AGENT_MATERIAL_REWRAPPED, array_replace($valid, ['key_version_before' => null])
        ];
        yield 'expiry retains copy' => [AuditAction::AGENT_MATERIAL_EXPIRED, $valid];
        yield 'expiry fabricated source' => [
            AuditAction::AGENT_MATERIAL_EXPIRED,
            array_replace($valid, ['key_version_before' => null, 'key_version_after' => null])
        ];
        yield 'expiry with failure' => [
            AuditAction::AGENT_MATERIAL_EXPIRED,
            array_replace($valid, ['key_version_after' => null, 'failure' => 'temporary'])
        ];
        yield 'failure retains copy' => [AuditAction::AGENT_MATERIAL_FAILED, $valid];
        yield 'failure without source' => [
            AuditAction::AGENT_MATERIAL_FAILED,
            array_replace($valid, [
                'key_version_before' => null, 'key_version_after' => null, 'failure' => 'key_retired'
            ])
        ];
        yield 'transient is not terminal' => [
            AuditAction::AGENT_MATERIAL_FAILED,
            array_replace($valid, ['key_version_after' => null, 'failure' => 'temporary'])
        ];
        yield 'cleanup has material' => [AuditAction::AGENT_SINK_CLEANED, $valid];
        yield 'unrelated action' => [AuditAction::AGENT_PROVISIONED, $valid];
    }

    #[DataProvider('contradictoryOutcomes')]
    public function test_action_admission_rejects_contradictory_outcomes(AuditAction $action, array $data): void
    {
        $this->expectException(DomainException::class);
        AuditAgentMaintenanceOutcome::fromArray($data)->assertOutcome($action);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidEvidence(): iterable
    {
        yield 'anonymous actor' => ['anonymous'];
        yield 'missing original key' => ['key'];
        yield 'wrong subject' => ['subject'];
    }

    #[DataProvider('invalidEvidence')]
    public function test_maintenance_evidence_requires_real_scoped_correlation_and_admitted_actor_subject(
        string $case
    ): void {
        $this->expectException(DomainException::class);
        AuditEvidence::record(
            $case === 'anonymous' ? AnonymousId::generate() : SystemId::generate(),
            new AuditSubject($case === 'subject' ? UserId::generate() : AgentId::generate()),
            AuditAction::AGENT_MATERIAL_REWRAPPED,
            new DateTimeImmutable('2026-10-01T00:00:00+00:00'),
            AuditAgentMaintenanceOutcome::fromArray(self::payload()),
            $case === 'key' ? null : new AuditCorrelation(null, new AgentOperationKey(
                new AgentOperationScope('consumer', 'user', 'caller'),
                AgentOperationId::generate()
            ))
        );
    }

    /** @return array<string, mixed> */
    private static function payload(): array
    {
        return [
            'credential_id'      => AgentCredentialId::generate()->toString(),
            'delivery_id'        => AgentDeliveryId::generate()->toString(),
            'revision_before'    => 3,
            'revision_after'     => 4,
            'key_version_before' => 'key-v1',
            'key_version_after'  => 'key-v2',
            'failure'            => null
        ];
    }
}
