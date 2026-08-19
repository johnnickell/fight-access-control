<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User;

use Fight\Common\Application\Repository\UnitOfWork;
use Throwable;

final class InMemoryUnitOfWork implements UnitOfWork
{
    public int $transactions = 0;

    public bool $transactionCompleted = false;

    /** @var list<callable(): void> */
    private array $rollbackActions = [];

    public function commit(): void
    {
    }

    public function commitTransactional(callable $operation): mixed
    {
        ++$this->transactions;
        $rollbackStart = count($this->rollbackActions);

        try {
            $result = $operation();
            $this->transactionCompleted = true;

            return $result;
        } catch (Throwable $throwable) {
            for ($index = count($this->rollbackActions) - 1; $index >= $rollbackStart; --$index) {
                ($this->rollbackActions[$index])();
            }

            throw $throwable;
        } finally {
            array_splice($this->rollbackActions, $rollbackStart);
        }
    }

    /**
     * Registers an in-memory durable write to undo if its transaction fails.
     */
    public function onRollback(callable $action): void
    {
        $this->rollbackActions[] = $action;
    }

    public function isClosed(): bool
    {
        return false;
    }
}
