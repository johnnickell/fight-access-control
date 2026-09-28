<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent\Exception;

use RuntimeException;

/**
 * Class AgentDeliveryCommitUncertainException
 *
 * Reports that a completed delivery transaction callback did not receive commit confirmation.
 */
final class AgentDeliveryCommitUncertainException extends RuntimeException
{
    /**
     * Constructs AgentDeliveryCommitUncertainException
     */
    public function __construct()
    {
        parent::__construct('Agent delivery commit is indeterminate.');
    }
}
