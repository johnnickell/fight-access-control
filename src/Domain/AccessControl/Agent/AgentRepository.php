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
     * Retrieves an Agent by its stable identifier.
     *
     * @throws Exception When an error occurs
     */
    public function getById(AgentId $id): ?Agent;

    /**
     * Atomically replaces the current Agent authority with its successor.
     *
     * Returns false when the expected predecessor has already lost authority or the replacement changes identity.
     *
     * @throws Exception When an error occurs
     */
    public function replace(Agent $expected, Agent $replacement): bool;

    /**
     * Adds one newly provisioned Agent.
     *
     * @throws Exception When an error occurs
     */
    public function add(Agent $agent): void;
}
