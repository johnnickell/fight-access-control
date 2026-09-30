<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Repository;

use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentOperationRejectedException;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationContract;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationFailure;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;

/** Models authoritative cohort state and lock exclusion, not real storage or adapter qualification */
final class InMemoryAgentOperationContract
{
    public ?AgentOperationContract $current;

    public bool $unavailable = false;

    public bool $locked = false;

    public function __construct(private readonly ?InMemoryUnitOfWork $transaction = null)
    {
        $this->current = self::compatible();
    }

    public static function compatible(int $generation = 1): AgentOperationContract
    {
        return new AgentOperationContract(1, 2, 1, $generation, AgentOperationContract::REQUIRED_CAPABILITIES);
    }

    public function read(): AgentOperationContract
    {
        if ($this->unavailable || $this->current === null) {
            throw new AgentOperationRejectedException(AgentOperationFailure::UNAVAILABLE);
        }

        if ($this->transaction?->transactionActive && !$this->locked) {
            $this->locked = true;
            $this->transaction->onCompletion(function (): void {
                $this->locked = false;
            });
        }

        return $this->current;
    }

    public function switchTo(AgentOperationContract $contract): void
    {
        if ($this->locked) {
            throw new AgentOperationRejectedException(AgentOperationFailure::CONTENTION);
        }

        $this->current = $contract;
    }
}
