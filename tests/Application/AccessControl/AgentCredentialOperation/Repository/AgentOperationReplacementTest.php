<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\AgentCredentialOperation\Repository;

use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentCredentialOperation;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentDeliveryDisposition;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentDeliveryMaterial;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationFailure;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Delivery\AgentDeliveryAttempt;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Delivery\AgentDeliveryAuthority;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Delivery\AgentDeliveryClaimId;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Delivery\AgentDeliveryFailure;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Delivery\AgentDeliveryPolicy;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Delivery\AgentDeliveryReceipt;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Exception\AgentOperationRejectedException;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\EncryptedCredentialMaterial;
use Fight\Test\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery\DeliveryEnvironment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(AgentCredentialOperation::class)]
final class AgentOperationReplacementTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function inconsistentSuccessors(): iterable
    {
        yield 'request' => ['request'];
        yield 'marker' => ['marker'];
        yield 'delivery material swap' => ['material'];
        yield 'maintenance claim reset' => ['claim'];
        yield 'delivery claim reset' => ['delivery claim'];
    }

    /** @return iterable<string, array{string}> */
    public static function invalidDeliveryHistory(): iterable
    {
        foreach (
            ['drop attempt', 'drop policy', 'change policy', 'change claim', 'change lease',
            'remove admission', 'change authority', 'renew deadline', 'rewind fence', 'skip fence',
            'reuse claim', 'takeover with admission', 'short takeover lease', 'outcome changes claim',
            'unexhausted terminal', 'unchanged pending'] as $case
        ) {
            yield $case => [$case];
        }
    }

    #[DataProvider('invalidDeliveryHistory')]
    public function test_delivery_writer_rejects_non_successor_claim_policy_and_admission_history(string $case): void
    {
        $env = new DeliveryEnvironment();
        $now = $env->clock->now();
        $policy = new AgentDeliveryPolicy();
        $first = $env->operation()->claimDelivery(AgentDeliveryClaimId::generate(), $policy, $now);
        $now = $now->modify('+60 seconds');
        $claim = $first->claimDelivery(AgentDeliveryClaimId::generate(), $policy, $now);
        $authority = new AgentDeliveryAuthority('worker-epoch-1', $now->modify('+1 hour'));
        $original = $claim->admitDelivery($claim->requireAttempt(), $authority, $now, $claim->getStateRevision());
        $env->provisioning->operationRepository->operations[$env->provisioning->key->toString()] = $original;
        $prior = $original->requireAttempt();
        $attempt = $prior;
        $material = $original->getMaterial();
        $disposition = AgentDeliveryDisposition::PENDING;
        $receipt = null;
        if ($case === 'drop attempt' || $case === 'drop policy') {
            $attempt = null;
            if ($case === 'drop policy') {
                $policy = null;
            }
        } elseif ($case === 'change policy') {
            $policy = new AgentDeliveryPolicy(retentionSeconds: 172800);
        } elseif ($case !== 'unchanged pending') {
            $fence = $prior->getFence();
            $claimId = $prior->getClaimId();
            $lease = $prior->getLeaseUntil();
            $deadline = $prior->getDeadline();
            if ($case === 'change claim' || $case === 'outcome changes claim') {
                $claimId = AgentDeliveryClaimId::generate();
            }

            if ($case === 'change lease') {
                $lease = $lease->modify('+1 second');
            }

            if ($case === 'remove admission') {
                $authority = null;
                $deadline = null;
            }

            if ($case === 'change authority') {
                $authority = new AgentDeliveryAuthority('worker-epoch-2', $now->modify('+1 hour'));
            }

            if ($case === 'renew deadline') {
                self::assertNotNull($deadline);
                $deadline = $deadline->modify('+1 second');
            }

            if (
                in_array($case, ['rewind fence', 'skip fence', 'reuse claim',
                'takeover with admission', 'short takeover lease'], true)
            ) {
                $fence = match ($case) {
                    'rewind fence' => 1,
                    'skip fence' => 4,
                    default => 3
                };
                $lease = $lease->modify('+60 seconds');
                if ($case === 'short takeover lease') {
                    $lease = $lease->modify('-1 microsecond');
                }

                if ($case !== 'reuse claim') {
                    $claimId = AgentDeliveryClaimId::generate();
                }

                if ($case !== 'takeover with admission') {
                    $authority = null;
                    $deadline = null;
                }
            }

            $attempt = new AgentDeliveryAttempt($fence, $claimId, $lease, $authority, $deadline);
            if ($case === 'outcome changes claim') {
                $material = null;
                $disposition = AgentDeliveryDisposition::DELIVERED;
                $receipt = new AgentDeliveryReceipt('opaque-receipt-123');
            }

            if ($case === 'unexhausted terminal') {
                $material = null;
                $disposition = AgentDeliveryDisposition::TERMINAL;
            }
        }

        $replacement = new AgentCredentialOperation(
            2,
            $original->getCanonicalRequest(),
            $original->getIssuance(),
            $material,
            $disposition,
            stateRevision: $original->getStateRevision() + 1,
            attempt: $attempt,
            deliveryPolicy: $policy,
            receipt: $receipt
        );
        try {
            $this->persistDelivery($env, $original, $replacement);
            self::fail('A non-successor delivery history was persisted.');
        } catch (AgentOperationRejectedException $agentOperationRejectedException) {
            self::assertSame(AgentOperationFailure::CONFLICT, $agentOperationRejectedException->getReason());
            self::assertNull($agentOperationRejectedException->getPrevious());
            self::assertSame($original, $env->operation());
            self::assertSame(0, $env->provisioning->operationRepository->deliveryWrites);
        }
    }

    public function test_delivery_writer_preserves_real_claim_takeover_admission_and_outcome_successors(): void
    {
        foreach ([new AgentDeliveryReceipt('opaque-receipt-123'), ...AgentDeliveryFailure::cases()] as $outcome) {
            $env = new DeliveryEnvironment();
            $now = $env->clock->now();
            $policy = new AgentDeliveryPolicy();
            $original = $env->operation();
            $claim = $original->claimDelivery(AgentDeliveryClaimId::generate(), $policy, $now);
            $this->persistDelivery($env, $original, $claim);
            $now = $now->modify('+60 seconds');
            $takeover = $claim->claimDelivery(AgentDeliveryClaimId::generate(), new AgentDeliveryPolicy(), $now);
            $this->persistDelivery($env, $claim, $takeover);
            self::assertSame(2, $takeover->requireAttempt()->getFence());
            $authority = new AgentDeliveryAuthority('worker-epoch-1', $now->modify('+1 hour'));
            $admitted = $takeover->admitDelivery(
                $takeover->requireAttempt(),
                $authority,
                $now,
                $takeover->getStateRevision()
            );
            $this->persistDelivery($env, $takeover, $admitted);
            $finished = $admitted->finishDelivery(
                $admitted->requireAttempt(),
                $authority,
                $outcome,
                $now,
                $admitted->getStateRevision()
            );
            $this->persistDelivery($env, $admitted, $finished);
            self::assertSame($finished, $env->operation());
            self::assertSame($admitted->getAttempt(), $finished->getAttempt());
            self::assertSame($policy, $finished->getDeliveryPolicy());
            if ($outcome === AgentDeliveryFailure::TEMPORARY) {
                $retry = $finished->claimDelivery(
                    AgentDeliveryClaimId::generate(),
                    new AgentDeliveryPolicy(retentionSeconds: 172800),
                    $now->modify('+60 seconds')
                );
                $this->persistDelivery($env, $finished, $retry);
                self::assertSame(3, $retry->requireAttempt()->getFence());
                self::assertSame($policy, $retry->getDeliveryPolicy());
            }
        }
    }

    public function test_delivery_writer_preserves_expiry_and_attempt_exhaustion_without_reset(): void
    {
        foreach (['unclaimed expiry', 'claimed expiry', 'exhaustion'] as $case) {
            $env = new DeliveryEnvironment();
            $now = $env->clock->now();
            $policy = new AgentDeliveryPolicy(retentionSeconds: 120, maximumAttempts: 1);
            $original = $env->operation();
            if ($case !== 'unclaimed expiry') {
                $claim = $original->claimDelivery(AgentDeliveryClaimId::generate(), $policy, $now);
                $this->persistDelivery($env, $original, $claim);
                $original = $claim;
            }

            $at = $now->modify('+120 seconds');
            $expectedDisposition = AgentDeliveryDisposition::EXPIRED;
            if ($case === 'exhaustion') {
                $at = $now->modify('+60 seconds');
                $expectedDisposition = AgentDeliveryDisposition::TERMINAL;
            }

            $terminal = $original->claimDelivery(AgentDeliveryClaimId::generate(), $policy, $at);
            $this->persistDelivery($env, $original, $terminal);
            self::assertSame($terminal, $env->operation());
            self::assertSame($expectedDisposition, $terminal->getDeliveryDisposition());
            self::assertSame($original->getAttempt(), $terminal->getAttempt());
            self::assertNull($terminal->getMaterial());
        }
    }

    public function test_valid_delivery_successor_rolls_back_when_the_transaction_fails_after_write(): void
    {
        $env = new DeliveryEnvironment();
        $original = $env->operation();
        $claim = $original->claimDelivery(
            AgentDeliveryClaimId::generate(),
            new AgentDeliveryPolicy(),
            $env->clock->now()
        );
        $failure = new RuntimeException('Controlled transaction failure.');
        $env->provisioning->operationRepository->afterDeliveryWrite = static function () use ($failure): void {
            throw $failure;
        };
        try {
            $this->persistDelivery($env, $original, $claim);
            self::fail('The controlled transaction failure was lost.');
        } catch (RuntimeException $runtimeException) {
            self::assertSame($failure, $runtimeException);
            self::assertSame($original, $env->operation());
            self::assertNull($env->operation()->getAttempt());
            self::assertNull($env->operation()->getDeliveryPolicy());
        }

        $env->provisioning->operationRepository->afterDeliveryWrite = null;
        $this->persistDelivery($env, $original, $claim);
        self::assertSame($claim, $env->operation());
        try {
            $this->persistDelivery($env, $original, $claim);
            self::fail('A stale expected snapshot was accepted.');
        } catch (AgentOperationRejectedException $agentOperationRejectedException) {
            self::assertSame(AgentOperationFailure::CONFLICT, $agentOperationRejectedException->getReason());
            self::assertSame($claim, $env->operation());
        }
    }

    #[DataProvider('inconsistentSuccessors')]
    public function test_exact_writers_reject_rewritten_binding_or_lost_history(string $case): void
    {
        $env = new DeliveryEnvironment();
        $ports = $env->provisioning;
        $original = $env->operation();
        if ($case === 'claim' || $case === 'delivery claim') {
            $original = $original->claimDelivery(
                AgentDeliveryClaimId::generate(),
                new AgentDeliveryPolicy(),
                $env->clock->now()
            );
            $ports->operationRepository->operations[$ports->key->toString()] = $original;
        }

        $material = $original->getMaterial();
        if ($case === 'material') {
            $material = new AgentDeliveryMaterial(EncryptedCredentialMaterial::fromString('other-copy'), 'test-key-v2');
        }

        $replacement = new AgentCredentialOperation(
            $case === 'marker' ? 99 : $original->getCanonicalVersion(),
            $case === 'request' ? 'different-request' : $original->getCanonicalRequest(),
            $original->getIssuance(),
            $material,
            stateRevision: $original->getStateRevision() + 1
        );
        try {
            $ports->transaction->commitTransactional(function () use ($ports, $original, $replacement, $case): void {
                $ports->operationAuthorization->authorize($ports->key->getScope(), $ports->request->getDestination());
                if ($case === 'claim') {
                    $ports->operationRepository->replaceMaintenance($original, $replacement);
                } else {
                    $ports->operationRepository->replaceDelivery(
                        $original,
                        $replacement,
                        $ports->agentRepository->all()[0]
                    );
                }
            });
            self::fail('An inconsistent successor was persisted.');
        } catch (AgentOperationRejectedException $agentOperationRejectedException) {
            self::assertSame(AgentOperationFailure::CONFLICT, $agentOperationRejectedException->getReason());
            self::assertSame($original, $env->operation());
            self::assertSame(0, $ports->operationRepository->deliveryWrites);
            self::assertSame(0, $ports->operationRepository->maintenanceWrites);
        }
    }

    private function persistDelivery(
        DeliveryEnvironment $env,
        AgentCredentialOperation $expected,
        AgentCredentialOperation $replacement
    ): void {
        $ports = $env->provisioning;
        $ports->transaction->commitTransactional(static function () use ($ports, $expected, $replacement): void {
            $ports->operationAuthorization->authorize($ports->key->getScope(), $ports->request->getDestination());
            $ports->operationRepository->replaceDelivery($expected, $replacement, $ports->agentRepository->all()[0]);
        });
    }
}
