<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent\Operation;

use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentOperationRejectedException;

/**
 * Class AgentCredentialDestination
 *
 * Binds delivery to a registered slot revision, never a caller-supplied URL or path.
 */
final readonly class AgentCredentialDestination
{
    /**
     * Constructs AgentCredentialDestination
     */
    public function __construct(private AgentDestinationId $id, private int $revision)
    {
        if ($revision < 1) {
            throw new AgentOperationRejectedException(AgentOperationFailure::INVALID_REQUEST);
        }
    }

    /**
     * Returns the permanent slot identity
     */
    public function getId(): AgentDestinationId
    {
        return $this->id;
    }

    /**
     * Returns the immutable ownership binding revision
     */
    public function getRevision(): int
    {
        return $this->revision;
    }
}
