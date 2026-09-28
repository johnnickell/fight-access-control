<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent\Exception;

use Fight\AccessControl\Domain\AccessControl\Agent\Delivery\AgentDeliveryFailure;
use RuntimeException;

/**
 * Class AgentDeliveryFailedException
 *
 * Carries a closed failure classification without arbitrary provider diagnostics or chained exceptions.
 */
final class AgentDeliveryFailedException extends RuntimeException
{
    /**
     * Constructs AgentDeliveryFailedException
     */
    public function __construct(private readonly AgentDeliveryFailure $reason)
    {
        parent::__construct('Agent delivery failed: '.$reason->value.'.');
    }

    /**
     * Returns the safe classification
     */
    public function getReason(): AgentDeliveryFailure
    {
        return $this->reason;
    }
}
