<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Repository;

use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentRepository;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;

final class InMemoryAgentRepository implements AgentRepository
{
    /** @var list<Agent> */
    private array $agents = [];

    public function __construct(private readonly ?InMemoryUnitOfWork $unitOfWork = null)
    {
    }

    public function add(Agent $agent): void
    {
        $this->agents[] = $agent;
        $this->unitOfWork?->onRollback(function (): void {
            array_pop($this->agents);
        });
    }

    /** @return list<Agent> */
    public function all(): array
    {
        return $this->agents;
    }
}
