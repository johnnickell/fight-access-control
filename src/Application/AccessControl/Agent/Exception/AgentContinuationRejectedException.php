<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\Exception;

use RuntimeException;

/**
 * Class AgentContinuationRejectedException
 *
 * Supplies one neutral pre-admission outcome without private causes or policy explanations.
 */
final class AgentContinuationRejectedException extends RuntimeException
{
    /**
     * Constructs AgentContinuationRejectedException
     */
    public function __construct()
    {
        parent::__construct('Agent continuation unavailable.');
    }
}
