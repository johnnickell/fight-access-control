<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\AgentCredentialOperation;

use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentCredentialDisposition;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentCredentialOperation;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentDeliveryDisposition;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationFailure;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Delivery\AgentDeliveryAttempt;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Delivery\AgentDeliveryAuthority;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Delivery\AgentDeliveryClaimId;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Delivery\AgentDeliveryFailure;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Delivery\AgentDeliveryPolicy;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Delivery\AgentDeliveryReceipt;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Exception\AgentOperationRejectedException;
use Fight\Test\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery\DeliveryEnvironment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(AgentCredentialOperation::class)]
final class AgentOperationHydrationTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function contradictorySnapshots(): iterable
    {
        foreach (
            ['missing material', 'terminal material', 'retired credential material', 'pending receipt',
            'pending retry time', 'pending failure', 'cleaned pending', 'claim without policy',
            'claim exceeds revision', 'retry without history', 'delivery with retry time',
            'terminal transient failure', 'cleaned current delivery', 'retired receipt'] as $case
        ) {
            yield $case => [$case];
        }
    }

    #[DataProvider('contradictorySnapshots')]
    public function test_hydration_rejects_contradictory_state_without_inventing_history(string $case): void
    {
        $env = new DeliveryEnvironment();
        $original = $env->operation();
        $material = $original->getMaterial();
        $delivery = AgentDeliveryDisposition::PENDING;
        $credential = AgentCredentialDisposition::CURRENT;
        $attempt = null;
        $policy = null;
        $retryAt = null;
        $receipt = null;
        $failure = null;
        $cleaned = false;
        if (
            in_array($case, ['missing material', 'retry without history', 'delivery with retry time',
            'terminal transient failure', 'cleaned current delivery', 'retired receipt'], true)
        ) {
            $material = null;
        }

        if ($case === 'terminal material' || $case === 'terminal transient failure') {
            $delivery = AgentDeliveryDisposition::TERMINAL;
            $policy = new AgentDeliveryPolicy();
            $failure = AgentDeliveryFailure::TEMPORARY;
        }

        if ($case === 'retired credential material') {
            $credential = AgentCredentialDisposition::REVOKED;
        }

        if (in_array($case, ['pending receipt', 'cleaned current delivery', 'retired receipt'], true)) {
            $receipt = new AgentDeliveryReceipt('opaque-receipt-123');
        }

        if ($case === 'pending retry time' || $case === 'delivery with retry time') {
            $retryAt = $env->clock->now();
        }

        if ($case === 'pending failure') {
            $failure = AgentDeliveryFailure::TEMPORARY;
        }

        if ($case === 'cleaned pending' || $case === 'cleaned current delivery') {
            $cleaned = true;
        }

        if ($case === 'claim without policy' || $case === 'claim exceeds revision') {
            $attempt = new AgentDeliveryAttempt(1, AgentDeliveryClaimId::generate(), $env->clock->now());
            if ($case === 'claim exceeds revision') {
                $policy = new AgentDeliveryPolicy();
            }
        }

        if ($case === 'retry without history') {
            $delivery = AgentDeliveryDisposition::RETRYABLE;
        }

        if ($case === 'delivery with retry time' || $case === 'cleaned current delivery') {
            $delivery = AgentDeliveryDisposition::DELIVERED;
        }

        if ($case === 'retired receipt') {
            $delivery = AgentDeliveryDisposition::RETIRED;
        }

        try {
            new AgentCredentialOperation(
                2,
                $original->getCanonicalRequest(),
                $original->getIssuance(),
                $material,
                $delivery,
                $credential,
                0,
                $attempt,
                $policy,
                $retryAt,
                $receipt,
                $failure,
                $cleaned
            );
            self::fail('Contradictory operation snapshot accepted.');
        } catch (AgentOperationRejectedException $agentOperationRejectedException) {
            self::assertSame(AgentOperationFailure::INVALID_REQUEST, $agentOperationRejectedException->getReason());
            self::assertNull($agentOperationRejectedException->getPrevious());
        }
    }

    public function test_hydration_rejects_admission_beyond_original_pinned_retention(): void
    {
        $env = new DeliveryEnvironment();
        $original = $env->operation();
        $policy = new AgentDeliveryPolicy(retentionSeconds: 120);
        $retentionEnd = $policy->retainUntil($original->getIssuance()->getIssuedAt());
        $attempt = new AgentDeliveryAttempt(
            1,
            AgentDeliveryClaimId::generate(),
            $retentionEnd->modify('+60 seconds'),
            new AgentDeliveryAuthority('worker-epoch-1', $retentionEnd->modify('+120 seconds')),
            $retentionEnd->modify('+30 seconds')
        );
        try {
            new AgentCredentialOperation(
                2,
                $original->getCanonicalRequest(),
                $original->getIssuance(),
                $original->getMaterial(),
                stateRevision: 2,
                attempt: $attempt,
                deliveryPolicy: $policy
            );
            self::fail('Hydration extended materialization beyond original retention.');
        } catch (AgentOperationRejectedException $agentOperationRejectedException) {
            self::assertSame(AgentOperationFailure::INVALID_REQUEST, $agentOperationRejectedException->getReason());
            self::assertNull($agentOperationRejectedException->getPrevious());
        }
    }

    public function test_hydrated_normal_admission_preserves_the_exact_original_retention_boundary(): void
    {
        $env = new DeliveryEnvironment();
        $original = $env->operation();
        $policy = new AgentDeliveryPolicy(retentionSeconds: 120);
        $retentionEnd = $policy->retainUntil($original->getIssuance()->getIssuedAt());
        $now = $retentionEnd->modify('-1 second');
        $claim = $original->claimDelivery(AgentDeliveryClaimId::generate(), $policy, $now);
        $admitted = $claim->admitDelivery(
            $claim->requireAttempt(),
            new AgentDeliveryAuthority('worker-epoch-1', $now->modify('+1 hour')),
            $now,
            $claim->getStateRevision()
        );
        $hydrated = new AgentCredentialOperation(
            2,
            $admitted->getCanonicalRequest(),
            $admitted->getIssuance(),
            $admitted->getMaterial(),
            stateRevision: $admitted->getStateRevision(),
            attempt: $admitted->getAttempt(),
            deliveryPolicy: $admitted->getDeliveryPolicy()
        );
        self::assertEquals($retentionEnd, $hydrated->requireAttempt()->getDeadline());
        self::assertSame(
            $original->getMaterial(),
            $hydrated->getAdmittedMaterial($retentionEnd->modify('-1 microsecond'))
        );
        foreach ([$retentionEnd, $retentionEnd->modify('+1 microsecond')] as $late) {
            self::assertTrue($hydrated->isMaterialExpired($late));
            try {
                $hydrated->getAdmittedMaterial($late);
                self::fail('Materialization started at or beyond original retention.');
            } catch (AgentOperationRejectedException $agentOperationRejectedException) {
                self::assertSame(AgentOperationFailure::CONFLICT, $agentOperationRejectedException->getReason());
            }
        }
    }

    public function test_complete_transition_snapshots_rehydrate_losslessly(): void
    {
        $env = new DeliveryEnvironment();
        $original = $env->operation();
        $now = $env->clock->now();
        $claim = $original->claimDelivery(AgentDeliveryClaimId::generate(), new AgentDeliveryPolicy(), $now);
        $authority = new AgentDeliveryAuthority('worker-epoch-1', $now->modify('+1 hour'));
        $admitted = $claim->admitDelivery($claim->requireAttempt(), $authority, $now, $claim->getStateRevision());
        $delivered = $admitted->finishDelivery(
            $admitted->requireAttempt(),
            $authority,
            new AgentDeliveryReceipt('opaque-receipt-123'),
            $now,
            $admitted->getStateRevision()
        );
        $retry = $admitted->finishDelivery(
            $admitted->requireAttempt(),
            $authority,
            AgentDeliveryFailure::TEMPORARY,
            $now,
            $admitted->getStateRevision()
        );
        foreach (
            [$original, $claim, $admitted, $delivered, $retry, $retry->retireMaterial(),
            $original->expireMaterial($now->modify('+1 day')),
            $original->failMaterial(AgentDeliveryFailure::KEY_RETIRED),
            $original->retireMaterial()] as $snapshot
        ) {
            $hydrated = new AgentCredentialOperation(
                $snapshot->getCanonicalVersion(),
                $snapshot->getCanonicalRequest(),
                $snapshot->getIssuance(),
                $snapshot->getMaterial(),
                $snapshot->getDeliveryDisposition(),
                $snapshot->getCredentialDisposition(),
                $snapshot->getStateRevision(),
                $snapshot->getAttempt(),
                $snapshot->getDeliveryPolicy(),
                $snapshot->getRetryAt(),
                $snapshot->getReceipt(),
                $snapshot->getDeliveryFailure(),
                $snapshot->isSinkCleaned()
            );
            self::assertEquals($snapshot, $hydrated);
        }
    }
}
