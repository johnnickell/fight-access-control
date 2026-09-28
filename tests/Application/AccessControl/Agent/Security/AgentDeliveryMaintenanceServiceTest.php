<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Security;

use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentCredentialInvocation;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentDeliveryMaintenanceService;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentDeliveryResult;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentMaintenanceResult;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentState;
use Fight\AccessControl\Domain\AccessControl\Agent\Delivery\AgentDeliveryFailure;
use Fight\AccessControl\Domain\AccessControl\Agent\Delivery\AgentDeliveryPolicy;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentDeliveryCommitUncertainException;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentDeliveryFailedException;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentOperationRejectedException;
use Fight\AccessControl\Domain\AccessControl\Agent\Maintenance\AgentDeliveryKeyVersion;
use Fight\AccessControl\Domain\AccessControl\Agent\Maintenance\AgentMaintenancePolicy;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentCredentialDestination;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentCredentialOperation;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentDeliveryDisposition;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentDeliveryId;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentDeliveryMaterial;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentIssuance;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationId;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationKey;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationLimits;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\EncryptedCredentialMaterial;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\MaintenanceEnvironment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(AgentDeliveryMaintenanceService::class)]
#[CoversClass(AgentCredentialOperation::class)]
#[CoversClass(AgentDeliveryKeyVersion::class)]
#[CoversClass(AgentMaintenancePolicy::class)]
#[CoversClass(AgentDeliveryCommitUncertainException::class)]
final class AgentDeliveryMaintenanceServiceTest extends TestCase
{
    public function test_rewrap_preserves_original_bytes_identity_and_pinned_retry_history_across_restart(): void
    {
        $env = new MaintenanceEnvironment();
        $delivery = $env->delivery;
        $delivery->decipher->failure = AgentDeliveryFailure::TEMPORARY;
        self::assertSame(
            AgentDeliveryResult::RETRYABLE,
            $delivery->deliver(new AgentDeliveryPolicy(retentionSeconds: 172800))
        );
        $before = $delivery->operation();
        $envelope = $delivery->provisioning->agents->all()[0]->getEncryptedHmacSharedSecretEnvelope();
        self::assertSame(AgentMaintenanceResult::REWRAPPED, $env->rewrapOriginal());
        $after = $delivery->operation();
        self::assertSame($before->getIssuance(), $after->getIssuance());
        self::assertSame($before->getCanonicalRequest(), $after->getCanonicalRequest());
        self::assertSame($before->getAttempt(), $after->getAttempt());
        self::assertSame($before->getRetryAt(), $after->getRetryAt());
        self::assertSame($before->getDeliveryFailure(), $after->getDeliveryFailure());
        self::assertSame($before->getDeliveryPolicy(), $after->getDeliveryPolicy());
        self::assertSame('test-key-v2', $after->getMaterial()?->getKeyVersion());
        $repository = $delivery->provisioning->operations;
        self::assertSame(0, $repository->countDeliveryKeyReferences(new AgentDeliveryKeyVersion('test-key-v1')));
        self::assertSame(1, $repository->countDeliveryKeyReferences(new AgentDeliveryKeyVersion('test-key-v2')));
        self::assertSame(AgentMaintenanceResult::UNCHANGED, $env->rewrapOriginal());
        self::assertSame(1, $env->rewraps);
        $delivery->decipher->failure = null;
        $delivery->clock->advance(61);
        // A new service instance has no rewrap/attempt state; persisted operation drives recovery.
        self::assertSame(AgentDeliveryResult::DELIVERED, $delivery->deliver());
        self::assertSame('original-test-secret', $delivery->sink->stagedBytes($delivery->issuance));
        self::assertSame($envelope, $delivery->provisioning->agents->all()[0]->getEncryptedHmacSharedSecretEnvelope());
        self::assertSame(AgentMaintenanceResult::UNCHANGED, $env->rewrapOriginal('test-key-v3'));
        self::assertSame(1, $delivery->provisioning->generations);
        self::assertCount(1, $delivery->provisioning->audit->all());
    }

    public function test_transient_source_or_target_outage_retains_discoverable_original_version_and_recovers(): void
    {
        $env = new MaintenanceEnvironment();
        $before = $env->delivery->operation();
        $env->failure = AgentDeliveryFailure::TEMPORARY;
        self::assertSame(AgentMaintenanceResult::RETRYABLE, $env->rewrapOriginal());
        self::assertSame($before, $env->delivery->operation());
        self::assertNotNull($before->getDeliveryDueAt());
        self::assertSame('test-key-v1', $before->getMaterial()?->getKeyVersion());
        $env->failure = null;
        self::assertSame(AgentMaintenanceResult::REWRAPPED, $env->rewrapOriginal());
        self::assertSame(AgentDeliveryResult::DELIVERED, $env->delivery->deliver());
        self::assertSame('original-test-secret', $env->delivery->sink->stagedBytes($env->delivery->issuance));
    }

    /** @return iterable<string, array{AgentDeliveryFailure}> */
    public static function permanentFailures(): iterable
    {
        yield 'lost source key' => [AgentDeliveryFailure::KEY_RETIRED];
        yield 'corrupt material' => [AgentDeliveryFailure::CORRUPT_MATERIAL];
    }

    #[DataProvider('permanentFailures')]
    public function test_permanent_source_failure_never_falls_back_or_reissues(AgentDeliveryFailure $failure): void
    {
        $env = new MaintenanceEnvironment();
        $env->failure = $failure;
        self::assertSame(AgentMaintenanceResult::TERMINAL, $env->rewrapOriginal());
        self::assertNull($env->delivery->operation()->getMaterial());
        self::assertSame($failure, $env->delivery->operation()->getDeliveryFailure());
        self::assertSame(
            AgentDeliveryDisposition::TERMINAL,
            $env->delivery->operation()->getStatus()->getDeliveryDisposition()
        );
        self::assertSame(AgentDeliveryResult::TERMINAL, $env->delivery->deliver());
        self::assertSame(0, $env->delivery->decipher->calls);
        self::assertSame(1, $env->delivery->provisioning->generations);
        self::assertSame(AgentState::ACTIVE, $env->delivery->provisioning->agents->all()[0]->getState());
    }

    public function test_corrupt_persisted_ciphertext_cannot_be_rewrapped(): void
    {
        $env = new MaintenanceEnvironment();
        $original = $env->delivery->operation();
        $repository = $env->delivery->provisioning->operations;
        $repository->operations[$env->delivery->issuance->getKey()->toString()] = new AgentCredentialOperation(
            1,
            $original->getCanonicalRequest(),
            $original->getIssuance(),
            new AgentDeliveryMaterial(
                EncryptedCredentialMaterial::fromString('invalid-test-ciphertext'),
                'test-key-v1'
            )
        );
        self::assertSame(AgentMaintenanceResult::TERMINAL, $env->rewrapOriginal());
        self::assertSame(AgentDeliveryFailure::CORRUPT_MATERIAL, $env->delivery->operation()->getDeliveryFailure());
    }

    public function test_expiry_needs_no_key_or_current_destination_reservation_and_preserves_old_key_resolution(): void
    {
        $env = new MaintenanceEnvironment();
        self::assertSame(AgentMaintenanceResult::UNCHANGED, $env->expire());
        $slot = $env->delivery->issuance->getDestination()->getId()->toString();
        $env->delivery->provisioning->operations->versions[$slot] = 50;
        $env->delivery->clock->advance(86400);
        self::assertSame(AgentMaintenanceResult::EXPIRED, $env->expire());
        self::assertSame(AgentMaintenanceResult::UNCHANGED, $env->expire());
        self::assertSame(0, $env->rewraps);
        self::assertNull($env->delivery->operation()->getMaterial());
        self::assertSame(
            AgentDeliveryDisposition::EXPIRED,
            $env->delivery->operation()->getStatus()->getDeliveryDisposition()
        );
        self::assertSame(
            $env->delivery->issuance,
            $env->delivery->operation()->resolve($env->delivery->provisioning->request)
        );
        self::assertSame(1, $env->delivery->provisioning->generations);
    }

    public function test_rewrap_expires_original_before_any_key_access_and_does_not_extend_retention(): void
    {
        $env = new MaintenanceEnvironment();
        $env->delivery->clock->advance(86399);
        self::assertSame(AgentMaintenanceResult::REWRAPPED, $env->rewrapOriginal());
        $env->delivery->clock->advance(1);
        self::assertSame(AgentMaintenanceResult::EXPIRED, $env->rewrapOriginal('test-key-v3'));
        self::assertSame(1, $env->rewraps);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function uncertainWrites(): iterable
    {
        foreach (['rewrap', 'expiry', 'cleanup admission', 'cleanup acknowledgement'] as $operation) {
            yield $operation.' committed' => [$operation, true];
            yield $operation.' rolled back' => [$operation, false];
        }
    }

    #[DataProvider('uncertainWrites')]
    public function test_uncertain_commits_never_imply_external_completion(string $operation, bool $persist): void
    {
        $env = new MaintenanceEnvironment();
        $tx = $env->delivery->transaction;
        if (str_starts_with($operation, 'cleanup')) {
            $env->delivery->revoke();
            $env->delivery->clock->advance(172800);
        } elseif ($operation === 'expiry') {
            $env->delivery->clock->advance(86400);
        }

        $tx->uncertainAt = $operation === 'cleanup acknowledgement' ? 2 : 1;
        $tx->persistUncertain = $persist;

        $result = match ($operation) {
            'rewrap' => $env->rewrapOriginal(),
            'expiry' => $env->expire(),
            default => $env->cleanup()
        };
        self::assertSame(AgentMaintenanceResult::INDETERMINATE, $result);
        if ($operation === 'cleanup admission') {
            self::assertSame(0, $env->delivery->sink->cleanups);
            self::assertFalse($env->delivery->operation()->isSinkCleaned());
        } elseif ($operation === 'cleanup acknowledgement') {
            self::assertSame(1, $env->delivery->sink->cleanups);
            self::assertSame($persist, $env->delivery->operation()->isSinkCleaned());
        } elseif ($operation === 'rewrap') {
            self::assertSame(
                $persist ? 'test-key-v2' : 'test-key-v1',
                $env->delivery->operation()->getMaterial()?->getKeyVersion()
            );
        } else {
            self::assertSame($persist, $env->delivery->operation()->getMaterial() === null);
        }

        $tx->uncertainAt = null;
        $recovered = match ($operation) {
            'rewrap' => $env->rewrapOriginal(),
            'expiry' => $env->expire(),
            default => $env->cleanup()
        };
        self::assertContains($recovered, [
            AgentMaintenanceResult::REWRAPPED,
            AgentMaintenanceResult::UNCHANGED,
            AgentMaintenanceResult::EXPIRED,
            AgentMaintenanceResult::CLEANED
        ]);
        self::assertSame(1, $env->delivery->provisioning->generations);
    }

    /** @return iterable<string, array{string}> */
    public static function retirementRaces(): iterable
    {
        yield 'completion' => ['completion'];
        yield 'revocation' => ['revocation'];
        yield 'expiry' => ['expiry'];
    }

    #[DataProvider('retirementRaces')]
    public function test_stale_rewrap_write_cannot_restore_material_after_actual_terminal_path(string $race): void
    {
        $env = new MaintenanceEnvironment();
        $original = $env->delivery->operation();
        $material = new AgentDeliveryMaterial(
            EncryptedCredentialMaterial::fromString('test-stale-copy'),
            'test-key-v2'
        );
        $replacement = $original->rewrapMaterial($material, $env->delivery->clock->now());
        if ($race === 'completion') {
            self::assertSame(AgentDeliveryResult::DELIVERED, $env->delivery->deliver());
        } elseif ($race === 'revocation') {
            $env->delivery->revoke();
        } else {
            $env->delivery->clock->advance(86400);
            self::assertSame(AgentMaintenanceResult::EXPIRED, $env->expire());
        }

        try {
            $env->delivery->provisioning->transaction->commitTransactional(
                static function () use ($env, $original, $replacement): void {
                    $env->delivery->provisioning->authorization->holdFence();
                    $env->delivery->provisioning->operations->replaceMaintenance($original, $replacement);
                }
            );
            self::fail('Stale rewrap must reject.');
        } catch (AgentOperationRejectedException) {
            self::assertNull($env->delivery->operation()->getMaterial());
        }
    }

    public function test_cleanup_never_removes_current_delivered_material_but_revocation_makes_it_eligible(): void
    {
        $env = new MaintenanceEnvironment();
        self::assertSame(AgentDeliveryResult::DELIVERED, $env->delivery->deliver());
        $receipt = $env->delivery->operation()->getReceipt();
        $env->delivery->clock->advance(172800);
        self::assertSame(AgentMaintenanceResult::UNCHANGED, $env->cleanup());
        self::assertSame('original-test-secret', $env->delivery->sink->stagedBytes($env->delivery->issuance));
        $env->delivery->revoke();
        self::assertSame(AgentMaintenanceResult::CLEANED, $env->cleanup());
        self::assertNull($env->delivery->sink->stagedBytes($env->delivery->issuance));
        self::assertSame($receipt, $env->delivery->operation()->getReceipt());
        self::assertSame(
            AgentDeliveryDisposition::DELIVERED,
            $env->delivery->operation()->getStatus()->getDeliveryDisposition()
        );
        self::assertSame(AgentState::REVOKED, $env->delivery->provisioning->agents->all()[0]->getState());
        self::assertSame(AgentMaintenanceResult::UNCHANGED, $env->rewrapOriginal());
        self::assertSame(AgentMaintenanceResult::CLEANED, $env->cleanup());
        self::assertSame(1, $env->delivery->sink->cleanups);
    }

    public function test_cleanup_tombstones_not_yet_arrived_calls_and_keeps_order_through_replay(): void
    {
        $env = new MaintenanceEnvironment();
        $invocation = new AgentCredentialInvocation($env->delivery->issuance, 'original-test-secret');
        $env->delivery->revoke();
        self::assertSame(AgentMaintenanceResult::UNCHANGED, $env->cleanup());
        $env->delivery->clock->advance(172800);
        $slot = $env->delivery->issuance->getDestination()->getId()->toString();
        $env->delivery->sink->highWater[$slot] = 99;
        self::assertSame(AgentMaintenanceResult::CLEANED, $env->cleanup());
        self::assertSame(99, $env->delivery->sink->highWater[$slot]);
        try {
            $env->delivery->sink->stage($invocation);
            self::fail('Delayed call must never recreate erased bytes.');
        } catch (AgentDeliveryFailedException) {
            self::assertNull($env->delivery->sink->stagedBytes($env->delivery->issuance));
        }

        self::assertSame(
            $env->delivery->issuance,
            $env->delivery->operation()->resolve($env->delivery->provisioning->request)
        );
        self::assertTrue($env->delivery->operation()->isSinkCleaned());
        self::assertNull($env->delivery->operation()->getDeliveryDueAt());
    }

    public function test_lost_cleanup_response_retries_same_identity_without_claiming_database_atomicity(): void
    {
        $env = new MaintenanceEnvironment();
        $env->delivery->revoke();
        $env->delivery->clock->advance(172800);
        $env->delivery->sink->afterCleanup = static function (): void {
            throw new RuntimeException('unsafe-provider-path original-test-secret');
        };
        self::assertSame(AgentMaintenanceResult::UNAVAILABLE, $env->cleanup());
        self::assertFalse($env->delivery->operation()->isSinkCleaned());
        self::assertNull($env->delivery->sink->stagedBytes($env->delivery->issuance));
        $env->delivery->sink->afterCleanup = null;
        self::assertSame(AgentMaintenanceResult::CLEANED, $env->cleanup());
        self::assertSame(2, $env->delivery->sink->cleanups);
    }

    public function test_unsupported_sink_fails_closed_without_recording_cleanup(): void
    {
        $env = new MaintenanceEnvironment();
        $env->delivery->revoke();
        $env->delivery->clock->advance(172800);
        $env->delivery->sink->supported = false;
        self::assertSame(AgentMaintenanceResult::RETRYABLE, $env->cleanup());
        self::assertSame(0, $env->delivery->sink->cleanups);
        self::assertFalse($env->delivery->operation()->isSinkCleaned());
    }

    /** @return iterable<string, array{string}> */
    public static function authorizationFailures(): iterable
    {
        $cases = [
            'scope', 'target', 'expired', 'wrong delivery', 'missing operation', 'wrong destination', 'unknown version'
        ];
        foreach ($cases as $case) {
            yield $case => [$case];
        }
    }

    #[DataProvider('authorizationFailures')]
    public function test_authorization_and_exact_correlation_precede_key_access(string $case): void
    {
        $env = new MaintenanceEnvironment();
        $issuance = $env->delivery->issuance;
        $key = $issuance->getKey();
        $destination = $issuance->getDestination();
        $id = $issuance->getDeliveryId();
        if ($case === 'scope') {
            $env->permitted = false;
        } elseif ($case === 'target') {
            $env->targetPermitted = false;
        } elseif ($case === 'expired') {
            $env->expiresAt = $env->delivery->clock->now();
        } elseif ($case === 'wrong delivery') {
            $id = AgentDeliveryId::generate();
        } elseif ($case === 'missing operation') {
            $key = new AgentOperationKey($key->getScope(), AgentOperationId::generate());
        } elseif ($case === 'wrong destination') {
            $destination = new AgentCredentialDestination($destination->getId(), 2);
        } else {
            $before = $env->delivery->operation();
            $env->delivery->provisioning->operations->operations[$key->toString()] = new AgentCredentialOperation(
                99,
                $before->getCanonicalRequest(),
                $issuance,
                $before->getMaterial()
            );
        }

        self::assertSame(
            AgentMaintenanceResult::REJECTED,
            $env->service()->rewrap($key, $destination, $id, new AgentDeliveryKeyVersion('test-key-v2'))
        );
        self::assertSame(0, $env->rewraps);
        self::assertSame(0, $env->delivery->provisioning->operations->maintenanceWrites);
    }

    public function test_storage_cipher_and_wrong_target_failures_preserve_original_material(): void
    {
        $env = new MaintenanceEnvironment();
        $original = $env->delivery->operation();
        $env->wrongVersion = 'wrong-version';
        self::assertSame(AgentMaintenanceResult::UNAVAILABLE, $env->rewrapOriginal());
        self::assertSame($original, $env->delivery->operation());
        $env->wrongVersion = null;
        $env->delivery->provisioning->operations->afterMaintenanceWrite = static function (): void {
            throw new RuntimeException('original-test-secret unsafe provider trace');
        };
        self::assertSame(AgentMaintenanceResult::UNAVAILABLE, $env->rewrapOriginal());
        self::assertSame($original, $env->delivery->operation());
        $env->delivery->provisioning->operations->afterMaintenanceWrite = null;
        $env->afterRewrap = static function (): void {
            throw new RuntimeException('original-test-secret unsafe key path');
        };
        self::assertSame(AgentMaintenanceResult::UNAVAILABLE, $env->rewrapOriginal());
        self::assertSame($original, $env->delivery->operation());
        $env->delivery->transaction->closed = true;
        self::assertSame(AgentMaintenanceResult::UNAVAILABLE, $env->expire());
    }

    public function test_expired_authority_after_slow_rewrap_or_admission_commit_prevents_effect(): void
    {
        $env = new MaintenanceEnvironment();
        $env->expiresAt = $env->delivery->clock->now()->modify('+1 second');
        $env->afterRewrap = static fn () => $env->delivery->clock->advance(1);
        self::assertSame(AgentMaintenanceResult::REJECTED, $env->rewrapOriginal());
        self::assertSame('test-key-v1', $env->delivery->operation()->getMaterial()?->getKeyVersion());
        $env = new MaintenanceEnvironment();
        $env->delivery->revoke();
        $env->delivery->clock->advance(172800);
        $env->expiresAt = $env->delivery->clock->now()->modify('+1 second');
        $env->delivery->transaction->afterCommit = static fn () => $env->delivery->clock->advance(1);
        self::assertSame(AgentMaintenanceResult::REJECTED, $env->cleanup());
        self::assertSame(0, $env->delivery->sink->cleanups);
    }

    /** @return iterable<string, array{string}> */
    public static function cleanupRaces(): iterable
    {
        yield 'revoked delegation' => ['deny'];
        yield 'revoke regrant ABA' => ['epoch'];
        yield 'concurrent state change' => ['revision'];
        yield 'expired authority during external call' => ['expiry'];
    }

    #[DataProvider('cleanupRaces')]
    public function test_cleanup_cannot_acknowledge_stale_authority_or_revision(string $case): void
    {
        $env = new MaintenanceEnvironment();
        $env->delivery->revoke();
        $env->delivery->clock->advance(172800);
        $env->delivery->sink->afterCleanup = static function () use ($env, $case): void {
            if ($case === 'deny') {
                $env->permitted = false;
            } elseif ($case === 'epoch') {
                ++$env->epoch;
            } elseif ($case === 'expiry') {
                $env->delivery->clock->advance(30 * 86400);
            } else {
                $repository = $env->delivery->provisioning->operations;
                $key = $env->delivery->issuance->getKey()->toString();
                $repository->operations[$key] = $env->delivery->operation()->retireMaterial();
            }
        };
        self::assertSame(AgentMaintenanceResult::REJECTED, $env->cleanup());
        self::assertFalse($env->delivery->operation()->isSinkCleaned());
        self::assertSame(1, $env->delivery->sink->cleanups);
    }

    public function test_key_admission_fences_and_capacity_preserve_existing_recovery(): void
    {
        $env = new MaintenanceEnvironment();
        $repo = $env->delivery->provisioning->operations;
        $repo->closedKeyVersions['test-key-v2'] = true;
        self::assertSame(AgentMaintenanceResult::REJECTED, $env->rewrapOriginal());
        self::assertSame(0, $repo->countDeliveryKeyReferences(new AgentDeliveryKeyVersion('test-key-v2')));
        // Closing admission after authorization still rejects the expected-state write.
        $repo->closedKeyVersions['test-key-v2'] = false;
        $env->afterRewrap = static function () use ($repo): void {
            $repo->closedKeyVersions['test-key-v2'] = true;
        };
        self::assertSame(AgentMaintenanceResult::REJECTED, $env->rewrapOriginal());
        self::assertSame('test-key-v1', $env->delivery->operation()->getMaterial()?->getKeyVersion());
        $env->afterRewrap = null;
        $repo->closedKeyVersions['test-key-v2'] = false;
        $provisioning = $env->delivery->provisioning;
        try {
            $provisioning->service(limits: new AgentOperationLimits(pendingPerScope: 1, pendingTotal: 1))->provision(
                new AgentOperationKey($provisioning->key->getScope(), AgentOperationId::generate()),
                $provisioning->request
            );
            self::fail('New work must reject at capacity.');
        } catch (AgentOperationRejectedException) {
            self::assertSame($env->delivery->issuance, $env->delivery->operation()->getStatus()->getIssuance());
        }

        self::assertSame(AgentMaintenanceResult::REWRAPPED, $env->rewrapOriginal());
        self::assertSame(AgentDeliveryResult::DELIVERED, $env->delivery->deliver());
    }

    public function test_swapped_original_binding_terminalizes_without_accepting_another_operations_secret(): void
    {
        $env = new MaintenanceEnvironment();
        $other = new MaintenanceEnvironment();
        $original = $env->delivery->operation();
        $repository = $env->delivery->provisioning->operations;
        $repository->operations[$original->getIssuance()->getKey()->toString()] = new AgentCredentialOperation(
            1,
            $original->getCanonicalRequest(),
            $original->getIssuance(),
            $other->delivery->operation()->getMaterial()
        );
        self::assertSame(AgentMaintenanceResult::TERMINAL, $env->rewrapOriginal());
        self::assertNull($env->delivery->operation()->getMaterial());
        self::assertSame(0, $env->delivery->sink->calls);
        self::assertSame($original->getIssuance(), $env->delivery->operation()->getIssuance());
    }

    public function test_key_closure_rejects_new_issuance_but_allows_existing_delivery_to_drain(): void
    {
        $env = new MaintenanceEnvironment();
        $repository = $env->delivery->provisioning->operations;
        $version = new AgentDeliveryKeyVersion('test-key-v1');
        self::assertSame(1, $repository->countDeliveryKeyReferences($version));
        $repository->closedKeyVersions[$version->toString()] = true;
        $provisioning = $env->delivery->provisioning;
        $agentsBefore = $provisioning->agents->all();
        $envelope = $agentsBefore[0]->getEncryptedHmacSharedSecretEnvelope();
        try {
            $provisioning->service()->provision(
                new AgentOperationKey($provisioning->key->getScope(), AgentOperationId::generate()),
                $provisioning->request
            );
            self::fail('Closed key version cannot acquire a new reference.');
        } catch (AgentOperationRejectedException) {
            self::assertSame($agentsBefore, $provisioning->agents->all());
            self::assertSame(1, $repository->countDeliveryKeyReferences($version));
        }

        // Source material still exists; temporary recovery and completion may drain the closed version.
        $env->delivery->decipher->failure = AgentDeliveryFailure::TEMPORARY;
        self::assertSame(AgentDeliveryResult::RETRYABLE, $env->delivery->deliver());
        $env->delivery->clock->advance(61);
        $env->delivery->decipher->failure = null;
        self::assertSame(AgentDeliveryResult::DELIVERED, $env->delivery->deliver());
        self::assertSame(0, $repository->countDeliveryKeyReferences($version));
        self::assertSame($envelope, $provisioning->agents->all()[0]->getEncryptedHmacSharedSecretEnvelope());
    }

    public function test_cleanup_keeps_a_rebound_successor_and_rejects_changed_tombstone_binding(): void
    {
        $env = new MaintenanceEnvironment();
        self::assertSame(AgentDeliveryResult::DELIVERED, $env->delivery->deliver());
        $successor = $env->delivery->issuance->toArray();
        $successor['delivery_id'] = AgentDeliveryId::generate()->toString();
        $successor['agent_id'] = AgentDeliveryId::generate()->toString();
        $successor['credential_id'] = AgentDeliveryId::generate()->toString();
        $successor['namespace'] = 'other-scope';
        $successor['destination_revision'] = 2;
        $successor['destination_write_version'] = 2;
        $newIssuance = AgentIssuance::fromArray($successor);
        $newReceipt = $env->delivery->sink->stage(new AgentCredentialInvocation($newIssuance, 'successor-test-secret'));
        $env->delivery->revoke();
        $env->delivery->clock->advance(172800);
        self::assertSame(AgentMaintenanceResult::CLEANED, $env->cleanup());
        self::assertTrue($env->delivery->sink->verify($newReceipt, $newIssuance));
        self::assertSame('successor-test-secret', $env->delivery->sink->stagedBytes($newIssuance));
        $slot = $newIssuance->getDestination()->getId()->toString();
        self::assertSame(2, $env->delivery->sink->highWater[$slot]);
        $changed = $env->delivery->issuance->toArray();
        ++$changed['destination_revision'];
        try {
            $env->delivery->sink->remove(AgentIssuance::fromArray($changed));
            self::fail('Changed cleanup tuple must reject.');
        } catch (AgentDeliveryFailedException) {
            self::assertTrue($env->delivery->sink->verify($newReceipt, $newIssuance));
            self::assertSame(2, $env->delivery->sink->highWater[$slot]);
        }

        self::assertFalse($env->delivery->sink->verify($newReceipt, $env->delivery->issuance));
    }

    public function test_cleanup_override_preserves_explicit_recovery_window(): void
    {
        $env = new MaintenanceEnvironment();
        $env->delivery->revoke();
        $env->delivery->clock->advance(86400);

        $policy = new AgentMaintenancePolicy(1, 1);
        self::assertSame(AgentMaintenanceResult::UNCHANGED, $env->cleanup($policy));
        $env->delivery->clock->advance(1);
        self::assertSame(AgentMaintenanceResult::CLEANED, $env->cleanup($policy));
    }
}
