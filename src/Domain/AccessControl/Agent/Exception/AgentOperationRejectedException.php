<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent\Exception;

use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationFailure;
use RuntimeException;

/**
 * Class AgentOperationRejectedException
 *
 * Reports a sanitized rejection without chaining provider exceptions.
 */
final class AgentOperationRejectedException extends RuntimeException
{
    /**
     * Constructs AgentOperationRejectedException
     */
    public function __construct(private readonly AgentOperationFailure $reason)
    {
        parent::__construct('Agent operation rejected: '.$reason->value.'.');
    }

    /**
     * Returns the safe rejection classification
     */
    public function getReason(): AgentOperationFailure
    {
        return $this->reason;
    }

    /**
     * Returns whether the original request can be retried after transient contention or unavailability
     */
    public function isRetryable(): bool
    {
        return in_array($this->reason, [
            AgentOperationFailure::CAPACITY,
            AgentOperationFailure::CONTENTION,
            AgentOperationFailure::UNAVAILABLE
        ], true);
    }
}
