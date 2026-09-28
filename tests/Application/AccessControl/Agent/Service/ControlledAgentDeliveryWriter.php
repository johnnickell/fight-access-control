<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Service;

use Fight\AccessControl\Domain\AccessControl\Agent\AgentState;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentCredentialDisposition;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentCredentialOperation;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentDeliveryDisposition;

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
        $current = $this->environment->operations->operations[$key];
        $agent = $this->environment->agents->getById($issuance->getAgentId());
        $destination = $issuance->getDestination();
        $binding = $this->environment->authorization->destinations[$destination->getId()->toString()] ?? null;
        if (
            $this->environment->operations->retirementLocked
            || $current !== $expected
            || !$current->hasPendingDeliveryAtRevision($expected->getStateRevision())
            || $agent?->getState() !== AgentState::ACTIVE
            || !$agent->getCredentialId()->equals($issuance->getCredentialId())
            || $agent->getCredentialRevision() !== $issuance->getCredentialRevision()
            || $this->environment->authorization->epoch !== $epoch
            || !($this->environment->authorization->scopes[$issuance->getKey()->getScope()->toString()] ?? false)
            || $this->environment->authorization->delegationExpired
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

        $this->environment->operations->operations[$key] = new AgentCredentialOperation(
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
