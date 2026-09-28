<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\Agent;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentName;
use Fight\AccessControl\Domain\AccessControl\Agent\Delivery\AgentDeliveryAttempt;
use Fight\AccessControl\Domain\AccessControl\Agent\Delivery\AgentDeliveryAuthority;
use Fight\AccessControl\Domain\AccessControl\Agent\Delivery\AgentDeliveryClaimId;
use Fight\AccessControl\Domain\AccessControl\Agent\Delivery\AgentDeliveryFailure;
use Fight\AccessControl\Domain\AccessControl\Agent\Delivery\AgentDeliveryPolicy;
use Fight\AccessControl\Domain\AccessControl\Agent\Delivery\AgentDeliveryReceipt;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentDeliveryFailedException;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentOperationRejectedException;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentCredentialDestination;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentCredentialOperation;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentDeliveryDisposition;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentDeliveryId;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentDeliveryMaterial;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentDestinationId;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentIssuance;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationId;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationKey;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationScope;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\EncryptedCredentialMaterial;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(AgentCredentialOperation::class)]
#[CoversClass(AgentDeliveryAttempt::class)]
#[CoversClass(AgentDeliveryAuthority::class)]
#[CoversClass(AgentDeliveryPolicy::class)]
#[CoversClass(AgentDeliveryReceipt::class)]
#[CoversClass(AgentDeliveryFailedException::class)]
final class AgentDeliveryTest extends TestCase
{
    public function test_defaults_and_bounded_overrides_have_finite_durations_and_attempts(): void
    {
        $now = $this->now();
        $policy = new AgentDeliveryPolicy();
        self::assertSame([
            'lease_seconds'     => 60,
            'admission_seconds' => 15,
            'retry_seconds'     => 30,
            'retention_seconds' => 86400,
            'maximum_attempts'  => 100
        ], $policy->toArray());
        self::assertEquals($now->modify('+60 seconds'), $policy->leaseUntil($now));
        self::assertEquals($now->modify('+15 seconds'), $policy->admitUntil($now));
        self::assertEquals($now->modify('+30 seconds'), $policy->retryAt($now));
        self::assertEquals($now->modify('+1 day'), $policy->retainUntil($now));
        self::assertTrue($policy->permitsAttempt(100));
        self::assertFalse($policy->permitsAttempt(101));
        $override = new AgentDeliveryPolicy(3600, 3600, 604800, 604800, 1000);
        self::assertEquals($now->modify('+7 days'), $override->retainUntil($now));
        self::assertTrue($override->permitsAttempt(1000));
        self::assertFalse(new AgentDeliveryPolicy(1, 1, 1, 1, 1)->permitsAttempt(2));
    }

    /** @return iterable<string, array{int, int, int, int, int}> */
    public static function invalidPolicies(): iterable
    {
        yield 'lease zero' => [0, 1, 1, 60, 1];
        yield 'lease upper' => [3601, 1, 1, 86400, 1];
        yield 'admission zero' => [60, 0, 1, 60, 1];
        yield 'admission exceeds lease' => [60, 61, 1, 60, 1];
        yield 'retry zero' => [60, 1, 0, 60, 1];
        yield 'retry exceeds retention' => [60, 1, 61, 60, 1];
        yield 'retention below lease' => [60, 1, 1, 59, 1];
        yield 'retention upper' => [60, 1, 1, 604801, 1];
        yield 'attempts zero' => [60, 1, 1, 60, 0];
        yield 'attempts upper' => [60, 1, 1, 60, 1001];
    }

    #[DataProvider('invalidPolicies')]
    public function test_invalid_overrides_cannot_disable_safety(
        int $lease,
        int $admit,
        int $retry,
        int $retain,
        int $max
    ): void {
        $this->expectException(AgentOperationRejectedException::class);
        new AgentDeliveryPolicy($lease, $admit, $retry, $retain, $max);
    }

    public function test_claim_admission_and_completion_preserve_original_binding_but_remove_only_delivery_copy(): void
    {
        $operation = $this->operation();
        $claim = $operation->claimDelivery(AgentDeliveryClaimId::generate(), new AgentDeliveryPolicy(), $this->now());
        self::assertSame(1, $claim->getStateRevision());
        self::assertSame(1, $claim->requireAttempt()->getFence());
        self::assertNull($claim->requireAttempt()->getAuthority());
        self::assertNull($claim->requireAttempt()->getDeadline());
        $authority = new AgentDeliveryAuthority('worker:epoch1', $this->now()->modify('+10 seconds'));
        $admitted = $claim->admitDelivery($claim->requireAttempt(), $authority, $this->now(), 1);
        self::assertSame(2, $admitted->getStateRevision());
        self::assertSame($authority, $admitted->requireAttempt()->getAuthority());
        self::assertEquals($this->now()->modify('+10 seconds'), $admitted->requireAttempt()->getDeadline());
        self::assertSame($operation->getMaterial(), $admitted->getAdmittedMaterial($this->now()));
        $completed = $admitted->finishDelivery(
            $admitted->requireAttempt(),
            $authority,
            new AgentDeliveryReceipt(str_repeat('a', 32)),
            $this->now(),
            2
        );
        self::assertSame(3, $completed->getStateRevision());
        self::assertSame($operation->getIssuance(), $completed->getIssuance());
        self::assertSame($operation->getCanonicalRequest(), $completed->getCanonicalRequest());
        self::assertNull($completed->getMaterial());
        self::assertSame(AgentDeliveryDisposition::DELIVERED, $completed->getStatus()->getDeliveryDisposition());
        self::assertSame($admitted->requireAttempt(), $completed->requireAttempt());
        self::assertSame(str_repeat('a', 32), $completed->getReceipt()?->toString());
        self::assertNull($completed->getDeliveryFailure());
    }

    public function test_admission_uses_the_earliest_policy_lease_authorization_or_retention_deadline(): void
    {
        $cases = [
            [0, 0, 100, 86400, 15],
            [0, 50, 100, 86400, 60],
            [0, 0, 7, 86400, 7],
            [50, 50, 100, 60, 60]
        ];
        foreach ($cases as [$claimSecond, $admitSecond, $expirySecond, $retention, $expected]) {
            $now = $this->now();
            $claim = $this->operation()->claimDelivery(
                AgentDeliveryClaimId::generate(),
                new AgentDeliveryPolicy(retentionSeconds: $retention),
                $now->modify('+'.$claimSecond.' seconds')
            );
            $admitted = $claim->admitDelivery(
                $claim->requireAttempt(),
                new AgentDeliveryAuthority('worker:epoch1', $now->modify('+'.$expirySecond.' seconds')),
                $now->modify('+'.$admitSecond.' seconds'),
                $claim->getStateRevision()
            );
            self::assertEquals($now->modify('+'.$expected.' seconds'), $admitted->requireAttempt()->getDeadline());
        }
    }

    public function test_policy_is_pinned_and_expiry_or_attempt_exhaustion_cannot_be_extended_on_retry(): void
    {
        $now = $this->now();
        $policy = new AgentDeliveryPolicy(5, 3, 2, 10, 1);
        $claim = $this->operation()->claimDelivery(AgentDeliveryClaimId::generate(), $policy, $now);
        $authority = new AgentDeliveryAuthority('worker:epoch1', $now->modify('+1 hour'));
        $admitted = $claim->admitDelivery($claim->requireAttempt(), $authority, $now, 1);
        $retry = $admitted->finishDelivery(
            $admitted->requireAttempt(),
            $authority,
            AgentDeliveryFailure::TEMPORARY,
            $now,
            2
        );
        self::assertSame(AgentDeliveryFailure::TEMPORARY, $retry->getDeliveryFailure());
        self::assertNull($retry->getReceipt());
        self::assertSame($policy, $retry->getDeliveryPolicy());
        self::assertEquals($now->modify('+2 seconds'), $retry->getRetryAt());
        self::assertNotNull($retry->getMaterial());
        $exhausted = $retry->claimDelivery(
            AgentDeliveryClaimId::generate(),
            new AgentDeliveryPolicy(),
            $now->modify('+5 seconds')
        );
        self::assertSame(AgentDeliveryDisposition::TERMINAL, $exhausted->getStatus()->getDeliveryDisposition());
        self::assertNull($exhausted->getMaterial());
        self::assertSame(1, $exhausted->requireAttempt()->getFence());
        $expired = $retry->claimDelivery(
            AgentDeliveryClaimId::generate(),
            new AgentDeliveryPolicy(),
            $now->modify('+10 seconds')
        );
        self::assertSame(AgentDeliveryDisposition::EXPIRED, $expired->getStatus()->getDeliveryDisposition());
        self::assertNull($expired->getMaterial());
    }

    public function test_takeover_advances_fence_and_preserves_delivery_identity(): void
    {
        $claim = $this->operation()->claimDelivery(
            AgentDeliveryClaimId::generate(),
            new AgentDeliveryPolicy(),
            $this->now()
        );
        $next = $claim->claimDelivery(
            AgentDeliveryClaimId::generate(),
            new AgentDeliveryPolicy(),
            $this->now()->modify('+60 seconds')
        );
        self::assertSame(2, $next->requireAttempt()->getFence());
        self::assertFalse($next->requireAttempt()->getClaimId()->equals($claim->requireAttempt()->getClaimId()));
        self::assertSame($claim->getIssuance(), $next->getIssuance());
    }

    /** @return iterable<string, array{string}> */
    public static function rejectedTransitions(): iterable
    {
        foreach (
            [
            'unclaimed material', 'claimed material', 'completed material', 'live lease', 'retry not due',
            'unknown version', 'terminal claim', 'missing claim', 'wrong claim', 'stale admission revision',
            'double admission', 'expired admission', 'unadmitted completion',
            'stale completion revision', 'expired completion', 'retired completion'
            ] as $case
        ) {
            yield $case => [$case];
        }
    }

    #[DataProvider('rejectedTransitions')]
    public function test_invalid_transitions_never_grant_materialization_or_acknowledge_stale_state(string $case): void
    {
        $operation = $this->operation();
        $now = $this->now();
        $policy = new AgentDeliveryPolicy(5, 3, 10, 60);
        $authority = new AgentDeliveryAuthority('worker:epoch1', $now->modify('+1 hour'));
        $claim = $operation->claimDelivery(AgentDeliveryClaimId::generate(), $policy, $now);
        $admitted = $claim->admitDelivery($claim->requireAttempt(), $authority, $now, 1);
        $this->expectException(AgentOperationRejectedException::class);
        match ($case) {
            'unclaimed material' => $operation->getAdmittedMaterial($now),
            'claimed material' => $claim->getAdmittedMaterial($now),
            'completed material' => $admitted->retireMaterial()->getAdmittedMaterial($now),
            'live lease' => $claim->claimDelivery(AgentDeliveryClaimId::generate(), $policy, $now),
            'retry not due' => $admitted->finishDelivery(
                $admitted->requireAttempt(),
                $authority,
                AgentDeliveryFailure::TEMPORARY,
                $now,
                2
            )->claimDelivery(AgentDeliveryClaimId::generate(), $policy, $now->modify('+6 seconds')),
            'unknown version' => $this->operation(2)->claimDelivery(AgentDeliveryClaimId::generate(), $policy, $now),
            'terminal claim' => $operation->retireMaterial()->claimDelivery(
                AgentDeliveryClaimId::generate(),
                $policy,
                $now
            ),
            'missing claim' => $operation->admitDelivery($claim->requireAttempt(), $authority, $now, 0),
            'wrong claim' => $claim->admitDelivery(
                new AgentDeliveryAttempt(1, AgentDeliveryClaimId::generate(), $now->modify('+5 seconds')),
                $authority, $now, 1
            ),
            'stale admission revision' => $claim->admitDelivery($claim->requireAttempt(), $authority, $now, 0),
            'double admission' => $admitted->admitDelivery($claim->requireAttempt(), $authority, $now, 2),
            'expired admission' => $claim->admitDelivery(
                $claim->requireAttempt(),
                $authority,
                $now->modify('+5 seconds'),
                1
            ),
            'unadmitted completion' => $operation->finishDelivery(
                $admitted->requireAttempt(),
                $authority,
                new AgentDeliveryReceipt(str_repeat('a', 32)),
                $now,
                0
            ),
            'stale completion revision' => $admitted->finishDelivery(
                $admitted->requireAttempt(),
                $authority,
                new AgentDeliveryReceipt(str_repeat('a', 32)),
                $now,
                1
            ),
            'expired completion' => $admitted->finishDelivery(
                $admitted->requireAttempt(),
                $authority,
                new AgentDeliveryReceipt(str_repeat('a', 32)),
                $now->modify('+3 seconds'),
                2
            ),
            'retired completion' => $admitted->retireMaterial()->finishDelivery(
                $admitted->requireAttempt(),
                $authority,
                new AgentDeliveryReceipt(str_repeat('a', 32)),
                $now,
                3
            ),
            default => throw new LogicException('Unknown transition scenario.')
        };
    }

    /** @return iterable<string, array{string}> */
    public static function staleAdmissions(): iterable
    {
        $cases = [
            'fence', 'token', 'lease', 'deadline', 'original epoch', 'original expiry',
            'current epoch', 'current expiry'
        ];
        foreach ($cases as $case) {
            yield $case => [$case];
        }
    }

    #[DataProvider('staleAdmissions')]
    public function test_completion_requires_exact_admission_and_fresh_current_authority(string $case): void
    {
        $now = $this->now();
        $id = AgentDeliveryClaimId::generate();
        $authority = new AgentDeliveryAuthority('worker:epoch1', $now->modify('+30 seconds'));
        $attempt = new AgentDeliveryAttempt(
            1,
            $id,
            $now->modify('+60 seconds'),
            $authority,
            $now->modify('+15 seconds')
        );
        $expected = new AgentDeliveryAttempt(
            $case === 'fence' ? 2 : 1,
            $case === 'token' ? AgentDeliveryClaimId::generate() : $id,
            $now->modify($case === 'lease' ? '+59 seconds' : '+60 seconds'),
            new AgentDeliveryAuthority(
                $case === 'original epoch' ? 'worker:epoch2' : 'worker:epoch1',
                $case === 'original expiry' ? $now->modify('+29 seconds') : $authority->getExpiresAt()
            ),
            $now->modify($case === 'deadline' ? '+14 seconds' : '+15 seconds')
        );
        $current = new AgentDeliveryAuthority(
            $case === 'current epoch' ? 'worker:epoch3' : 'worker:epoch1',
            $case === 'current expiry' ? $now : $authority->getExpiresAt()
        );
        $this->expectException(AgentOperationRejectedException::class);
        $attempt->assertCurrent($expected, $current, $now);
    }

    /** @return iterable<string, array{int, bool, bool, int}> */
    public static function malformedAttempts(): iterable
    {
        yield 'zero fence' => [0, false, false, 0];
        yield 'authority without deadline' => [1, true, false, 0];
        yield 'deadline without authority' => [1, false, true, 10];
        yield 'deadline exceeds lease' => [1, true, true, 61];
        yield 'deadline exceeds authorization' => [1, true, true, 31];
    }

    #[DataProvider('malformedAttempts')]
    public function test_malformed_attempt_hydration_rejects(
        int $fence,
        bool $authority,
        bool $deadline,
        int $seconds
    ): void {
        $this->expectException(AgentOperationRejectedException::class);
        new AgentDeliveryAttempt(
            $fence,
            AgentDeliveryClaimId::generate(),
            $this->now()->modify('+60 seconds'),
            $authority ? new AgentDeliveryAuthority('worker:epoch1', $this->now()->modify('+30 seconds')) : null,
            $deadline ? $this->now()->modify('+'.$seconds.' seconds') : null
        );
    }

    public function test_authority_epoch_rejects_unsafe_provider_details(): void
    {
        $this->expectException(AgentOperationRejectedException::class);
        new AgentDeliveryAuthority('/private/key/path', $this->now());
    }

    public function test_receipt_rejects_paths_and_does_not_echo_them(): void
    {
        $this->expectException(AgentDeliveryFailedException::class);
        $this->expectExceptionMessage('Agent delivery failed: invalid_receipt.');
        new AgentDeliveryReceipt('/private/key/path');
    }

    public function test_credential_validation_rejects_missing_revoked_or_different_authority(): void
    {
        $operation = $this->operation();
        $issuance = $operation->getIssuance();
        $agent = Agent::provision(
            $issuance->getAgentId(),
            AgentName::fromString('Worker'),
            $issuance->getCredentialId(),
            'envelope',
            $this->now()
        );
        $operation->assertDeliveryCredential($agent);
        foreach (
            [null, $agent->revoke($this->now()), Agent::provision(
                AgentId::generate(),
                AgentName::fromString('Other'),
                AgentCredentialId::generate(),
                'other',
                $this->now()
            )] as $invalid
        ) {
            try {
                $operation->assertDeliveryCredential($invalid);
                self::fail('Only the original active credential may deliver.');
            } catch (AgentOperationRejectedException) {
                self::assertNotSame($agent, $invalid);
            }
        }
    }

    public function test_due_selection_tracks_lease_retry_and_retention_without_changing_state(): void
    {
        $operation = $this->operation();
        $now = $this->now();
        $authority = new AgentDeliveryAuthority('worker:epoch1', $now->modify('+1 day'));
        self::assertEquals($now, $operation->getDeliveryDueAt());
        self::assertFalse($operation->canReconcileDelivery($authority, $now));
        $claim = $operation->claimDelivery(AgentDeliveryClaimId::generate(), new AgentDeliveryPolicy(), $now);
        self::assertEquals($now->modify('+60 seconds'), $claim->getDeliveryDueAt());
        self::assertFalse($claim->canReconcileDelivery($authority, $now));
        $admission = $claim->admitDelivery($claim->requireAttempt(), $authority, $now, 1);
        self::assertTrue($admission->canReconcileDelivery($authority, $now));
        self::assertFalse($admission->canReconcileDelivery($authority, $now->modify('+15 seconds')));
        $retry = $admission->finishDelivery(
            $admission->requireAttempt(),
            $authority,
            AgentDeliveryFailure::TEMPORARY,
            $now,
            2
        );
        self::assertFalse($retry->canReconcileDelivery($authority, $now));
        self::assertEquals($now->modify('+60 seconds'), $retry->getDeliveryDueAt());
        $delivered = $admission->finishDelivery(
            $admission->requireAttempt(),
            $authority,
            new AgentDeliveryReceipt('opaque-receipt-123'),
            $now,
            2
        );
        self::assertNull($delivered->getDeliveryDueAt());
        self::assertFalse($delivered->canReconcileDelivery($authority, $now));
        self::assertNull($operation->retireMaterial()->getDeliveryDueAt());
        $late = $operation->claimDelivery(
            AgentDeliveryClaimId::generate(),
            new AgentDeliveryPolicy(),
            $now->modify('+86399 seconds')
        );
        self::assertEquals($now->modify('+86400 seconds'), $late->getDeliveryDueAt());
        self::assertSame(0, $operation->getStateRevision());
        self::assertSame(1, $claim->getStateRevision());
        $this->expectException(AgentOperationRejectedException::class);
        $admission->canReconcileDelivery(new AgentDeliveryAuthority('worker:epoch2', $authority->getExpiresAt()), $now);
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-27T12:00:00+00:00');
    }

    private function operation(int $version = 1): AgentCredentialOperation
    {
        return new AgentCredentialOperation(
            $version,
            'original canonical request',
            new AgentIssuance(
                new AgentOperationKey(
                    new AgentOperationScope('consumer', 'user', 'owner'),
                    AgentOperationId::generate()
                ),
                AgentDeliveryId::generate(),
                AgentId::generate(),
                AgentCredentialId::generate(),
                0,
                new AgentCredentialDestination(AgentDestinationId::generate(), 1),
                1,
                $this->now()
            ),
            new AgentDeliveryMaterial(EncryptedCredentialMaterial::fromString('separate-delivery-copy'), 'key-v1')
        );
    }
}
