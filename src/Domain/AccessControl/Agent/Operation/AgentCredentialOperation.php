<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent\Operation;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentState;
use Fight\AccessControl\Domain\AccessControl\Agent\Delivery\AgentDeliveryAttempt;
use Fight\AccessControl\Domain\AccessControl\Agent\Delivery\AgentDeliveryAuthority;
use Fight\AccessControl\Domain\AccessControl\Agent\Delivery\AgentDeliveryClaimId;
use Fight\AccessControl\Domain\AccessControl\Agent\Delivery\AgentDeliveryFailure;
use Fight\AccessControl\Domain\AccessControl\Agent\Delivery\AgentDeliveryPolicy;
use Fight\AccessControl\Domain\AccessControl\Agent\Delivery\AgentDeliveryReceipt;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentOperationRejectedException;
use Fight\AccessControl\Domain\AccessControl\Agent\Maintenance\AgentMaintenancePolicy;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\AgentOperationView;
use SensitiveParameter;

/**
 * Class AgentCredentialOperation
 *
 * Owns immutable request correlation and the prepared delivery copy's independent lifetime.
 */
class AgentCredentialOperation
{
    /**
     * Constructs AgentCredentialOperation
     *
     * Retains unknown canonical versions during hydration so resolution can reject rather than reissue.
     */
    public function __construct(
        private readonly int $canonicalVersion,
        private readonly string $canonicalRequest,
        private readonly AgentIssuance $issuance,
        #[SensitiveParameter] private readonly ?AgentDeliveryMaterial $material,
        private readonly AgentDeliveryDisposition $deliveryDisposition = AgentDeliveryDisposition::PENDING,
        private readonly AgentCredentialDisposition $credentialDisposition = AgentCredentialDisposition::CURRENT,
        private readonly int $stateRevision = 0,
        private readonly ?AgentDeliveryAttempt $attempt = null,
        private readonly ?AgentDeliveryPolicy $deliveryPolicy = null,
        private readonly ?DateTimeImmutable $retryAt = null,
        private readonly ?AgentDeliveryReceipt $receipt = null,
        private readonly ?AgentDeliveryFailure $deliveryFailure = null,
        private readonly bool $sinkCleaned = false
    ) {
        if ($stateRevision < 0) {
            throw new AgentOperationRejectedException(AgentOperationFailure::INVALID_REQUEST);
        }
    }

    /**
     * Resolves the original request after validating its persisted contract before any new-work admission
     */
    public function resolve(AgentProvisioningRequest|AgentRotationRequest $request): AgentIssuance
    {
        AgentOperationCanonicalization::assertSupported($this->canonicalVersion);
        if ($request->canonicalize() !== $this->canonicalRequest) {
            throw new AgentOperationRejectedException(AgentOperationFailure::CONFLICT);
        }

        return $this->issuance;
    }

    /**
     * Returns the persisted canonical version without rewriting historical bindings
     */
    public function getCanonicalVersion(): int
    {
        return $this->canonicalVersion;
    }

    /**
     * Returns sufficient safe request evidence for permanent key retention
     */
    public function getCanonicalRequest(): string
    {
        return $this->canonicalRequest;
    }

    /**
     * Returns original issuance separately from pending delivery material
     */
    public function getIssuance(): AgentIssuance
    {
        return $this->issuance;
    }

    /**
     * Returns recorded safe state without deriving delivery success from issuance or material absence
     *
     * Adapters persist both dispositions with their owning lifecycle writes; queries never advance them.
     */
    public function getStatus(): AgentOperationView
    {
        return AgentOperationView::confirmed(
            $this->canonicalVersion,
            $this->issuance,
            $this->deliveryDisposition,
            $this->credentialDisposition
        );
    }

    /**
     * Returns the prepared copy only for authorized persistence and internal delivery coordination
     */
    public function getMaterial(): ?AgentDeliveryMaterial
    {
        return $this->material;
    }

    /**
     * Returns the expected-state revision shared by delivery and lifecycle writes
     */
    public function getStateRevision(): int
    {
        return $this->stateRevision;
    }

    /**
     * Returns whether this exact delivery snapshot still has unfinished material for its original credential
     *
     * This is an expected-state invariant, not caller authorization or permission to materialize. Writers must also
     * check authoritative Agent/destination state, claim/admission identity, epochs and deadlines under shared fences.
     */
    public function hasPendingDeliveryAtRevision(int $expectedRevision): bool
    {
        return $this->stateRevision === $expectedRevision
            && $this->credentialDisposition === AgentCredentialDisposition::CURRENT
            && $this->material !== null
            && in_array($this->deliveryDisposition, [
                AgentDeliveryDisposition::PENDING,
                AgentDeliveryDisposition::RETRYABLE
            ], true);
    }

    /**
     * Returns the next discovery time for unfinished work without mutating or granting admission
     *
     * Expired retention remains discoverable for authorized terminalization, even before lease or retry expiry.
     */
    public function getDeliveryDueAt(): ?DateTimeImmutable
    {
        if (!$this->hasPendingDeliveryAtRevision($this->stateRevision)) {
            return null;
        }

        $issuedAt = $this->issuance->getIssuedAt();
        $policy = $this->deliveryPolicy ?? new AgentDeliveryPolicy();

        return min(
            $policy->retainUntil($issuedAt),
            max($issuedAt, $this->attempt?->getLeaseUntil() ?? $issuedAt, $this->retryAt ?? $issuedAt)
        );
    }

    /**
     * Returns whether current authority may reconcile an existing admission without rematerializing
     *
     * A confirmed read of persisted admission permits receipt lookup only, never another sensitive invocation.
     */
    public function canReconcileDelivery(AgentDeliveryAuthority $authority, DateTimeImmutable $now): bool
    {
        if (
            !$this->hasPendingDeliveryAtRevision($this->stateRevision)
            || $this->deliveryDisposition !== AgentDeliveryDisposition::PENDING
            || $this->attempt?->getDeadline() === null || $now >= $this->attempt->getDeadline()
        ) {
            return false;
        }

        $this->attempt->assertCurrent($this->attempt, $authority, $now);

        return true;
    }

    /**
     * Returns the persisted attempt without granting materialization authority
     */
    public function getAttempt(): ?AgentDeliveryAttempt
    {
        return $this->attempt;
    }

    /**
     * Returns a required persisted attempt or rejects an incomplete delivery snapshot
     */
    public function requireAttempt(): AgentDeliveryAttempt
    {
        if ($this->attempt === null) {
            throw new AgentOperationRejectedException(AgentOperationFailure::CONFLICT);
        }

        return $this->attempt;
    }

    /**
     * Returns delivery material only from a pending admitted snapshot within its recorded deadline
     *
     * The Application caller must separately establish confirmed admission commit before using this snapshot.
     */
    public function getAdmittedMaterial(DateTimeImmutable $now): AgentDeliveryMaterial
    {
        $this->requireAttempt()->assertAdmittedAt($now);
        if ($this->material === null) {
            throw new AgentOperationRejectedException(AgentOperationFailure::CONFLICT);
        }

        $this->assertPendingDelivery();

        return $this->material;
    }

    /**
     * Returns the policy pinned by the first delivery claim
     */
    public function getDeliveryPolicy(): ?AgentDeliveryPolicy
    {
        return $this->deliveryPolicy;
    }

    /**
     * Returns the earliest retry time after a transient failure
     */
    public function getRetryAt(): ?DateTimeImmutable
    {
        return $this->retryAt;
    }

    /**
     * Returns the verified durable acknowledgement retained after delivery material retirement
     */
    public function getReceipt(): ?AgentDeliveryReceipt
    {
        return $this->receipt;
    }

    /**
     * Returns only the closed failure classification without provider diagnostics
     */
    public function getDeliveryFailure(): ?AgentDeliveryFailure
    {
        return $this->deliveryFailure;
    }

    /**
     * Validates the exact current credential without reading its authentication envelope
     */
    public function assertDeliveryCredential(#[SensitiveParameter] ?Agent $agent): void
    {
        if (
            $agent === null || $agent->getState() !== AgentState::ACTIVE
            || !$this->issuance->getAgentId()->equals($agent->getId())
            || !$this->issuance->getCredentialId()->equals($agent->getCredentialId())
            || $this->issuance->getCredentialRevision() !== $agent->getCredentialRevision()
            || $this->credentialDisposition !== AgentCredentialDisposition::CURRENT
        ) {
            throw new AgentOperationRejectedException(AgentOperationFailure::CONFLICT);
        }
    }

    /**
     * Creates a leased attempt or retires exhausted material under current authorization
     */
    public function claimDelivery(
        AgentDeliveryClaimId $claimId,
        AgentDeliveryPolicy $policy,
        DateTimeImmutable $now
    ): self {
        $this->assertPendingDelivery();
        $policy = $this->deliveryPolicy ?? $policy;
        if ($now >= $policy->retainUntil($this->issuance->getIssuedAt())) {
            return $this->withDelivery(null, AgentDeliveryDisposition::EXPIRED, $this->attempt, $policy);
        }

        if (
            ($this->attempt !== null && $now < $this->attempt->getLeaseUntil())
            || ($this->retryAt !== null && $now < $this->retryAt)
        ) {
            throw new AgentOperationRejectedException(AgentOperationFailure::CONTENTION);
        }

        $fence = ($this->attempt?->getFence() ?? 0) + 1;
        if (!$policy->permitsAttempt($fence)) {
            return $this->withDelivery(null, AgentDeliveryDisposition::TERMINAL, $this->attempt, $policy);
        }

        return $this->withDelivery(
            $this->material,
            AgentDeliveryDisposition::PENDING,
            new AgentDeliveryAttempt($fence, $claimId, $policy->leaseUntil($now)),
            $policy
        );
    }

    /**
     * Creates sensitive admission for the exact persisted claim
     */
    public function admitDelivery(
        AgentDeliveryAttempt $claim,
        AgentDeliveryAuthority $authority,
        DateTimeImmutable $now,
        int $expectedRevision
    ): self {
        $this->assertPendingDelivery();
        if (
            $this->stateRevision !== $expectedRevision
            || $this->attempt === null || $this->deliveryPolicy === null
            || $claim->getFence() !== $this->attempt->getFence()
            || !$claim->getClaimId()->equals($this->attempt->getClaimId())
        ) {
            throw new AgentOperationRejectedException(AgentOperationFailure::CONFLICT);
        }

        return $this->withDelivery(
            $this->material,
            AgentDeliveryDisposition::PENDING,
            $this->attempt->admit(
                $authority,
                $this->deliveryPolicy,
                $this->deliveryPolicy->retainUntil($this->issuance->getIssuedAt()),
                $now
            ),
            $this->deliveryPolicy
        );
    }

    /**
     * Creates a fenced outcome without reviving stale claims or extending original material retention
     */
    public function finishDelivery(
        AgentDeliveryAttempt $admission,
        AgentDeliveryAuthority $authority,
        AgentDeliveryReceipt|AgentDeliveryFailure $outcome,
        DateTimeImmutable $now,
        int $expectedRevision
    ): self {
        $this->assertPendingDelivery();
        if (
            $this->stateRevision !== $expectedRevision
            || $this->attempt === null || $this->deliveryPolicy === null
        ) {
            throw new AgentOperationRejectedException(AgentOperationFailure::CONFLICT);
        }

        $this->attempt->assertCurrent($admission, $authority, $now);
        $material = null;
        $retryAt = null;
        $receipt = null;
        $failure = null;
        $disposition = AgentDeliveryDisposition::DELIVERED;
        if ($outcome instanceof AgentDeliveryReceipt) {
            $receipt = $outcome;
        } else {
            $failure = $outcome;
            $disposition = AgentDeliveryDisposition::TERMINAL;
            if ($outcome === AgentDeliveryFailure::TEMPORARY) {
                $disposition = AgentDeliveryDisposition::RETRYABLE;
                $material = $this->material;
                $retryAt = $this->deliveryPolicy->retryAt($now);
            }
        }

        return $this->withDelivery(
            $material,
            $disposition,
            $this->attempt,
            $this->deliveryPolicy,
            $retryAt,
            $receipt,
            $failure
        );
    }

    /**
     * Returns whether original material has reached its pinned retention boundary
     */
    public function isMaterialExpired(DateTimeImmutable $now): bool
    {
        return $this->material !== null && $now >= $this->retentionEnd();
    }

    /**
     * Creates a rewrapped copy without changing original binding, attempt, policy or delivery history
     */
    public function rewrapMaterial(#[SensitiveParameter] AgentDeliveryMaterial $material, DateTimeImmutable $now): self
    {
        $this->assertPendingDelivery();
        if ($this->isMaterialExpired($now)) {
            throw new AgentOperationRejectedException(AgentOperationFailure::CONFLICT);
        }

        return $this->withDelivery(
            $material,
            $this->deliveryDisposition,
            $this->attempt,
            $this->deliveryPolicy ?? new AgentDeliveryPolicy(),
            $this->retryAt,
            $this->receipt,
            $this->deliveryFailure
        );
    }

    /**
     * Creates expiry without requiring a current slot reservation, claim or key access
     */
    public function expireMaterial(DateTimeImmutable $now): self
    {
        if (!$this->isMaterialExpired($now)) {
            return $this;
        }

        $this->assertPendingDelivery();

        return $this->withDelivery(
            null,
            AgentDeliveryDisposition::EXPIRED,
            $this->attempt,
            $this->deliveryPolicy ?? new AgentDeliveryPolicy()
        );
    }

    /**
     * Creates terminal delivery failure without retiring or replacing authentication authority
     */
    public function failMaterial(AgentDeliveryFailure $failure): self
    {
        $this->assertPendingDelivery();
        if (!in_array($failure, [AgentDeliveryFailure::KEY_RETIRED, AgentDeliveryFailure::CORRUPT_MATERIAL], true)) {
            throw new AgentOperationRejectedException(AgentOperationFailure::INVALID_REQUEST);
        }

        return $this->withDelivery(
            null,
            AgentDeliveryDisposition::TERMINAL,
            $this->attempt,
            $this->deliveryPolicy ?? new AgentDeliveryPolicy(),
            null,
            null,
            $failure
        );
    }

    /**
     * Returns whether irreversible terminal state permits exact inert-entry cleanup after its recovery window
     *
     * A current delivered credential remains protected indefinitely; cleanup cannot remove its active sink entry.
     */
    public function canCleanup(DateTimeImmutable $now, AgentMaintenancePolicy $policy): bool
    {
        return $this->canonicalVersion === AgentOperationCanonicalization::VERSION
            && !$this->sinkCleaned && $this->material === null
            && !in_array($this->deliveryDisposition, [
                AgentDeliveryDisposition::PENDING,
                AgentDeliveryDisposition::RETRYABLE
            ], true)
            && ($this->deliveryDisposition !== AgentDeliveryDisposition::DELIVERED
                || $this->credentialDisposition !== AgentCredentialDisposition::CURRENT)
            && $now >= $policy->cleanupAfter($this->retentionEnd());
    }

    /**
     * Returns confirmed external cleanup separately from material absence and delivery acknowledgement
     */
    public function isSinkCleaned(): bool
    {
        return $this->sinkCleaned;
    }

    /**
     * Creates a cleanup acknowledgement while retaining permanent correlation and lifecycle history
     */
    public function confirmCleanup(DateTimeImmutable $now, AgentMaintenancePolicy $policy): self
    {
        if (!$this->canCleanup($now, $policy)) {
            throw new AgentOperationRejectedException(AgentOperationFailure::CONFLICT);
        }

        return new self(
            $this->canonicalVersion,
            $this->canonicalRequest,
            $this->issuance,
            null,
            $this->deliveryDisposition,
            $this->credentialDisposition,
            $this->stateRevision + 1,
            $this->attempt,
            $this->deliveryPolicy,
            $this->retryAt,
            $this->receipt,
            $this->deliveryFailure,
            true
        );
    }

    /**
     * Creates a retired original credential snapshot invalidating all outstanding delivery work
     *
     * Persist together with the validated Agent successor under shared transaction-duration fences. Never use a
     * successor's material or destination to rewrite this original operation. Delivered/failed history stays distinct
     * from current credential authority; removing material cannot recall an already admitted external invocation.
     */
    public function retireCredential(
        #[SensitiveParameter] Agent $expected,
        #[SensitiveParameter] Agent $replacement
    ): self {
        if (
            !$expected->canReplaceCredentialWith($replacement)
            || !$this->issuance->getAgentId()->equals($expected->getId())
            || !$this->issuance->getCredentialId()->equals($expected->getCredentialId())
            || $this->issuance->getCredentialRevision() !== $expected->getCredentialRevision()
            || $this->credentialDisposition !== AgentCredentialDisposition::CURRENT
        ) {
            throw new AgentOperationRejectedException(AgentOperationFailure::CONFLICT);
        }

        $credentialDisposition = AgentCredentialDisposition::SUPERSEDED;
        if ($replacement->getState() === AgentState::REVOKED) {
            $credentialDisposition = AgentCredentialDisposition::REVOKED;
        }

        return new self(
            $this->canonicalVersion,
            $this->canonicalRequest,
            $this->issuance,
            null,
            $this->retiredDeliveryDisposition(),
            $credentialDisposition,
            $this->stateRevision + 1,
            $this->attempt,
            $this->deliveryPolicy,
            null,
            $this->receipt,
            $this->deliveryFailure,
            $this->sinkCleaned
        );
    }

    /**
     * Returns a permanent correlation tombstone without the delivery copy
     *
     * Downstream retirement must persist this with the lifecycle outcome under shared expected-state fences.
     * Absence of material is not evidence of successful delivery.
     */
    public function retireMaterial(): self
    {
        return new self(
            $this->canonicalVersion,
            $this->canonicalRequest,
            $this->issuance,
            null,
            $this->retiredDeliveryDisposition(),
            $this->credentialDisposition,
            $this->stateRevision + 1,
            $this->attempt,
            $this->deliveryPolicy,
            null,
            $this->receipt,
            $this->deliveryFailure,
            $this->sinkCleaned
        );
    }

    /**
     * Returns original retention without extending it during rewrap, restart or cleanup
     */
    private function retentionEnd(): DateTimeImmutable
    {
        return ($this->deliveryPolicy ?? new AgentDeliveryPolicy())->retainUntil($this->issuance->getIssuedAt());
    }

    /**
     * Validates pending delivery and its supported immutable binding
     */
    private function assertPendingDelivery(): void
    {
        AgentOperationCanonicalization::assertSupported($this->canonicalVersion);

        if (!$this->hasPendingDeliveryAtRevision($this->stateRevision)) {
            throw new AgentOperationRejectedException(AgentOperationFailure::CONFLICT);
        }
    }

    /**
     * Creates the next immutable delivery snapshot for an expected-state repository write
     */
    private function withDelivery(
        #[SensitiveParameter] ?AgentDeliveryMaterial $material,
        AgentDeliveryDisposition $disposition,
        ?AgentDeliveryAttempt $attempt,
        AgentDeliveryPolicy $policy,
        ?DateTimeImmutable $retryAt = null,
        ?AgentDeliveryReceipt $receipt = null,
        ?AgentDeliveryFailure $failure = null
    ): self {
        return new self(
            $this->canonicalVersion,
            $this->canonicalRequest,
            $this->issuance,
            $material,
            $disposition,
            $this->credentialDisposition,
            $this->stateRevision + 1,
            $attempt,
            $policy,
            $retryAt,
            $receipt,
            $failure
        );
    }

    /**
     * Returns retirement for unfinished work without overwriting a confirmed terminal delivery outcome
     */
    private function retiredDeliveryDisposition(): AgentDeliveryDisposition
    {
        if (
            $this->deliveryDisposition === AgentDeliveryDisposition::PENDING
            || $this->deliveryDisposition === AgentDeliveryDisposition::RETRYABLE
        ) {
            return AgentDeliveryDisposition::RETIRED;
        }

        return $this->deliveryDisposition;
    }
}
