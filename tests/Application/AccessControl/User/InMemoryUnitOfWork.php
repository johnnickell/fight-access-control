<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User;

use Fight\Common\Application\Repository\UnitOfWork;
use Throwable;

final class InMemoryUnitOfWork implements UnitOfWork
{
    public int $transactions = 0;

    public bool $transactionCompleted = false;

    public bool $transactionActive = false;

    /** @var list<callable(): void> */
    private array $rollbackActions = [];

    /** @var list<callable(): void> */
    private array $completionActions = [];

    public function commit(): void
    {
    }

    public function commitTransactional(callable $operation): mixed
    {
        ++$this->transactions;
        $this->transactionActive = true;
        $rollbackStart = count($this->rollbackActions);
        $completionStart = count($this->completionActions);

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
            for ($index = count($this->completionActions) - 1; $index >= $completionStart; --$index) {
                ($this->completionActions[$index])();
            }

            $this->transactionActive = false;
            array_splice($this->rollbackActions, $rollbackStart);
            array_splice($this->completionActions, $completionStart);
        }
    }

    /**
     * Registers an in-memory durable write to undo if its transaction fails.
     */
    public function onRollback(callable $action): void
    {
        $this->rollbackActions[] = $action;
    }

    /**
     * Registers an action to run after the current transaction commits or rolls back.
     */
    public function onCompletion(callable $action): void
    {
        $this->completionActions[] = $action;
    }

    public function isClosed(): bool
    {
        return false;
    }
}
