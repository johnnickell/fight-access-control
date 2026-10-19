<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\Service;

use Fight\Common\Application\Repository\TransactionalUnitOfWork;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use RuntimeException;
use SensitiveParameter;

final class UncertainActivationUnitOfWork implements TransactionalUnitOfWork
{
    private int $calls = 0;

    public function __construct(
        private readonly InMemoryUnitOfWork $inner,
        private readonly int $uncertainAt,
        private readonly bool $committed
    ) {
    }

    public function commitTransactional(#[SensitiveParameter] callable $operation): mixed
    {
        ++$this->calls;
        $result = $this->inner->commitTransactional(function () use ($operation): mixed {
            $result = $operation();
            if ($this->calls === $this->uncertainAt && !$this->committed) {
                throw new RuntimeException('Uncertain transaction result.');
            }

            return $result;
        });
        if ($this->calls === $this->uncertainAt) {
            throw new RuntimeException('Uncertain transaction result.');
        }

        return $result;
    }

    public function isClosed(): bool
    {
        return false;
    }
}
