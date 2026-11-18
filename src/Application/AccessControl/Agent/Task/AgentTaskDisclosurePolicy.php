<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\Task;

use Fight\AccessControl\Domain\AccessControl\Agent\AuthenticatedAgentPrincipal;
use Fight\Common\Domain\Mcp\Task\TaskId;

/**
 * Interface AgentTaskDisclosurePolicy
 *
 * Supplies current consumer policy, never authority inferred from proposed state or technical filters.
 */
interface AgentTaskDisclosurePolicy
{
    /**
     * Determines independent complete Task signal and identity eligibility
     *
     * Handle possession, catalog grants and earlier acceptance confer no grant. Throw on unverifiable policy.
     */
    public function allowsSignal(AuthenticatedAgentPrincipal $principal, string $subscriber, TaskId $id): bool;

    /**
     * Determines eligibility of the exact complete proposed state under the enclosing continuation fence
     *
     * Check current Task relationships, every protected field and the proposed revision's visibility epoch against
     * the same trusted context used by AgentTaskReadScope. Deny incompatible old projections, never redact them.
     * Every relevant policy writer must participate in the consumer's composite fence, including reentrant writers.
     *
     * @phpstan-param array<string, mixed> $state
     */
    public function allowsState(
        AuthenticatedAgentPrincipal $principal,
        string $subscriber,
        TaskId $id,
        array $state
    ): bool;
}
