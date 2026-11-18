<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\Task;

use Fight\AccessControl\Application\AccessControl\Agent\Continuation\AgentContinuationScope;
use Fight\AccessControl\Application\AccessControl\Agent\Exception\AgentContinuationRejectedException;
use Fight\AccessControl\Domain\AccessControl\Agent\AuthenticatedAgentPrincipal;
use SensitiveParameter;

/**
 * Class AgentTaskContinuationScope
 *
 * Retains the guard's fresh principal for one bounded Task action, not a caller-supplied authority factory.
 */
final class AgentTaskContinuationScope implements AgentContinuationScope
{
    private ?AuthenticatedAgentPrincipal $principal = null;

    /**
     * Constructs AgentTaskContinuationScope
     */
    public function __construct(#[SensitiveParameter] private readonly AgentContinuationScope $scope)
    {
    }

    /**
     * Returns the exact consumer scope whose writers must participate in the enclosing fence
     */
    public function getScope(): AgentContinuationScope
    {
        return $this->scope;
    }

    /**
     * @inheritDoc
     */
    public function isAllowed(#[SensitiveParameter] AuthenticatedAgentPrincipal $principal): bool
    {
        $this->principal = null;
        if (!$this->scope->isAllowed($principal)) {
            return false;
        }

        $this->principal = $principal;

        return true;
    }

    /**
     * Returns the fresh principal observed by the guard rather than copied protocol claims
     */
    public function principal(): AuthenticatedAgentPrincipal
    {
        if ($this->principal === null) {
            throw new AgentContinuationRejectedException();
        }

        return $this->principal;
    }
}
