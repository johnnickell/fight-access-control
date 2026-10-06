<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Security;

use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentCredentialInvocation;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentDeliveryResult;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentMaintenanceResult;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentState;
use Fight\AccessControl\Domain\AccessControl\Agent\Delivery\AgentDeliveryFailure;
use Fight\AccessControl\Domain\AccessControl\Agent\Delivery\AgentDeliveryPolicy;
use Fight\AccessControl\Domain\AccessControl\Agent\Delivery\AgentDeliverySchedule;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentDeliveryFailedException;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentOperationRejectedException;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\CurrentAgentPrincipalResolutionRejectedException;
use Fight\AccessControl\Domain\AccessControl\Agent\Maintenance\AgentDeliveryKeyVersion;
use Fight\AccessControl\Domain\AccessControl\Agent\Maintenance\AgentMaintenancePolicy;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentCredentialDestination;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentCredentialDisposition;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentDeliveryDisposition;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentDestinationId;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationFailure;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationId;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationKey;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationLimits;
use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;

/** Consumer-bindable delivery, authorization, transaction, lifecycle and maintenance outcomes */
abstract class DeliveryLifecycleConformance extends DeliveryConformance
{
    /** @return iterable<string, array{string, string}> */
    public static function authorityRaces(): iterable
    {
        foreach (['caller', 'permission', 'delegation', 'destination'] as $change) {
            foreach (['before', 'claim', 'admission', 'invoking', 'verifying'] as $boundary) {
                yield $change.' '.$boundary => [$change, $boundary];
            }
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function retirementPaths(): iterable
    {
        foreach (['revoke', 'direct revoke', 'rotate', 'direct rotate'] as $path) {
            foreach (['before', 'claim', 'admission', 'invoking', 'verifying'] as $boundary) {
                yield $path.' '.$boundary => [$path, $boundary];
            }
        }
    }

    /** @return iterable<string, array{int, bool, bool}> */
    public static function uncertainCommits(): iterable
    {
        foreach ([false, true] as $rotation) {
            foreach ([1 => 'claim', 2 => 'admission', 3 => 'outcome'] as $stage => $name) {
                foreach ([false, true] as $persist) {
                    $label = ($rotation ? 'rotation ' : 'provision ').$name;
                    yield $label.($persist ? ' committed' : ' rolled back') => [$stage, $persist, $rotation];
                }
            }
        }
    }

    /** @return iterable<string, array{string, int, int}> */
    public static function deadlines(): iterable
    {
        yield 'late admission return' => ['admission', 0, 0];
        yield 'slow decipher' => ['materialized', 1, 0];
        yield 'expiry during invocation' => ['accepted', 1, 1];
    }

    /** @return iterable<string, array{string}> */
    public static function keyFailures(): iterable
    {
        yield 'temporary' => ['temporary'];
        yield 'permanently missing' => ['missing'];
        yield 'corrupt persisted material' => ['corrupt'];
        yield 'swapped persisted material' => ['swapped'];
    }

    /** @return iterable<string, array{string, list<int>}> */
    public static function invalidPolicies(): iterable
    {
        yield 'zero lease' => ['delivery', [0, 1, 1, 60, 1]];
        yield 'excess lease' => ['delivery', [3601, 1, 1, 7200, 1]];
        yield 'admission beyond lease' => ['delivery', [10, 11, 1, 60, 1]];
        yield 'retry beyond retention' => ['delivery', [10, 1, 61, 60, 1]];
        yield 'retention below lease' => ['delivery', [60, 1, 1, 59, 1]];
        yield 'excess retention' => ['delivery', [60, 15, 30, 604801, 1]];
        yield 'zero attempts' => ['delivery', [60, 15, 30, 86400, 0]];
        yield 'excess attempts' => ['delivery', [60, 15, 30, 86400, 1001]];
        yield 'zero batch' => ['schedule', [0, 30]];
        yield 'excess batch' => ['schedule', [101, 30]];
        yield 'zero polling' => ['schedule', [50, 0]];
        yield 'excess polling' => ['schedule', [50, 3601]];
        yield 'zero maintenance batch' => ['maintenance', [0, 1]];
        yield 'excess maintenance batch' => ['maintenance', [101, 1]];
        yield 'zero cleanup grace' => ['maintenance', [1, 0]];
        yield 'excess cleanup grace' => ['maintenance', [1, 604801]];
    }

    /** @param list<int> $arguments */
    #[DataProvider('invalidPolicies')]
    public function test_invalid_overrides_reject_without_work(string $kind, array $arguments): void
    {
        $fixture = $this->newFixture();
        $before = $fixture->counts();
        try {
            match ($kind) {
                'delivery' => new AgentDeliveryPolicy(...$arguments),
                'schedule' => new AgentDeliverySchedule(...$arguments),
                default => new AgentMaintenancePolicy(...$arguments)
            };
            self::fail('Invalid policy must reject before execution.');
        } catch (AgentOperationRejectedException $agentOperationRejectedException) {
            self::assertSame(AgentOperationFailure::INVALID_REQUEST, $agentOperationRejectedException->getReason());
        } finally {
            self::assertSame($before, $fixture->counts());
            self::assertSame(0, $fixture->stored($fixture->original())->getStateRevision());
        }
    }

    #[DataProvider('authorityRaces')]
    public function test_authority_changes_deny_early_or_leave_only_inert_late_bytes(
        string $change,
        string $boundary
    ): void {
        $fixture = $this->newFixture();
        $issuance = $fixture->original();
        $bytes = $fixture->preparedBytes($issuance);
        $mutate = static fn () => $fixture->changeAuthority($change, $issuance);
        if ($boundary === 'before') {
            $mutate();
        } else {
            $fixture->pause($boundary, $mutate);
        }

        self::assertSame(AgentDeliveryResult::REJECTED, $this->deliver($fixture, $issuance));
        $late = !in_array($boundary, ['before', 'claim'], true);
        self::assertSame((int) $late, $fixture->counts()['decryptions']);
        self::assertSame((int) $late, $fixture->counts()['sink']);
        self::assertSame($late ? $bytes : null, $fixture->stagedBytes($issuance));
        self::assertNotSame(
            AgentDeliveryDisposition::DELIVERED,
            $fixture->stored($issuance)->getStatus()->getDeliveryDisposition()
        );
        self::assertNull($fixture->stored($issuance)->getReceipt(), 'Sink receipt alone is not package confirmation.');
        self::assertSame(1, $fixture->counts()['generations']);
        self::assertSame(1, $fixture->counts()['audit']);
        $this->assertSafe($fixture, [$fixture->stored($issuance)->getStatus()->toArray()]);
    }

    #[DataProvider('retirementPaths')]
    public function test_every_retained_lifecycle_writer_fences_real_delivery_and_original_status(
        string $path,
        string $boundary
    ): void {
        $fixture = $this->newFixture();
        $issuance = $fixture->original();
        $bytes = $fixture->preparedBytes($issuance);
        self::assertEquals($issuance->getAgentId(), $fixture->authenticate($issuance, $bytes)->getAgentId());
        $retire = function () use ($fixture, $issuance, $path): void {
            $before = $fixture->counts();
            if ($path === 'revoke') {
                $this->revoke($fixture, $issuance);
            } elseif ($path === 'rotate') {
                $this->rotate($fixture, $issuance);
            } else {
                $ports = $fixture->ports();
                $ports->transaction->commitTransactional(static function () use ($ports, $issuance, $path): void {
                    $agent = $ports->agents->getById($issuance->getAgentId());
                    self::assertNotNull($agent);
                    $successor = $agent->revoke($ports->clock->now());
                    if ($path === 'direct rotate') {
                        $successor = $agent->rotateRecoverableCredential(
                            $issuance->getCredentialId(),
                            $issuance->getCredentialRevision(),
                            AgentCredentialId::generate(),
                            'direct-successor-envelope',
                            $ports->clock->now()
                        );
                    }

                    self::assertTrue($ports->agents->replace($agent, $successor));
                });
            }

            self::assertSame($before['decryptions'], $fixture->counts()['decryptions']);
            self::assertSame($before['sink'], $fixture->counts()['sink']);
            self::assertNull($fixture->stored($issuance)->getMaterial());
        };
        if ($boundary === 'before') {
            $retire();
        } else {
            $fixture->pause($boundary, $retire);
        }

        self::assertSame(AgentDeliveryResult::REJECTED, $this->deliver($fixture, $issuance));
        $view = $this->readOperation($fixture, $issuance);
        $disposition = AgentCredentialDisposition::SUPERSEDED;
        if (str_contains($path, 'revoke')) {
            $disposition = AgentCredentialDisposition::REVOKED;
        }

        self::assertSame($disposition, $view->getCredentialDisposition());
        self::assertSame(AgentDeliveryDisposition::RETIRED, $view->getDeliveryDisposition());
        self::assertSame(
            in_array($boundary, ['before', 'claim'], true) ? null : $bytes,
            $fixture->stagedBytes($issuance)
        );
        try {
            $fixture->authenticate($issuance, $bytes);
            self::fail('Retired credentials must not authenticate a fresh request.');
        } catch (CurrentAgentPrincipalResolutionRejectedException $currentAgentPrincipalResolutionRejectedException) {
            $this->assertSafe($fixture, [
                $currentAgentPrincipalResolutionRejectedException->getDiagnostic()->toArray()
            ]);
        }

        self::assertSame(AgentDeliveryResult::REJECTED, $this->deliver($fixture, $issuance));
    }

    public function test_fenced_authority_writer_and_aba_require_a_new_admission(): void
    {
        $fixture = $this->newFixture();
        $issuance = $fixture->original();
        $fixture->pause('fenced', static fn () => $fixture->changeAuthority('caller', $issuance));
        self::assertSame(AgentDeliveryResult::REJECTED, $this->deliver($fixture, $issuance));
        self::assertSame(1, $fixture->stored($issuance)->getStateRevision(), 'Claim commits before contending writer.');
        self::assertSame(0, $fixture->counts()['decryptions']);

        $fixture = $this->newFixture();
        $issuance = $fixture->original();
        $fixture->pause('accepted', static fn () => $fixture->changeAuthority('aba', $issuance));
        self::assertSame(AgentDeliveryResult::REJECTED, $this->deliver($fixture, $issuance));
        $oldAttempt = $fixture->stored($issuance)->requireAttempt();
        self::assertNotNull($fixture->receipt($issuance));
        self::assertNull($fixture->stored($issuance)->getReceipt());
        $fixture->restart();
        $fixture->advance(61);
        self::assertSame(
            [$issuance->getDeliveryId()->toString() => AgentDeliveryResult::DELIVERED],
            $this->recover($fixture, $issuance)
        );
        $newAttempt = $fixture->stored($issuance)->requireAttempt();
        self::assertGreaterThan($oldAttempt->getFence(), $newAttempt->getFence());
        self::assertNotEquals($oldAttempt->getAuthority(), $newAttempt->getAuthority());
        $invocations = $this->hasReceiptLookup($fixture) ? 1 : 2;
        self::assertSame($invocations, $fixture->counts()['decryptions']);
        self::assertSame($invocations, $fixture->counts()['sink']);
    }

    #[DataProvider('deadlines')]
    public function test_earliest_authority_deadline_guards_materialization_invocation_and_completion(
        string $boundary,
        int $decryptions,
        int $calls
    ): void {
        $fixture = $this->newFixture();
        $issuance = $fixture->original();
        $fixture->expireAuthorityAfter(5);
        $deadline = $fixture->ports()->clock->now()->modify('+5 seconds');
        $fixture->pause($boundary, static fn () => $fixture->advance(5));
        self::assertSame(AgentDeliveryResult::REJECTED, $this->deliver($fixture, $issuance));
        self::assertEquals($deadline, $fixture->stored($issuance)->requireAttempt()->getDeadline());
        self::assertSame($decryptions, $fixture->counts()['decryptions']);
        self::assertSame($calls, $fixture->counts()['sink']);
        self::assertNull($fixture->stored($issuance)->getReceipt());
        self::assertNotNull($fixture->stored($issuance)->getMaterial());
    }

    #[DataProvider('uncertainCommits')]
    public function test_restart_resolves_each_uncertain_delivery_commit_without_reissuing(
        int $stage,
        bool $persist,
        bool $rotation
    ): void {
        $fixture = $this->newFixture();
        $issuance = $rotation ? $this->rotate($fixture, $fixture->original()) : $fixture->original();
        $bytes = $fixture->preparedBytes($issuance);
        $before = $fixture->counts();
        $fixture->loseCommit($stage, $persist);
        self::assertSame(AgentDeliveryResult::INDETERMINATE, $this->deliver($fixture, $issuance));
        self::assertSame($persist ? $stage : $stage - 1, $fixture->stored($issuance)->getStateRevision());
        self::assertSame($stage === 3 ? 1 : 0, $fixture->counts()['decryptions']);
        self::assertSame($stage === 3 ? 1 : 0, $fixture->counts()['sink']);
        $fixture->restart();
        if ($stage === 2 && $persist) {
            self::assertSame(AgentDeliveryResult::DEFERRED, $this->deliver($fixture, $issuance));
            self::assertSame(0, $fixture->counts()['decryptions'], 'Recovered admission is receipt-only.');
        }

        $fixture->advance(61);
        self::assertSame(
            $stage === 3 && $persist ? [] : [$issuance->getDeliveryId()->toString() => AgentDeliveryResult::DELIVERED],
            $this->recover($fixture, $issuance)
        );
        self::assertSame(AgentDeliveryResult::DELIVERED, $this->deliver($fixture, $issuance));
        self::assertSame(
            AgentDeliveryDisposition::DELIVERED,
            $this->readOperation($fixture, $issuance)->getDeliveryDisposition()
        );
        self::assertSame($bytes, $fixture->stagedBytes($issuance));
        self::assertEquals($fixture->receipt($issuance), $fixture->stored($issuance)->getReceipt());
        self::assertNull($fixture->stored($issuance)->getMaterial());
        self::assertSame($before['generations'], $fixture->counts()['generations']);
        self::assertSame($before['audit'], $fixture->counts()['audit']);
        $invocations = $stage === 3 && !$persist && !$this->hasReceiptLookup($fixture) ? 2 : 1;
        self::assertSame($invocations, $fixture->counts()['decryptions']);
        self::assertSame($invocations, $fixture->counts()['sink']);
    }

    public function test_each_failed_persistence_stage_rolls_back_and_scheduler_recovers(): void
    {
        foreach ([1, 2, 3] as $stage) {
            $fixture = $this->newFixture();
            $issuance = $fixture->original();
            $fixture->failWrite($stage);
            self::assertSame(AgentDeliveryResult::UNAVAILABLE, $this->deliver($fixture, $issuance));
            self::assertSame($stage - 1, $fixture->stored($issuance)->getStateRevision());
            self::assertNotNull($fixture->stored($issuance)->getMaterial());
            $fixture->restart();
            $fixture->advance(61);
            self::assertSame(
                [$issuance->getDeliveryId()->toString() => AgentDeliveryResult::DELIVERED],
                $this->recover($fixture, $issuance)
            );
            $invocations = $stage === 3 && !$this->hasReceiptLookup($fixture) ? 2 : 1;
            self::assertSame($invocations, $fixture->counts()['sink']);
            self::assertSame(1, $fixture->counts()['generations']);
            $this->readOperation($fixture, $issuance);
        }
    }

    public function test_lost_response_takeover_and_delivered_secret_loss_never_trigger_secret_reread(): void
    {
        $fixture = $this->newFixture();
        $issuance = $fixture->original();
        $bytes = $fixture->preparedBytes($issuance);
        $fixture->loseSinkResponse();
        self::assertSame(AgentDeliveryResult::RETRYABLE, $this->deliver($fixture, $issuance));
        self::assertSame($bytes, $fixture->stagedBytes($issuance));
        $fixture->restart();
        if ($this->hasReceiptLookup($fixture)) {
            $fixture->keyFailure(AgentDeliveryFailure::KEY_RETIRED);
        }

        $fixture->advance(61);
        self::assertSame(
            [$issuance->getDeliveryId()->toString() => AgentDeliveryResult::DELIVERED],
            $this->recover($fixture, $issuance)
        );
        $fixture->forgetSinkMaterial($issuance);
        self::assertSame(AgentDeliveryResult::RECONCILIATION_REQUIRED, $this->deliver($fixture, $issuance));
        self::assertSame(
            AgentDeliveryDisposition::DELIVERED,
            $this->readOperation($fixture, $issuance)->getDeliveryDisposition()
        );
        $invocations = $this->hasReceiptLookup($fixture) ? 1 : 2;
        self::assertSame($invocations, $fixture->counts()['sink']);
        self::assertSame($invocations, $fixture->counts()['decryptions']);
        self::assertSame(1, $fixture->counts()['generations']);

        $fixture = $this->newFixture();
        $issuance = $fixture->original();
        $fixture->pause('accepted', function () use ($fixture, $issuance): void {
            $fixture->advance(60);
            self::assertSame(
                [$issuance->getDeliveryId()->toString() => AgentDeliveryResult::DELIVERED],
                $this->recover($fixture, $issuance)
            );
        });
        self::assertSame(AgentDeliveryResult::REJECTED, $this->deliver($fixture, $issuance));
        self::assertSame(2, $fixture->stored($issuance)->requireAttempt()->getFence());
        self::assertSame(
            AgentDeliveryDisposition::DELIVERED,
            $this->readOperation($fixture, $issuance)->getDeliveryDisposition()
        );
        $invocations = $this->hasReceiptLookup($fixture) ? 1 : 2;
        self::assertSame($invocations, $fixture->counts()['sink']);
        self::assertSame($invocations, $fixture->counts()['decryptions']);
    }

    #[DataProvider('keyFailures')]
    public function test_rewrap_key_failure_and_restart_preserve_original_binding_without_envelope_fallback(
        string $failure
    ): void {
        $fixture = $this->newFixture();
        $issuance = $fixture->original();
        $bytes = $fixture->preparedBytes($issuance);
        $agent = $fixture->ports()->agents->getById($issuance->getAgentId());
        self::assertNotNull($agent);
        $envelope = $agent->getEncryptedHmacSharedSecretEnvelope();
        if ($failure === 'corrupt') {
            $fixture->corruptMaterial($issuance);
        } elseif ($failure === 'swapped') {
            $destination = new AgentCredentialDestination(AgentDestinationId::generate(), 1);
            $fixture->allow($issuance->getKey()->getScope(), $destination);
            $other = $this->provision(
                $fixture,
                new AgentOperationKey($issuance->getKey()->getScope(), AgentOperationId::generate()),
                $destination
            );
            $fixture->corruptMaterial($issuance, $other);
        } else {
            $fixture->keyFailure(
                $failure === 'temporary' ? AgentDeliveryFailure::TEMPORARY : AgentDeliveryFailure::KEY_RETIRED
            );
        }

        $before = $fixture->counts();
        $result = $this->maintenance($fixture)->rewrap(
            $issuance->getKey(),
            $issuance->getDestination(),
            $issuance->getDeliveryId(),
            new AgentDeliveryKeyVersion('test-key-v2')
        );
        self::assertSame(
            $failure === 'temporary' ? AgentMaintenanceResult::RETRYABLE : AgentMaintenanceResult::TERMINAL,
            $result
        );
        $fixture->restart();
        if ($failure === 'temporary') {
            self::assertSame('test-key-v1', $fixture->stored($issuance)->getMaterial()?->getKeyVersion());
            self::assertSame(AgentMaintenanceResult::REWRAPPED, $this->maintenance($fixture)->rewrap(
                $issuance->getKey(),
                $issuance->getDestination(),
                $issuance->getDeliveryId(),
                new AgentDeliveryKeyVersion('test-key-v2')
            ));
            self::assertSame('test-key-v2', $fixture->stored($issuance)->getMaterial()?->getKeyVersion());
            self::assertSame($bytes, $fixture->preparedBytes($issuance));
            self::assertSame(
                [$issuance->getDeliveryId()->toString() => AgentDeliveryResult::DELIVERED],
                $this->recover($fixture, $issuance)
            );
            self::assertSame($bytes, $fixture->stagedBytes($issuance));
        } else {
            self::assertNull($fixture->stored($issuance)->getMaterial());
            self::assertSame([], $this->recover($fixture, $issuance));
            self::assertSame(AgentDeliveryResult::TERMINAL, $this->deliver($fixture, $issuance));
            self::assertSame(0, $fixture->counts()['decryptions']);
            self::assertSame(0, $fixture->counts()['sink']);
        }

        self::assertSame(
            $envelope,
            $fixture->ports()->agents->getById($issuance->getAgentId())?->getEncryptedHmacSharedSecretEnvelope()
        );
        self::assertSame($before['generations'], $fixture->counts()['generations']);
        $this->readOperation($fixture, $issuance);
    }

    public function test_rewrap_during_delivery_fences_stale_completion_and_retains_retry_history(): void
    {
        $fixture = $this->newFixture();
        $issuance = $fixture->original();
        $bytes = $fixture->preparedBytes($issuance);
        $fixture->pause('accepted', function () use ($fixture, $issuance): void {
            $before = $fixture->stored($issuance);
            self::assertSame(AgentMaintenanceResult::REWRAPPED, $this->maintenance($fixture)->rewrap(
                $issuance->getKey(),
                $issuance->getDestination(),
                $issuance->getDeliveryId(),
                new AgentDeliveryKeyVersion('test-key-v2')
            ));
            $after = $fixture->stored($issuance);
            self::assertEquals($before->getAttempt(), $after->getAttempt());
            self::assertEquals($before->getDeliveryPolicy(), $after->getDeliveryPolicy());
            self::assertEquals($before->getRetryAt(), $after->getRetryAt());
            self::assertSame($before->getStateRevision() + 1, $after->getStateRevision());
        });
        self::assertSame(AgentDeliveryResult::REJECTED, $this->deliver($fixture, $issuance));
        self::assertSame('test-key-v2', $fixture->stored($issuance)->getMaterial()?->getKeyVersion());
        self::assertNull($fixture->stored($issuance)->getReceipt());
        $fixture->restart();
        $fixture->advance(61);
        self::assertSame(
            [$issuance->getDeliveryId()->toString() => AgentDeliveryResult::DELIVERED],
            $this->recover($fixture, $issuance)
        );
        self::assertSame($bytes, $fixture->stagedBytes($issuance));
        self::assertSame($this->hasReceiptLookup($fixture) ? 1 : 2, $fixture->counts()['decryptions']);
        self::assertNull($fixture->stored($issuance)->getMaterial());
        self::assertSame(1, $fixture->counts()['generations']);
    }

    public function test_retention_cleanup_and_replay_preserve_correlation_and_slot_order(): void
    {
        foreach ([false, true] as $delivered) {
            $fixture = $this->newFixture();
            $issuance = $fixture->original();
            $bytes = $fixture->preparedBytes($issuance);
            if ($delivered) {
                self::assertSame(AgentDeliveryResult::DELIVERED, $this->deliver($fixture, $issuance));
            }

            $fixture->advance(172800);
            $maintenance = $this->maintenance($fixture);
            if ($delivered) {
                self::assertSame(AgentMaintenanceResult::UNCHANGED, $maintenance->cleanup(
                    $issuance->getKey(),
                    $issuance->getDestination(),
                    $issuance->getDeliveryId()
                ));
                self::assertSame($bytes, $fixture->stagedBytes($issuance));
                $this->revoke($fixture, $issuance);
            } else {
                self::assertSame(AgentMaintenanceResult::EXPIRED, $maintenance->expire(
                    $issuance->getKey(),
                    $issuance->getDestination(),
                    $issuance->getDeliveryId()
                ));
            }

            self::assertSame(AgentMaintenanceResult::CLEANED, $maintenance->cleanup(
                $issuance->getKey(),
                $issuance->getDestination(),
                $issuance->getDeliveryId()
            ));
            $fixture->restart();
            self::assertNull($fixture->stagedBytes($issuance));
            self::assertNull($fixture->stored($issuance)->getMaterial());
            self::assertTrue($fixture->stored($issuance)->isSinkCleaned());
            self::assertGreaterThanOrEqual(
                $issuance->getDestinationWriteVersion(),
                $fixture->highWater($issuance->getDestination())
            );
            $this->readOperation($fixture, $issuance);
            self::assertSame([], $this->recover($fixture, $issuance));
            try {
                $fixture->ports()->sink->stage(new AgentCredentialInvocation($issuance, $bytes));
                self::fail('Cleanup must retain replay defenses.');
            } catch (AgentDeliveryFailedException) {
                self::assertNull($fixture->stagedBytes($issuance));
            }

            self::assertSame(1, $fixture->counts()['generations']);
        }
    }

    public function test_capacity_policy_and_storage_outage_do_not_disguise_existing_recovery(): void
    {
        $fixture = $this->newFixture();
        $issuance = $fixture->original();
        try {
            $this->provision(
                $fixture,
                new AgentOperationKey($issuance->getKey()->getScope(), AgentOperationId::generate()),
                $issuance->getDestination(),
                new AgentOperationLimits(512, 1, 1)
            );
            self::fail('Capacity must reject new work.');
        } catch (AgentOperationRejectedException $agentOperationRejectedException) {
            self::assertSame(AgentOperationFailure::CAPACITY, $agentOperationRejectedException->getReason());
        }

        $afterRejection = $fixture->counts();
        $this->readOperation($fixture, $issuance);
        $fixture->storageUnavailable();
        foreach (['status', 'discovery'] as $read) {
            try {
                $read === 'status' ? $this->readOperation($fixture, $issuance) : $this->recover($fixture, $issuance);
                self::fail('Storage outage cannot become absence or success.');
            } catch (AgentOperationRejectedException $agentOperationRejectedException) {
                self::assertSame(AgentOperationFailure::UNAVAILABLE, $agentOperationRejectedException->getReason());
                self::assertNull($agentOperationRejectedException->getPrevious());
                $this->assertSafe($fixture, [
                    $agentOperationRejectedException->getMessage(),
                    $agentOperationRejectedException->getTraceAsString()
                ]);
            }
        }

        $fixture->restart();
        self::assertSame(
            [$issuance->getDeliveryId()->toString() => AgentDeliveryResult::DELIVERED],
            $this->recover($fixture, $issuance, new AgentDeliverySchedule(1, 90))
        );
        self::assertSame($afterRejection['generations'], $fixture->counts()['generations']);
        self::assertSame($afterRejection['audit'], $fixture->counts()['audit']);
    }

    public function test_default_restart_skips_an_entire_obsolete_batch_without_manual_cleanup(): void
    {
        $fixture = $this->newFixture();
        $old = $fixture->original();
        $latest = $old;
        for ($index = 0; $index < 50; ++$index) {
            $latest = $this->provision(
                $fixture,
                new AgentOperationKey($old->getKey()->getScope(), AgentOperationId::generate()),
                $old->getDestination()
            );
        }

        $before = $fixture->counts();
        $fixture->restart();
        self::assertSame(
            [$latest->getDeliveryId()->toString() => AgentDeliveryResult::DELIVERED],
            $this->recover($fixture, $latest)
        );
        self::assertSame(0, $fixture->stored($old)->getStateRevision());
        self::assertNull($fixture->stagedBytes($old));
        self::assertSame(1, $fixture->counts()['sink']);
        self::assertSame($before['generations'], $fixture->counts()['generations']);
        self::assertSame($before['audit'], $fixture->counts()['audit']);
        self::assertSame([
            'lease_seconds'     => 60,
            'admission_seconds' => 15,
            'retry_seconds'     => 30,
            'retention_seconds' => 86400,
            'maximum_attempts'  => 100
        ], $fixture->stored($latest)->getDeliveryPolicy()?->toArray());
        self::assertSame([], $this->recover($fixture, $latest));
    }

    public function test_valid_overrides_pin_retry_retention_and_cleanup_without_manual_approval(): void
    {
        $fixture = $this->newFixture();
        $issuance = $fixture->original();
        $policy = new AgentDeliveryPolicy(10, 3, 4, 20, 2);
        $fixture->keyFailure(AgentDeliveryFailure::TEMPORARY);
        self::assertSame(AgentDeliveryResult::RETRYABLE, $this->deliver($fixture, $issuance, $policy));
        self::assertEquals($policy, $fixture->stored($issuance)->getDeliveryPolicy());
        $fixture->restart();
        $fixture->advance(9);
        self::assertSame([], $this->recover($fixture, $issuance));
        $fixture->advance(1);
        self::assertSame(
            [$issuance->getDeliveryId()->toString() => AgentDeliveryResult::DELIVERED],
            $this->recover($fixture, $issuance)
        );
        self::assertEquals(
            $policy,
            $fixture->stored($issuance)->getDeliveryPolicy(),
            'Restart must not restore defaults.'
        );
        $this->revoke($fixture, $issuance);
        $fixture->advance(14);
        $maintenance = $this->maintenance($fixture, new AgentMaintenancePolicy(1, 5));
        self::assertSame(AgentMaintenanceResult::UNCHANGED, $maintenance->cleanup(
            $issuance->getKey(),
            $issuance->getDestination(),
            $issuance->getDeliveryId()
        ));
        $fixture->advance(1);
        self::assertSame(AgentMaintenanceResult::CLEANED, $maintenance->cleanup(
            $issuance->getKey(),
            $issuance->getDestination(),
            $issuance->getDeliveryId()
        ));
        self::assertSame(1, $fixture->counts()['generations']);
    }

    public function test_revocation_publication_failure_remains_a_throw_after_durable_retirement(): void
    {
        $fixture = $this->newFixture();
        $issuance = $fixture->original();
        $fault = $fixture->failPublishers();
        $caught = null;
        try {
            $this->revoke($fixture, $issuance);
        } catch (Throwable $throwable) {
            $caught = $throwable;
        }

        self::assertSame($fault, $caught, 'Revocation rethrows its original publication fault after commit.');
        self::assertSame(2, $fixture->counts()['events']);
        self::assertSame(
            AgentCredentialDisposition::REVOKED,
            $this->readOperation($fixture, $issuance)->getCredentialDisposition()
        );
        self::assertNull($fixture->stored($issuance)->getMaterial());
        self::assertSame(AgentState::REVOKED, $fixture->ports()->agents->getById($issuance->getAgentId())?->getState());
        self::assertSame(0, $fixture->counts()['decryptions']);
        self::assertSame(0, $fixture->counts()['sink']);
    }
}
