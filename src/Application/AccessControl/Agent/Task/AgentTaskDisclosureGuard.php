<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\Task;

use Closure;
use Fight\AccessControl\Application\AccessControl\Agent\Continuation\AgentContinuationBinding;
use Fight\AccessControl\Application\AccessControl\Agent\Continuation\AgentContinuationGuard;
use Fight\AccessControl\Application\AccessControl\Agent\Exception\AgentContinuationRejectedException;
use Fight\Common\Application\Mcp\Subscription\McpTaskDisclosureGuard;
use Fight\Common\Domain\Mcp\Notice\Notice;
use SensitiveParameter;

/**
 * Class AgentTaskDisclosureGuard
 *
 * Composes Common's required synchronous action with existing original-bound current continuation admission.
 * The consumer supplies the qualified composite fence; this adapter adds no production writer synchronization.
 */
final readonly class AgentTaskDisclosureGuard implements McpTaskDisclosureGuard
{
    /**
     * Constructs AgentTaskDisclosureGuard
     *
     * Requirements independently include listening, Task signal and complete-state disclosure Permissions.
     *
     * @phpstan-param array<array-key, mixed> $requirements
     */
    public function __construct(
        private string $subscriber,
        private AgentContinuationGuard $guard,
        #[SensitiveParameter] private AgentContinuationBinding $binding,
        private array $requirements,
        private AgentTaskDisclosurePolicy $policy
    ) {
        Notice::reference($subscriber);
    }

    /**
     * @inheritDoc
     */
    public function run(string $subscriber, array $states, Closure $disclose): void
    {
        if ($subscriber !== $this->subscriber) {
            throw new AgentContinuationRejectedException();
        }

        $this->guard->run(
            $this->binding,
            $this->requirements,
            new AgentTaskDisclosureScope($subscriber, $states, $this->policy),
            $disclose
        );
    }
}
