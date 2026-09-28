<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Service;

use Closure;
use Fight\Common\Application\Repository\TransactionalUnitOfWork;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use RuntimeException;
use SensitiveParameter;

final class DeliveryUnitOfWork implements TransactionalUnitOfWork
{
    public int $commits = 0;

    public ?int $uncertainAt = null;

    public bool $persistUncertain = false;

    public ?Closure $afterCommit = null;

    public bool $closed = false;

    public function __construct(private readonly InMemoryUnitOfWork $inner)
    {
    }

    public function commitTransactional(#[SensitiveParameter] callable $operation): mixed
    {
        ++$this->commits;
        $result = $this->inner->commitTransactional(function () use ($operation): mixed {
            $result = $operation();
            if ($this->uncertainAt === $this->commits && !$this->persistUncertain) {
                throw new RuntimeException('Untrusted database diagnostic.');
            }

            return $result;
        });
        if ($this->uncertainAt === $this->commits) {
            throw new RuntimeException('Untrusted commit acknowledgement detail.');
        }

        $this->afterCommit?->__invoke($this->commits);

        return $result;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }
}
