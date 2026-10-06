<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User;

use Fiber;
use Fight\Common\Application\Repository\TransactionalUnitOfWork;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryAuthorizationReferenceState;
use RuntimeException;
use SensitiveParameter;
use Throwable;

final class InMemoryUnitOfWork implements TransactionalUnitOfWork
{
    public int $transactions = 0;

    public bool $transactionCompleted = false;

    public bool $transactionActive = false;

    public bool $failNextCommit = false;

    private ?InMemoryAuthorizationReferenceState $authorizationReferenceState = null;

    private int $rollbackStart = 0;

    /** @var list<callable(): void> */
    private array $rollbackActions = [];

    /** @var list<callable(): void> */
    private array $completionActions = [];

    public function __construct(private readonly ?int $failOnTransaction = null)
    {
    }

    public function commitTransactional(#[SensitiveParameter] callable $operation): mixed
    {
        if ($this->transactionActive) {
            throw new RuntimeException('Nested package transactions are unsupported.');
        }

        ++$this->transactions;
        $this->transactionActive = true;
        $rollbackStart = count($this->rollbackActions);
        $this->rollbackStart = $rollbackStart;

        $completionStart = count($this->completionActions);

        try {
            $result = $operation();
            if ($this->transactions === $this->failOnTransaction || $this->failNextCommit) {
                $this->failNextCommit = false;

                throw new RuntimeException('Injected transaction failure.');
            }

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
     * Suspends a read-only transaction so another modeled connection can commit
     *
     * Only pre-write interleavings are supported: a paused transaction must not expose
     * uncommitted state or register rollback actions that could undo another winner.
     */
    public function suspendBeforeFirstWrite(): void
    {
        if (!$this->transactionActive || count($this->rollbackActions) !== $this->rollbackStart) {
            throw new RuntimeException('Only a transaction without writes can be suspended.');
        }

        $rollbackStart = $this->rollbackStart;
        $completed = $this->transactionCompleted;
        $this->transactionActive = false;
        try {
            Fiber::suspend();
        } finally {
            $this->transactionActive = true;
            $this->transactionCompleted = $completed;
            $this->rollbackStart = $rollbackStart;
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

    public function authorizationReferenceState(): InMemoryAuthorizationReferenceState
    {
        return $this->authorizationReferenceState ??= new InMemoryAuthorizationReferenceState($this);
    }
}
