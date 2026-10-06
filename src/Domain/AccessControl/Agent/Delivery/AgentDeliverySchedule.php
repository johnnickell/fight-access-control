<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent\Delivery;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentOperationRejectedException;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationFailure;

/**
 * Class AgentDeliverySchedule
 *
 * Bounds one scheduler pass and its next polling time independently of persisted per-delivery retry policy.
 */
final readonly class AgentDeliverySchedule
{
    /**
     * Constructs AgentDeliverySchedule
     */
    public function __construct(private int $batchSize = 50, private int $pollSeconds = 30)
    {
        if ($batchSize < 1 || $batchSize > 100 || $pollSeconds < 1 || $pollSeconds > 3600) {
            throw new AgentOperationRejectedException(AgentOperationFailure::INVALID_REQUEST);
        }
    }

    /**
     * Returns the maximum selections and exact delivery calls per scheduler pass
     */
    public function getBatchSize(): int
    {
        return $this->batchSize;
    }

    /**
     * Returns the next scheduler time without sleeping or holding a transaction
     */
    public function nextRunAt(DateTimeImmutable $now): DateTimeImmutable
    {
        return $now->modify('+'.$this->pollSeconds.' seconds');
    }
}
