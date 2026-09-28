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
        private readonly ?AgentDeliveryMaterial $material,
        private readonly AgentDeliveryDisposition $deliveryDisposition = AgentDeliveryDisposition::PENDING,
        private readonly AgentCredentialDisposition $credentialDisposition = AgentCredentialDisposition::CURRENT,
        private readonly int $stateRevision = 0,
        private readonly ?AgentDeliveryAttempt $attempt = null,
        private readonly ?AgentDeliveryPolicy $deliveryPolicy = null,
        private readonly ?DateTimeImmutable $retryAt = null,
        private readonly ?AgentDeliveryReceipt $receipt = null,
        private readonly ?AgentDeliveryFailure $deliveryFailure = null
    ) {
        if ($stateRevision < 0) {
            throw new AgentOperationRejectedException(AgentOperationFailure::INVALID_REQUEST);
        }
    }

    /**
     * Resolves the original request using its persisted version before any new-work admission
     */
    public function resolve(AgentProvisioningRequest|AgentRotationRequest $request): AgentIssuance
    {
        if ($request->canonicalize($this->canonicalVersion) !== $this->canonicalRequest) {
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
            $this->deliveryFailure
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
            $this->deliveryFailure
        );
    }

    /**
     * Validates pending delivery and its supported immutable binding
     */
    private function assertPendingDelivery(): void
    {
        if ($this->canonicalVersion !== 1) {
            throw new AgentOperationRejectedException(AgentOperationFailure::UNSUPPORTED_VERSION);
        }

        if (!$this->hasPendingDeliveryAtRevision($this->stateRevision)) {
            throw new AgentOperationRejectedException(AgentOperationFailure::CONFLICT);
        }
    }

    /**
     * Creates the next immutable delivery snapshot for an expected-state repository write
     */
    private function withDelivery(
        ?AgentDeliveryMaterial $material,
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
