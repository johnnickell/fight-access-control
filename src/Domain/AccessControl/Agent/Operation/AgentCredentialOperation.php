<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent\Operation;

use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentState;
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
        private readonly int $stateRevision = 0
    ) {
        if ($stateRevision < 0) {
            throw new AgentOperationRejectedException(AgentOperationFailure::INVALID_REQUEST);
        }
    }

    /**
     * Resolves the original request using its persisted version before any new-work admission
     */
    public function resolve(AgentProvisioningRequest $request): AgentIssuance
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
            $this->stateRevision + 1
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
            $this->stateRevision + 1
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
