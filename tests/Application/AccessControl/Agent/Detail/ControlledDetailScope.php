<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Detail;

use Fight\AccessControl\Application\AccessControl\Agent\Detail\AgentDetailScope;
use Fight\AccessControl\Domain\AccessControl\Agent\AuthenticatedAgentPrincipal;
use Fight\Common\Application\Mcp\Detail\Service\DetailAccess;
use Fight\Common\Domain\Mcp\Detail\DetailSelection;
use Throwable;

final class ControlledDetailScope implements AgentDetailScope
{
    public int $calls = 0;

    public ?Throwable $failure = null;

    public ?AuthenticatedAgentPrincipal $principal = null;

    public function __construct(public DetailAccess $access)
    {
    }

    public function currentAccess(AuthenticatedAgentPrincipal $principal, DetailSelection $selection): DetailAccess
    {
        ++$this->calls;
        $this->principal = $principal;
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->access;
    }
}
