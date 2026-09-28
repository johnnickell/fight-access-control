<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent\Operation;

use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentOperationRejectedException;

/**
 * Class AgentRotationRequest
 *
 * Retains the original predecessor on retries even after its successor becomes current.
 */
final readonly class AgentRotationRequest
{
    /**
     * Constructs AgentRotationRequest
     */
    public function __construct(
        private AgentId $agentId,
        private AgentCredentialId $expectedCredentialId,
        private int $expectedCredentialRevision,
        private AgentCredentialDestination $destination
    ) {
        if ($expectedCredentialRevision < 0 || $expectedCredentialRevision === PHP_INT_MAX) {
            throw new AgentOperationRejectedException(AgentOperationFailure::INVALID_REQUEST);
        }
    }

    /**
     * Returns the target Agent identity
     */
    public function getAgentId(): AgentId
    {
        return $this->agentId;
    }

    /**
     * Returns the original expected predecessor identity
     */
    public function getExpectedCredentialId(): AgentCredentialId
    {
        return $this->expectedCredentialId;
    }

    /**
     * Returns the original expected predecessor revision
     */
    public function getExpectedCredentialRevision(): int
    {
        return $this->expectedCredentialRevision;
    }

    /**
     * Returns the registered successor destination binding
     */
    public function getDestination(): AgentCredentialDestination
    {
        return $this->destination;
    }

    /**
     * Returns the package-owned request binding under its persisted canonical version
     */
    public function canonicalize(int $version): string
    {
        if ($version !== 1) {
            throw new AgentOperationRejectedException(AgentOperationFailure::UNSUPPORTED_VERSION);
        }

        return json_encode([
            'rotate',
            $this->agentId->toString(),
            $this->expectedCredentialId->toString(),
            $this->expectedCredentialRevision,
            $this->destination->getId()->toString(),
            $this->destination->getRevision()
        ], JSON_THROW_ON_ERROR);
    }
}
