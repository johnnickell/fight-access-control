<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent\Operation;

use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentOperationRejectedException;

/**
 * Class AgentProvisioningRequest
 *
 * Retains bounded input for canonical binding and new-work admission.
 */
final readonly class AgentProvisioningRequest
{
    /**
     * Constructs AgentProvisioningRequest
     */
    public function __construct(private string $name, private AgentCredentialDestination $destination)
    {
        if (strlen($name) > 4096 || !mb_check_encoding($name, 'UTF-8')) {
            throw new AgentOperationRejectedException(AgentOperationFailure::INVALID_REQUEST);
        }
    }

    /**
     * Returns the bounded original name representation
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Returns the registered destination binding
     */
    public function getDestination(): AgentCredentialDestination
    {
        return $this->destination;
    }

    /**
     * Returns the package-owned canonical request
     */
    public function canonicalize(): string
    {
        return json_encode([
            'provision',
            AgentOperationCanonicalization::name($this->name),
            $this->destination->getId()->toString(),
            $this->destination->getRevision()
        ], JSON_THROW_ON_ERROR);
    }
}
