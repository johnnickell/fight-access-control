<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent\Operation;

use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentOperationRejectedException;

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
        private readonly ?AgentDeliveryMaterial $material
    ) {
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
     * Returns the prepared copy only for authorized persistence and internal delivery coordination
     */
    public function getMaterial(): ?AgentDeliveryMaterial
    {
        return $this->material;
    }

    /**
     * Returns a permanent correlation tombstone without the delivery copy
     *
     * Downstream retirement must persist this with the lifecycle outcome under shared expected-state fences.
     * Absence of material is not evidence of successful delivery.
     */
    public function retireMaterial(): self
    {
        return new self($this->canonicalVersion, $this->canonicalRequest, $this->issuance, null);
    }
}
