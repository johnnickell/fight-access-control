<?php

declare(strict_types=1);

namespace Fight\AccessControl\Adapter\Mcp\Subscription\Agent;

use Fight\AccessControl\Application\AccessControl\Agent\Notice\AgentNoticeAuthority;
use Fight\AccessControl\Application\AccessControl\Agent\Task\AgentTaskDisclosurePolicy;
use Fight\Common\Application\Mcp\Subscription\McpSubscriptionAdmission;
use Fight\Common\Domain\Mcp\Notice\Notice;
use Fight\Common\Domain\Mcp\Task\TaskId;

/**
 * Class AgentTaskSubscriptionAdmission
 *
 * Adds independent current Task signal eligibility without sending full state through generic notice policy.
 */
final readonly class AgentTaskSubscriptionAdmission implements McpSubscriptionAdmission
{
    /**
     * Constructs AgentTaskSubscriptionAdmission
     *
     * Authority requires listening and independent Task signal Permissions from the same original trusted binding.
     * Generic admission is optional and must bind the identical subscriber; it never grants Task disclosure.
     */
    public function __construct(
        private string $subscriberReference,
        private AgentNoticeAuthority $authority,
        private AgentTaskDisclosurePolicy $policy,
        private ?McpSubscriptionAdmission $genericAdmission = null
    ) {
        Notice::reference($subscriberReference);
    }

    /**
     * @inheritDoc
     */
    public function subscriber(): string
    {
        return $this->subscriberReference;
    }

    /**
     * @inheritDoc
     */
    public function allows(string $subscriber, string $filter, ?string $uri, ?Notice $notice): bool
    {
        if ($subscriber !== $this->subscriberReference) {
            return false;
        }

        if ($filter !== 'taskIds') {
            return $this->genericAdmission !== null && $this->genericAdmission->subscriber() === $subscriber
                && $this->genericAdmission->allows($subscriber, $filter, $uri, $notice);
        }

        if ($uri === null || preg_match('/^[0-9a-f]{32}$/D', $uri) !== 1 || $notice !== null) {
            return false;
        }

        $principal = $this->authority->resolve();

        return $principal !== null && $this->policy->allowsSignal($principal, $subscriber, TaskId::fromString($uri));
    }
}
