<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery;

use Fight\AccessControl\Domain\AccessControl\Agent\AgentState;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentCredentialDisposition;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentCredentialOperation;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentDeliveryDisposition;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Credential\ProvisioningEnvironment;

/**
 * Supplies controlled expected-state writes, not a delivery worker or sink qualification
 */
final readonly class ControlledAgentDeliveryWriter
{
    public function __construct(private ProvisioningEnvironment $environment)
    {
    }

    public function advance(AgentCredentialOperation $expected, int $epoch, bool $complete = false): bool
    {
        $issuance = $expected->getIssuance();
        $key = $issuance->getKey()->toString();
        $current = $this->environment->operationRepository->operations[$key];
        $agent = $this->environment->agentRepository->getById($issuance->getAgentId());
        $destination = $issuance->getDestination();
        $binding = $this->environment->operationAuthorization->destinations[$destination->getId()->toString()] ?? null;
        if (
            $this->environment->operationRepository->retirementLocked
            || $current !== $expected
            || !$current->hasPendingDeliveryAtRevision($expected->getStateRevision())
            || $agent?->getState() !== AgentState::ACTIVE
            || !$agent->getCredentialId()->equals($issuance->getCredentialId())
            || $agent->getCredentialRevision() !== $issuance->getCredentialRevision()
            || $this->environment->operationAuthorization->epoch !== $epoch
            || !($this->environment->operationAuthorization->scopes[
                $issuance->getKey()->getScope()->toString()
            ] ?? false)
            || $this->environment->operationAuthorization->delegationExpired
            || $binding !== $destination->getRevision()
        ) {
            return false;
        }

        $material = $current->getMaterial();
        $disposition = AgentDeliveryDisposition::PENDING;
        if ($complete) {
            $material = null;
            $disposition = AgentDeliveryDisposition::DELIVERED;
        }

        $this->environment->operationRepository->operations[$key] = new AgentCredentialOperation(
            $current->getCanonicalVersion(),
            $current->getCanonicalRequest(),
            $issuance,
            $material,
            $disposition,
            AgentCredentialDisposition::CURRENT,
            $current->getStateRevision() + 1
        );

        return true;
    }
}
