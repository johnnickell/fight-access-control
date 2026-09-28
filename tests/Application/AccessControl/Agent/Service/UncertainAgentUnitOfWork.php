<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Service;

use Fight\Common\Application\Repository\TransactionalUnitOfWork;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use RuntimeException;

final readonly class UncertainAgentUnitOfWork implements TransactionalUnitOfWork
{
    public function __construct(private InMemoryUnitOfWork $inner, private bool $committed)
    {
    }

    public function commitTransactional(callable $operation): mixed
    {
        $this->inner->commitTransactional(function () use ($operation): mixed {
            $result = $operation();
            if (!$this->committed) {
                throw new RuntimeException('Unsafe database connection detail: rolled back.');
            }

            return $result;
        });

        throw new RuntimeException('Unsafe database connection detail: commit acknowledgement lost.');
    }

    public function isClosed(): bool
    {
        return false;
    }
}
