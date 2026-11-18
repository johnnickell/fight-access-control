<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\Task;

use Fight\AccessControl\Application\AccessControl\Agent\Continuation\AgentContinuationScope;
use Fight\AccessControl\Domain\AccessControl\Agent\AuthenticatedAgentPrincipal;
use Fight\Common\Domain\Mcp\Task\TaskId;
use SensitiveParameter;

/**
 * Class AgentTaskDisclosureScope
 *
 * Associates the exact proposed complete states with one bounded guarded action, not a reusable allow.
 */
final readonly class AgentTaskDisclosureScope implements AgentContinuationScope
{
    /**
     * Constructs AgentTaskDisclosureScope
     *
     * @phpstan-param array<string, array<string, mixed>> $states
     */
    public function __construct(
        private string $subscriber,
        #[SensitiveParameter] private array $states,
        private AgentTaskDisclosurePolicy $policy
    ) {
    }

    /**
     * Returns the consumer policy whose writers participate in the enclosing composite fence
     */
    public function policy(): AgentTaskDisclosurePolicy
    {
        return $this->policy;
    }

    /**
     * @inheritDoc
     */
    public function isAllowed(#[SensitiveParameter] AuthenticatedAgentPrincipal $principal): bool
    {
        foreach ($this->states as $id => $state) {
            $taskId = TaskId::fromString($id);
            if (
                !$this->policy->allowsSignal($principal, $this->subscriber, $taskId)
                || !$this->policy->allowsState($principal, $this->subscriber, $taskId, $state)
            ) {
                return false;
            }
        }

        return true;
    }
}
