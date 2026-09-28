<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent\Maintenance;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentOperationRejectedException;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationFailure;

/**
 * Class AgentMaintenancePolicy
 *
 * Bounds one maintenance page and preserves a finite inert-entry recovery window.
 */
final readonly class AgentMaintenancePolicy
{
    /**
     * Constructs AgentMaintenancePolicy
     */
    public function __construct(private int $batchSize = 50, private int $cleanupGraceSeconds = 86400)
    {
        if ($batchSize < 1 || $batchSize > 100 || $cleanupGraceSeconds < 1 || $cleanupGraceSeconds > 604800) {
            throw new AgentOperationRejectedException(AgentOperationFailure::INVALID_REQUEST);
        }
    }

    /**
     * Returns the maximum work in one page without an implicit unbounded loop
     */
    public function getBatchSize(): int
    {
        return $this->batchSize;
    }

    /**
     * Returns the inert-entry cleanup boundary after original delivery retention
     */
    public function cleanupAfter(DateTimeImmutable $retentionEnd): DateTimeImmutable
    {
        return $retentionEnd->modify('+'.$this->cleanupGraceSeconds.' seconds');
    }

    /**
     * Returns validated settings for durable scheduler configuration
     *
     * @return array{batch_size: int, cleanup_grace_seconds: int}
     */
    public function toArray(): array
    {
        return ['batch_size' => $this->batchSize, 'cleanup_grace_seconds' => $this->cleanupGraceSeconds];
    }
}
