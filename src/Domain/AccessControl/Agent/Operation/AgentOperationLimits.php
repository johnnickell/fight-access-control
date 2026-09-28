<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent\Operation;

use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentOperationRejectedException;

/**
 * Class AgentOperationLimits
 *
 * Bounds new work without gating retained-operation resolution or recovery.
 */
final readonly class AgentOperationLimits
{
    /**
     * Constructs AgentOperationLimits
     */
    public function __construct(
        private int $nameBytes = 512,
        private int $pendingPerScope = 100,
        private int $pendingTotal = 10000
    ) {
        if (
            $nameBytes < 128 || $nameBytes > 4096
            || $pendingPerScope < 1 || $pendingPerScope > 10000
            || $pendingTotal < $pendingPerScope || $pendingTotal > 1000000
        ) {
            throw new AgentOperationRejectedException(AgentOperationFailure::INVALID_REQUEST);
        }
    }

    /**
     * Validates the input bound for an unseen operation only
     */
    public function validateNewRequest(AgentProvisioningRequest $request): void
    {
        if (strlen($request->getName()) > $this->nameBytes) {
            throw new AgentOperationRejectedException(AgentOperationFailure::INVALID_REQUEST);
        }
    }

    /**
     * Validates authoritative pending counts while the repository holds admission fences
     */
    public function validateCapacity(int $pendingForScope, int $pendingTotal): void
    {
        if ($pendingForScope >= $this->pendingPerScope || $pendingTotal >= $this->pendingTotal) {
            throw new AgentOperationRejectedException(AgentOperationFailure::CAPACITY);
        }
    }
}
