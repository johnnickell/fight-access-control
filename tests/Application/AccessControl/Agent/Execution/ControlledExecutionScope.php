<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Execution;

use Closure;
use Fight\AccessControl\Application\AccessControl\Agent\Execution\AgentExecutionScope;
use Fight\AccessControl\Domain\AccessControl\Agent\AuthenticatedAgentPrincipal;
use Fight\Common\Application\Mcp\Session\Service\ExecutionAccess;
use Fight\Common\Domain\Mcp\Session\ExecutionSelection;
use RuntimeException;

final class ControlledExecutionScope implements AgentExecutionScope
{
    public int $calls = 0;

    public bool $fail = false;

    public ?Closure $beforeReturn = null;

    public function __construct(public ExecutionAccess $access)
    {
    }

    public function currentAccess(
        AuthenticatedAgentPrincipal $principal,
        ExecutionSelection $selection
    ): ExecutionAccess {
        ++$this->calls;
        if ($this->fail) {
            throw new RuntimeException('PRIVATE-POLICY-SENTINEL');
        }

        if ($this->beforeReturn !== null) {
            ($this->beforeReturn)();
        }

        return $this->access;
    }
}
