<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Security;

use Fight\AccessControl\Application\AccessControl\Agent\QueryHandler\GetAgentOperationHandler;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentCredentialDeliveryService;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentCredentialInvocation;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentDeliveryResult;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentRepository;
use Fight\AccessControl\Domain\AccessControl\Agent\Delivery\AgentDeliveryAttempt;
use Fight\AccessControl\Domain\AccessControl\Agent\Delivery\AgentDeliveryAuthority;
use Fight\AccessControl\Domain\AccessControl\Agent\Delivery\AgentDeliveryClaimId;
use Fight\AccessControl\Domain\AccessControl\Agent\Delivery\AgentDeliveryFailure;
use Fight\AccessControl\Domain\AccessControl\Agent\Delivery\AgentDeliveryPolicy;
use Fight\AccessControl\Domain\AccessControl\Agent\Delivery\AgentDeliveryReceipt;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentDeliveryCommitUncertainException;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentDeliveryFailedException;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentOperationRejectedException;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentCredentialDisposition;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentCredentialOperation;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentDeliveryDisposition;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentDeliveryId;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentDeliveryMaterial;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationFailure;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationId;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationKey;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationLimits;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\GetAgentOperation;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\EncryptedCredentialMaterial;
use Fight\Common\Domain\Messaging\Query\QueryMessage;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\DeliveryEnvironment;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(AgentCredentialDeliveryService::class)]
#[CoversClass(AgentCredentialInvocation::class)]
#[CoversClass(AgentCredentialOperation::class)]
#[CoversClass(AgentDeliveryAttempt::class)]
#[CoversClass(AgentDeliveryAuthority::class)]
#[CoversClass(AgentDeliveryPolicy::class)]
#[CoversClass(AgentDeliveryReceipt::class)]
#[CoversClass(AgentDeliveryFailedException::class)]
#[CoversClass(AgentDeliveryCommitUncertainException::class)]
final class AgentCredentialDeliveryServiceTest extends TestCase
{
    public function test_real_provision_claim_admission_sink_and_acknowledgement_commit_separately(): void
    {
        $env = new DeliveryEnvironment();
        $envelope = $env->provisioning->agents->all()[0]->getEncryptedHmacSharedSecretEnvelope();
        $env->transaction->afterCommit = function (int $stage) use ($env): void {
            self::assertFalse($env->provisioning->transaction->transactionActive);
            self::assertSame($stage, $env->operation()->getStateRevision());
            if ($stage === 1) {
                self::assertNull($env->operation()->requireAttempt()->getAuthority());
                self::assertSame(0, $env->decipher->calls);
                self::assertSame(0, $env->sink->calls);
            } elseif ($stage === 2) {
                self::assertSame('delivery-worker:1', $env->operation()->requireAttempt()->getAuthority()?->getEpoch());
                self::assertSame(0, $env->decipher->calls);
            } else {
                self::assertNull($env->operation()->getMaterial());
                self::assertSame(
                    AgentDeliveryDisposition::DELIVERED,
                    $env->operation()->getStatus()->getDeliveryDisposition()
                );
            }
        };
        self::assertSame(AgentDeliveryResult::DELIVERED, $env->deliver());
        self::assertSame(3, $env->transaction->commits);
        self::assertSame(1, $env->decipher->calls);
        self::assertSame(1, $env->sink->calls);
        self::assertSame(1, $env->sink->verifications);
        self::assertSame($env->sink->receiptFor($env->issuance), $env->operation()->getReceipt());
        self::assertNull($env->operation()->getDeliveryFailure());
        self::assertSame('original-test-secret', $env->sink->stagedBytes($env->issuance));
        self::assertSame($envelope, $env->provisioning->agents->all()[0]->getEncryptedHmacSharedSecretEnvelope());
        self::assertCount(1, $env->provisioning->audit->all());
        self::assertSame(1, $env->provisioning->generations);
        $env->transaction->afterCommit = null;
        self::assertSame(AgentDeliveryResult::DELIVERED, $env->deliver());
        self::assertSame(1, $env->decipher->calls);
        self::assertSame(1, $env->sink->calls);
        self::assertSame($env->issuance, $env->provisioning->service()->provision(
            $env->provisioning->key,
            $env->provisioning->request
        )->getIssuance());
    }

    /** @return iterable<string, array{int, bool}> */
    public static function uncertainCommits(): iterable
    {
        foreach ([1 => 'claim', 2 => 'admission', 3 => 'outcome'] as $stage => $name) {
            yield $name.' committed' => [$stage, true];
            yield $name.' rolled back' => [$stage, false];
        }
    }

    #[DataProvider('uncertainCommits')]
    public function test_uncertain_commits_require_confirmed_admission(int $stage, bool $persist): void
    {
        $env = new DeliveryEnvironment();
        $env->transaction->uncertainAt = $stage;
        $env->transaction->persistUncertain = $persist;
        self::assertSame(AgentDeliveryResult::INDETERMINATE, $env->deliver());
        self::assertSame($persist ? $stage : $stage - 1, $env->operation()->getStateRevision());
        self::assertSame($stage === 3 ? 1 : 0, $env->decipher->calls);
        self::assertSame($stage === 3 ? 1 : 0, $env->sink->calls);
        $receipt = $env->sink->receiptFor($env->issuance);
        $env->clock->advance(61);
        $env->transaction->uncertainAt = null;
        self::assertSame(AgentDeliveryResult::DELIVERED, $env->deliver());
        self::assertSame(1, $env->provisioning->generations);
        self::assertCount(1, $env->provisioning->audit->all());
        if ($receipt !== null) {
            self::assertSame($receipt, $env->sink->receiptFor($env->issuance));
        }

        if ($stage === 3 && $persist) {
            self::assertSame(1, $env->sink->calls);
        }
    }

    /** @return iterable<string, array{int}> */
    public static function transactionStages(): iterable
    {
        yield 'claim' => [1];
        yield 'admission' => [2];
        yield 'completion' => [3];
    }

    #[DataProvider('transactionStages')]
    public function test_persistence_failures_roll_back_each_stage_and_hide_dependency_diagnostics(int $stage): void
    {
        $env = new DeliveryEnvironment();
        $env->provisioning->operations->afterDeliveryWrite = static function () use ($env, $stage): void {
            if ($env->provisioning->operations->deliveryWrites === $stage) {
                throw new RuntimeException('original-test-secret /unsafe/provider/key');
            }
        };
        self::assertSame(AgentDeliveryResult::UNAVAILABLE, $env->deliver());
        self::assertSame($stage - 1, $env->operation()->getStateRevision());
        self::assertNotNull($env->operation()->getMaterial());
        self::assertSame($stage === 3 ? 1 : 0, $env->sink->calls);
        self::assertFalse($env->provisioning->transaction->transactionActive);
    }

    /** @return iterable<string, array{string, int}> */
    public static function authorityRaces(): iterable
    {
        foreach (['caller', 'permission', 'delegation', 'destination', 'retirement', 'direct retirement'] as $change) {
            $stages = ['before claim', 'before admission', 'before materialization', 'in flight', 'before ack'];
            foreach ($stages as $stage => $label) {
                yield $change.' '.$label => [$change, $stage];
            }
        }
    }

    #[DataProvider('authorityRaces')]
    public function test_current_authority_rejects_early_and_late_unauthorized_work(string $change, int $stage): void
    {
        $env = new DeliveryEnvironment();
        $mutate = function () use ($env, $change): void {
            if ($change === 'retirement') {
                $env->revoke();

                return;
            }

            if ($change === 'direct retirement') {
                $env->provisioning->transaction->commitTransactional(static function () use ($env): void {
                    $agent = $env->provisioning->agents->all()[0];
                    self::assertTrue($env->provisioning->agents->replace($agent, $agent->revoke($env->clock->now())));
                });

                return;
            }

            $env->provisioning->authorization->changeAuthority(static function () use ($env, $change): void {
                match ($change) {
                    'caller' => $env->provisioning->authorization->scopes[
                        $env->issuance->getKey()->getScope()->toString()
                    ] = false,
                    'permission' => $env->authorization->permitted = false,
                    'delegation' => $env->authorization->expiresAt = $env->clock->now(),
                    'destination' => $env->provisioning->authorization->destinations[
                        $env->issuance->getDestination()->getId()->toString()
                    ] = 2,
                    default => throw new LogicException('Unknown authority race.')
                };
            });
        };
        if ($stage === 0) {
            $mutate();
        } elseif ($stage < 3) {
            $env->transaction->afterCommit = static function (int $completed) use ($mutate, $stage): void {
                if ($completed === $stage) {
                    $mutate();
                }
            };
        } elseif ($stage === 3) {
            $env->sink->beforeStage = $mutate;
        } else {
            $env->sink->beforeVerify = $mutate;
        }

        self::assertSame(AgentDeliveryResult::REJECTED, $env->deliver());
        self::assertSame($stage < 2 ? 0 : 1, $env->decipher->calls);
        self::assertSame($stage < 2 ? 0 : 1, $env->sink->calls);
        self::assertNotSame(
            AgentDeliveryDisposition::DELIVERED,
            $env->operation()->getStatus()->getDeliveryDisposition()
        );
        self::assertSame(1, $env->provisioning->generations);
        if (str_contains($change, 'retirement')) {
            self::assertNull($env->operation()->getMaterial());
            self::assertSame(
                AgentCredentialDisposition::REVOKED,
                $env->operation()->getStatus()->getCredentialDisposition()
            );
        }
    }

    public function test_revoke_regrant_aba_and_worker_identity_change_invalidate_prior_admission(): void
    {
        foreach (['aba', 'worker'] as $case) {
            $env = new DeliveryEnvironment();
            $env->sink->afterStage = static function () use ($env, $case): void {
                if ($case === 'worker') {
                    $env->authorization->worker = 'another-worker';
                } else {
                    $env->provisioning->authorization->changeAuthority(static function () use ($env): void {
                        $env->authorization->permitted = false;
                    });
                    $env->provisioning->authorization->changeAuthority(static function () use ($env): void {
                        $env->authorization->permitted = true;
                    });
                }
            };
            self::assertSame(AgentDeliveryResult::REJECTED, $env->deliver());
            self::assertNotNull($env->operation()->getMaterial());
            self::assertNotNull($env->sink->receiptFor($env->issuance));
            $env->sink->afterStage = null;
            $env->clock->advance(61);
            self::assertSame(AgentDeliveryResult::DELIVERED, $env->deliver());
            self::assertSame(2, $env->operation()->requireAttempt()->getFence());
        }
    }

    /** @return iterable<string, array{string, int}> */
    public static function deadlineStages(): iterable
    {
        yield 'late admission commit' => ['commit', 0];
        yield 'slow capability check' => ['support', 0];
        yield 'slow decryption' => ['decipher', 1];
        yield 'expiry in flight' => ['sink', 1];
    }

    #[DataProvider('deadlineStages')]
    public function test_deadline_guards_sensitive_effects_and_acknowledgement(string $stage, int $decryptions): void
    {
        $env = new DeliveryEnvironment();
        $expire = static function () use ($env): void {
            $env->clock->advance(15);
        };
        if ($stage === 'commit') {
            $env->transaction->afterCommit = static function (int $phase) use ($expire): void {
                if ($phase === 2) {
                    $expire();
                }
            };
        } elseif ($stage === 'support') {
            $env->sink->afterSupported = $expire;
        } elseif ($stage === 'decipher') {
            $env->decipher->afterMaterialize = $expire;
        } else {
            $env->sink->afterStage = $expire;
        }

        self::assertSame(AgentDeliveryResult::REJECTED, $env->deliver());
        self::assertSame($decryptions, $env->decipher->calls);
        self::assertSame($stage === 'sink' ? 1 : 0, $env->sink->calls);
        self::assertSame(2, $env->operation()->getStateRevision());
        self::assertNotNull($env->operation()->getMaterial());
    }

    public function test_temporary_key_failure_and_lost_sink_response_retry_original_material_and_receipt(): void
    {
        $env = new DeliveryEnvironment();
        $material = $env->operation()->getMaterial();
        $env->decipher->failure = AgentDeliveryFailure::TEMPORARY;
        self::assertSame(AgentDeliveryResult::RETRYABLE, $env->deliver());
        self::assertSame($material, $env->operation()->getMaterial());
        self::assertSame('test-key-v1', $env->operation()->getMaterial()?->getKeyVersion());
        self::assertSame(AgentDeliveryResult::DEFERRED, $env->deliver());
        $env->clock->advance(60);
        $env->decipher->failure = null;
        $env->sink->afterStage = static function (): void {
            throw new RuntimeException('Unsafe provider error and raw original-test-secret');
        };
        self::assertSame(AgentDeliveryResult::RETRYABLE, $env->deliver());
        $receipt = $env->sink->receiptFor($env->issuance);
        self::assertNotNull($receipt);
        self::assertSame($material, $env->operation()->getMaterial());
        $env->sink->afterStage = null;
        $env->clock->advance(60);
        self::assertSame(AgentDeliveryResult::DELIVERED, $env->deliver());
        self::assertSame($receipt, $env->sink->receiptFor($env->issuance));
        self::assertSame('original-test-secret', $env->sink->stagedBytes($env->issuance));
        self::assertSame(1, $env->provisioning->generations);
    }

    public function test_permanent_key_loss_corruption_and_unqualified_sinks_retire_only_delivery_copy(): void
    {
        foreach (['key', 'corrupt', 'swap', 'wrong invocation', 'receipt', 'unsupported'] as $case) {
            $env = new DeliveryEnvironment();
            $original = $env->operation();
            if ($case === 'key') {
                $env->decipher->failure = AgentDeliveryFailure::KEY_RETIRED;
            } elseif ($case === 'corrupt' || $case === 'swap') {
                $material = new AgentDeliveryMaterial(
                    EncryptedCredentialMaterial::fromString('corrupt-copy'),
                    'test-key-v1'
                );
                if ($case === 'swap') {
                    $material = new DeliveryEnvironment()->operation()->getMaterial();
                }

                $key = $env->provisioning->key->toString();
                $env->provisioning->operations->operations[$key] = new AgentCredentialOperation(
                    1,
                    $original->getCanonicalRequest(),
                    $original->getIssuance(),
                    $material
                );
            } elseif ($case === 'wrong invocation') {
                $env->decipher->wrongBinding = new DeliveryEnvironment()->issuance;
            } elseif ($case === 'receipt') {
                $env->sink->validReceipt = false;
            } else {
                $env->sink->supported = false;
            }

            self::assertSame(AgentDeliveryResult::TERMINAL, $env->deliver(), $case);
            self::assertNull($env->operation()->getMaterial());
            self::assertSame($original->getIssuance(), $env->operation()->getIssuance());
            self::assertSame(
                'auth-envelope:original-test-secret',
                $env->provisioning->agents->all()[0]->getEncryptedHmacSharedSecretEnvelope()
            );
            self::assertSame($case === 'unsupported' ? 0 : 1, $env->decipher->calls);
            self::assertSame($case === 'receipt' ? 1 : 0, $env->sink->calls);
            self::assertSame(AgentDeliveryResult::TERMINAL, $env->deliver());
        }
    }

    public function test_expiry_and_attempt_limits_retire_material_without_new_work_capacity_or_reissuance(): void
    {
        $expired = new DeliveryEnvironment();
        $expired->clock->advance(86400);
        $expired->authorization->expiresAt = $expired->clock->now()->modify('+1 hour');
        self::assertSame(AgentDeliveryResult::EXPIRED, $expired->deliver());
        self::assertNull($expired->operation()->getMaterial());
        self::assertSame(0, $expired->decipher->calls);
        $limited = new DeliveryEnvironment();
        $limited->decipher->failure = AgentDeliveryFailure::TEMPORARY;
        self::assertSame(
            AgentDeliveryResult::RETRYABLE,
            $limited->deliver(new AgentDeliveryPolicy(maximumAttempts: 1))
        );
        $limited->clock->advance(60);
        self::assertSame(AgentDeliveryResult::TERMINAL, $limited->deliver());
        self::assertSame(1, $limited->decipher->calls);
        self::assertNull($limited->operation()->getMaterial());
        $env = new DeliveryEnvironment();
        $key = new AgentOperationKey($env->provisioning->key->getScope(), AgentOperationId::generate());
        try {
            $env->provisioning->service(limits: new AgentOperationLimits(512, 1, 1))->provision(
                $key,
                $env->provisioning->request
            );
            self::fail('New issuance must reject at capacity.');
        } catch (AgentOperationRejectedException $agentOperationRejectedException) {
            self::assertSame(AgentOperationFailure::CAPACITY, $agentOperationRejectedException->getReason());
        }

        self::assertSame(AgentDeliveryResult::DELIVERED, $env->deliver());
        self::assertCount(1, $env->provisioning->operations->operations);
    }

    public function test_competing_claim_and_takeover_cannot_acknowledge_an_old_admission(): void
    {
        $env = new DeliveryEnvironment();
        $env->sink->beforeStage = function () use ($env): void {
            $env->sink->beforeStage = null;
            self::assertSame(AgentDeliveryResult::DEFERRED, $env->deliver());
            $env->clock->advance(60);
            self::assertSame(AgentDeliveryResult::DELIVERED, $env->deliver());
        };
        self::assertSame(AgentDeliveryResult::REJECTED, $env->deliver());
        self::assertSame(2, $env->operation()->requireAttempt()->getFence());
        self::assertSame(AgentDeliveryDisposition::DELIVERED, $env->operation()->getStatus()->getDeliveryDisposition());
        self::assertSame(2, $env->sink->calls);
        self::assertSame(1, $env->provisioning->generations);
    }

    /** @return iterable<string, array{string}> */
    public static function unavailableInputs(): iterable
    {
        foreach (['delivery id', 'absent', 'version', 'agent', 'closed', 'storage'] as $case) {
            yield $case => [$case];
        }
    }

    #[DataProvider('unavailableInputs')]
    public function test_unavailable_or_invalid_correlation_fails_closed_without_sink_access(string $case): void
    {
        $env = new DeliveryEnvironment();
        $key = $env->provisioning->key->toString();
        $original = $env->operation();
        $service = $env->service();
        $deliveryId = $env->issuance->getDeliveryId();
        if ($case === 'delivery id') {
            $deliveryId = AgentDeliveryId::generate();
        } elseif ($case === 'absent') {
            unset($env->provisioning->operations->operations[$key]);
        } elseif ($case === 'version') {
            $env->provisioning->operations->operations[$key] = new AgentCredentialOperation(
                2,
                $original->getCanonicalRequest(),
                $env->issuance,
                $original->getMaterial()
            );
        } elseif ($case === 'agent') {
            $agents = $this->createStub(AgentRepository::class);
            $agents->method('getById')->willReturn(null);
            $service = new AgentCredentialDeliveryService(
                $agents,
                $env->provisioning->operations,
                $env->provisioning->authorization,
                $env->authorization,
                $env->decipher,
                $env->sink,
                $env->clock,
                $env->transaction
            );
        } elseif ($case === 'closed') {
            $env->transaction->closed = true;
        } else {
            $env->provisioning->authorization->afterAuthorization = static function (): void {
                throw new AgentOperationRejectedException(AgentOperationFailure::UNAVAILABLE);
            };
        }

        $expected = AgentDeliveryResult::REJECTED;
        if ($case === 'closed' || $case === 'storage') {
            $expected = AgentDeliveryResult::UNAVAILABLE;
        }

        self::assertSame($expected, $service->deliver(
            $env->provisioning->key,
            $env->issuance->getDestination(),
            $deliveryId
        ));
        self::assertSame(0, $env->sink->calls);
        self::assertSame(0, $env->decipher->calls);
        self::assertSame(0, $original->getStateRevision());
    }

    public function test_material_absence_is_not_delivery_and_retired_original_cannot_reenter_the_sink(): void
    {
        foreach ([AgentDeliveryDisposition::PENDING, AgentDeliveryDisposition::RETIRED] as $disposition) {
            $env = new DeliveryEnvironment();
            $original = $env->operation();
            $key = $env->provisioning->key->toString();
            $env->provisioning->operations->operations[$key] = new AgentCredentialOperation(
                1,
                $original->getCanonicalRequest(),
                $env->issuance,
                null,
                $disposition
            );
            $expected = AgentDeliveryResult::REJECTED;
            if ($disposition === AgentDeliveryDisposition::RETIRED) {
                $expected = AgentDeliveryResult::RETIRED;
            }

            self::assertSame($expected, $env->deliver());
            self::assertSame(0, $env->decipher->calls);
        }
    }

    public function test_authorized_status_and_receipt_history_remain_safe_after_completed_delivery_is_revoked(): void
    {
        $env = new DeliveryEnvironment();
        self::assertSame(AgentDeliveryResult::DELIVERED, $env->deliver());
        $receipt = $env->operation()->getReceipt();
        self::assertNotNull($receipt);
        $env->revoke();
        self::assertSame($receipt, $env->operation()->getReceipt());
        $transactions = $env->provisioning->transaction->transactions;
        $view = new GetAgentOperationHandler(
            $env->provisioning->operations,
            $env->provisioning->authorization
        )->handle(QueryMessage::create(new GetAgentOperation(
            $env->provisioning->key,
            $env->issuance->getDestination()
        )));
        self::assertSame(AgentDeliveryDisposition::DELIVERED, $view->getDeliveryDisposition());
        self::assertSame(AgentCredentialDisposition::REVOKED, $view->getCredentialDisposition());
        self::assertSame($transactions, $env->provisioning->transaction->transactions);
        self::assertStringNotContainsString($receipt->toString(), serialize($view));
        self::assertStringNotContainsString('original-test-secret', serialize($view));
        self::assertSame(AgentDeliveryResult::REJECTED, $env->deliver());
        self::assertSame(1, $env->decipher->calls);
        self::assertSame(1, $env->sink->calls);
        self::assertNull($env->operation()->getMaterial());
    }

    public function test_outer_transactions_and_missing_worker_delegation_or_target_authority_fail_closed(): void
    {
        $nested = new DeliveryEnvironment();
        $result = $nested->provisioning->transaction->commitTransactional($nested->deliver(...));
        self::assertSame(AgentDeliveryResult::UNAVAILABLE, $result);
        self::assertSame(0, $nested->decipher->calls);
        self::assertSame(0, $nested->operation()->getStateRevision());
        foreach (['delegated', 'targetAllowed'] as $property) {
            $env = new DeliveryEnvironment();
            $env->authorization->{$property} = false;
            self::assertSame(AgentDeliveryResult::REJECTED, $env->deliver());
            self::assertSame(0, $env->operation()->getStateRevision());
            self::assertSame(0, $env->decipher->calls);
        }
    }

    public function test_direct_delivery_write_cannot_restore_a_retired_operation_or_its_material(): void
    {
        $env = new DeliveryEnvironment();
        $original = $env->operation();
        $agent = $env->provisioning->agents->all()[0];
        $replacement = $original->claimDelivery(
            AgentDeliveryClaimId::generate(),
            new AgentDeliveryPolicy(),
            $env->clock->now()
        );
        $env->revoke();
        try {
            $env->provisioning->transaction->commitTransactional(static function () use (
                $env,
                $original,
                $replacement,
                $agent
            ): void {
                $env->provisioning->authorization->authorize(
                    $env->issuance->getKey()->getScope(),
                    $env->issuance->getDestination()
                );
                $env->provisioning->operations->replaceDelivery($original, $replacement, $agent);
            });
            self::fail('A direct stale delivery writer must lose to retirement.');
        } catch (AgentOperationRejectedException) {
            self::assertNull($env->operation()->getMaterial());
            self::assertSame(
                AgentCredentialDisposition::REVOKED,
                $env->operation()->getStatus()->getCredentialDisposition()
            );
            self::assertSame(0, $env->decipher->calls);
        }
    }

    public function test_new_destination_order_during_an_admitted_call_leaves_old_bytes_inert(): void
    {
        $env = new DeliveryEnvironment();
        $env->sink->beforeStage = static function () use ($env): void {
            $key = new AgentOperationKey($env->issuance->getKey()->getScope(), AgentOperationId::generate());
            $successor = $env->provisioning->service()->provision($key, $env->provisioning->request)->getIssuance();
            self::assertNotNull($successor);
            self::assertSame(2, $successor->getDestinationWriteVersion());
        };
        self::assertSame(AgentDeliveryResult::REJECTED, $env->deliver());
        self::assertSame('original-test-secret', $env->sink->stagedBytes($env->issuance));
        self::assertNotSame(
            AgentDeliveryDisposition::DELIVERED,
            $env->operation()->getStatus()->getDeliveryDisposition()
        );
        self::assertNull($env->operation()->getReceipt());
    }

    public function test_invocation_and_results_do_not_serialize_or_debug_secret_material(): void
    {
        $env = new DeliveryEnvironment();
        $invocation = new AgentCredentialInvocation($env->issuance, 'sensitive-original-bytes');
        self::assertSame($env->issuance, $invocation->getIssuance());
        ob_start();
        var_dump($invocation);
        $debug = ob_get_clean();
        self::assertIsString($debug);
        self::assertStringNotContainsString('sensitive-original-bytes', $debug);
        self::assertSame('{}', json_encode($invocation));
        try {
            serialize($invocation);
            self::fail('Sensitive invocation must not serialize.');
        } catch (LogicException $logicException) {
            self::assertSame('Agent credential invocation cannot be serialized.', $logicException->getMessage());
        }

        $result = $env->deliver();
        self::assertStringNotContainsString(
            'original-test-secret',
            serialize([$result, $env->operation()->getStatus()])
        );
        self::assertSame('"delivered"', json_encode($result));
    }
}
