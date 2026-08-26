<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent;

use Exception;

/**
 * Persists Agent authority aggregates.
 */
interface AgentRepository
{
    /**
     * Adds one newly provisioned Agent.
     *
     * @throws Exception When an error occurs
     */
    public function add(Agent $agent): void;
}
