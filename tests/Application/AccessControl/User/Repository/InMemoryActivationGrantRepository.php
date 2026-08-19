<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User\Repository;

use Fight\AccessControl\Domain\AccessControl\User\ActivationGrant;
use Fight\AccessControl\Domain\AccessControl\User\ActivationGrantRepository;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;

final class InMemoryActivationGrantRepository implements ActivationGrantRepository
{
    /** @var list<ActivationGrant> */
    private array $grants = [];

    public function __construct(private readonly ?InMemoryUnitOfWork $unitOfWork = null)
    {
    }

    public function add(ActivationGrant $grant): void
    {
        $this->grants[] = $grant;
        $this->unitOfWork?->onRollback(function (): void {
            array_pop($this->grants);
        });
    }

    /** @return list<ActivationGrant> */
    public function all(): array
    {
        return $this->grants;
    }
}
