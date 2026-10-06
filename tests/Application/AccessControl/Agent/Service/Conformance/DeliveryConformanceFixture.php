<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Service\Conformance;

use Closure;
use Fight\AccessControl\Application\AccessControl\Agent\Service\AgentCredentialCleanup;
use Fight\AccessControl\Application\AccessControl\Agent\Service\AgentDeliveryRewrapper;
use Fight\AccessControl\Application\AccessControl\Agent\Service\AgentMaintenanceAuthorization;
use Fight\AccessControl\Domain\AccessControl\Agent\AuthenticatedAgentPrincipal;
use Fight\AccessControl\Domain\AccessControl\Agent\Delivery\AgentDeliveryFailure;
use Fight\AccessControl\Domain\AccessControl\Agent\Delivery\AgentDeliveryReceipt;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentCredentialDestination;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentCredentialOperation;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentIssuance;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationScope;
use SensitiveParameter;
use Throwable;

/**
 * Isolated consumer adapters, fault injection and independent observation; never an alternate delivery workflow
 *
 * Hooks are one-shot barriers. Real bindings must synchronize independent connections/processes and retain
 * durable observers across restart. Reference callbacks model the specified schedule, not physical concurrency.
 */
abstract class DeliveryConformanceFixture
{
    /** Creates exactly one pending operation through AgentProvisioningService, not hand-authored delivery state */
    abstract public function original(): AgentIssuance;

    /** Shares the capability bundle with issuance conformance without inheriting its workflow or fixture */
    abstract public function ports(): IssuanceRecoveryPorts;

    abstract public function maintenanceAuthorization(): AgentMaintenanceAuthorization;

    abstract public function rewrapper(): AgentDeliveryRewrapper;

    abstract public function cleanupSink(): AgentCredentialCleanup;

    /** Grants test-owned scope/destination authority through the consumer's fenced writer */
    abstract public function allow(AgentOperationScope $scope, AgentCredentialDestination $destination): void;

    /** Retains storage/sink/authority, discards request services and transient hooks/faults */
    abstract public function restart(): void;

    abstract public function advance(int $seconds): void;

    /**
     * Runs once at the next delivery's specified boundary (not at a later nested request)
     *
     * @param 'claim'|'admission'|'materialized'|'invoking'|'accepted'|'verifying'|'fenced' $boundary
     */
    abstract public function pause(string $boundary, Closure $action): void;

    /**
     * Uses actual consumer authority writers and advances epochs even for revoke/regrant ABA
     *
     * @param 'caller'|'permission'|'delegation'|'destination'|'aba' $change
     */
    abstract public function changeAuthority(string $change, AgentIssuance $issuance): void;

    /** Sets the authenticated worker's absolute authority deadline relative to current trusted time */
    abstract public function expireAuthorityAfter(int $seconds): void;

    /** Loses the Nth subsequent transaction acknowledgement after either commit or rollback */
    abstract public function loseCommit(int $stage, bool $persist): void;

    /** Injects failure after the Nth delivery write, before commit, to prove rollback */
    abstract public function failWrite(int $stage): void;

    /** Throws on actual status/discovery storage access; absence is not a substitute */
    abstract public function storageUnavailable(): void;

    abstract public function keyFailure(?AgentDeliveryFailure $failure): void;

    /** Corrupts or swaps ciphertext from source without altering original correlation, policy or revision */
    abstract public function corruptMaterial(AgentIssuance $issuance, ?AgentIssuance $source = null): void;

    abstract public function loseSinkResponse(): void;

    abstract public function forgetSinkMaterial(AgentIssuance $issuance): void;

    /** Returns the exact fault thrown by both publishers for original-throwable identity assertions */
    abstract public function failPublishers(): Throwable;

    /**
     * Returns independently loaded persisted state; it must not drive the worker
     *
     * @phpstan-impure
     */
    abstract public function stored(AgentIssuance $issuance): AgentCredentialOperation;

    /** Reads test bytes only as an oracle; no worker/production secret-read API is implied */
    abstract public function preparedBytes(AgentIssuance $issuance): string;

    /** Resolves a fresh signed request through the package principal provider, never a saved principal */
    abstract public function authenticate(
        AgentIssuance $issuance,
        #[SensitiveParameter] string $secret
    ): AuthenticatedAgentPrincipal;

    abstract public function stagedBytes(AgentIssuance $issuance): ?string;

    abstract public function receipt(AgentIssuance $issuance): ?AgentDeliveryReceipt;

    abstract public function highWater(AgentCredentialDestination $destination): int;

    /** @return array{decryptions: int, sink: int, transactions: int, generations: int, audit: int, events: int} */
    abstract public function counts(): array;

    /** @return list<mixed> Safe event/audit representations including failed publication attempts */
    abstract public function safeEvidence(): array;

    /** @return list<string> Test-only secret/ciphertext/provider sentinels, including retired material */
    abstract public function forbiddenValues(): array;
}
