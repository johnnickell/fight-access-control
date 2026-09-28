<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Service\Conformance;

use Closure;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentDeliveryMaintenanceService;
use Fight\AccessControl\Domain\AccessControl\Agent\AuthenticatedAgentPrincipal;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentCredentialDestination;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentCredentialOperation;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentIssuance;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationKey;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationScope;
use Fight\Common\Domain\Messaging\Event\Event;

/**
 * Consumer binding for observable state and fault injection, never lifecycle policy
 *
 * Each fixture owns isolated storage. Faults remain armed until restart, except the next-commit fault.
 * Real bindings must use independent connections/processes for contention and restart.
 */
abstract class IssuanceRecoveryFixture
{
    abstract public function ports(): IssuanceRecoveryPorts;

    abstract public function allow(AgentOperationScope $scope, AgentCredentialDestination $destination): void;

    /** Discards caller services, publisher memory and faults; retains only durable state and observer counters */
    abstract public function restart(): void;

    abstract public function advance(int $seconds): void;

    /** Fails both success and failure publication until restart */
    abstract public function failPublishers(): void;

    /** @return list<Event> Attempted notifications, including those rejected by the publisher */
    abstract public function publications(): array;

    /** Loses the next transaction acknowledgement, either committing or rolling back its completed callback */
    abstract public function loseCommit(bool $committed): void;

    /** @param 'authorization'|'persistence'|'audit' $stage */
    abstract public function failBeforeCommit(string $stage): void;

    /** Revokes original-scope authority through the real authority writer, honoring any held fence */
    abstract public function revokeAuthority(AgentOperationScope $scope): void;

    /** Starts the authority writer while the next issuance holds its authorization fence */
    abstract public function contendWithAuthorityRevocation(AgentOperationScope $scope): void;

    /** Establishes an authenticated worker distinct from the originator with explicit scope delegation */
    abstract public function delegateWorker(bool $allowed): void;

    /** Revokes delegation after discovery but before claim/admission */
    abstract public function revokeWorkerAfterDiscovery(): void;

    /** Persists an unsupported canonical version for compatibility rejection, without altering its binding */
    abstract public function setCanonicalVersion(AgentOperationKey $key, int $version): void;

    /** Returns persisted state for independent assertions; not used to drive recovery */
    abstract public function stored(AgentOperationKey $key): ?AgentCredentialOperation;

    /**
     * @return array{agents: int, operations: int, audit: int, participant: int,
     *     reservations: array<string, int>, credentials: array<string, string>, audit_facts: list<string>,
     *     issuance_facts: array<string, string>}
     */
    abstract public function state(): array;

    abstract public function generations(): int;

    /** Counts entered package transactions, including rolled-back attempts */
    abstract public function transactions(): int;

    abstract public function sinkCalls(): int;

    abstract public function stagedBytes(AgentIssuance $issuance): ?string;

    /** Reads prepared bytes ONLY as a test oracle before delivery; never supplies the worker */
    abstract public function preparedBytes(AgentIssuance $issuance): string;

    /** Loses the sink response after persisting its exact invocation; retry must retain ID and bytes */
    abstract public function loseSinkResponse(): void;

    /** Makes the protected sink return an unverifiable receipt */
    abstract public function invalidateReceipt(): void;

    /** Authenticates a fresh request using delivered bytes and current authority, never a saved principal */
    abstract public function authenticateDelivered(AgentIssuance $issuance): AuthenticatedAgentPrincipal;

    /** @return list<string> Fixture-only sentinels for secrets, ciphertext, provider messages and key paths */
    abstract public function forbiddenValues(): array;

    /** @return list<mixed> Safe published event/audit representations and diagnostics */
    abstract public function safeEvidence(): array;

    /** Uses the real package maintenance service with the same repositories and authority fences */
    abstract public function maintenance(): AgentDeliveryMaintenanceService;

    /**
     * Runs contending requests against the same authority/key; returns outcomes in submission order
     *
     * The reference binding models a serialized schedule. Real bindings must synchronize independent
     * transactions before lookup/CAS and retain evidence of contention, not merely run this sequentially.
     *
     * @param list<Closure(): mixed> $requests
     * @return list<mixed> Results or caught throwables
     */
    abstract public function contend(array $requests): array;
}
