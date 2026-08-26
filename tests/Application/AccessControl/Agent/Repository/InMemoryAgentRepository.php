<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Repository;

use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
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

    public function getById(AgentId $id): ?Agent
    {
        foreach ($this->agents as $agent) {
            if ($agent->getId()->equals($id)) {
                return $agent;
            }
        }

        return null;
    }

    public function replace(Agent $expected, Agent $replacement): bool
    {
        foreach ($this->agents as $index => $agent) {
            if ($agent !== $expected || !$replacement->getId()->equals($expected->getId())) {
                continue;
            }

            $this->agents[$index] = $replacement;
            $this->unitOfWork?->onRollback(function () use ($expected, $index, $replacement): void {
                if (($this->agents[$index] ?? null) === $replacement) {
                    $this->agents[$index] = $expected;
                }
            });

            return true;
        }

        return false;
    }

    /** @return list<Agent> */
    public function all(): array
    {
        return $this->agents;
    }
}
