<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User\Repository;

use Fight\AccessControl\Domain\AccessControl\User\ActivationDeliveryWork;
use Fight\AccessControl\Domain\AccessControl\User\ActivationDeliveryWorkRepository;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;

final class InMemoryActivationDeliveryWorkRepository implements ActivationDeliveryWorkRepository
{
    /** @var list<ActivationDeliveryWork> */
    private array $work = [];

    public function __construct(private readonly ?InMemoryUnitOfWork $unitOfWork = null)
    {
    }

    public function add(ActivationDeliveryWork $work): void
    {
        $this->work[] = $work;
        $this->unitOfWork?->onRollback(function (): void {
            array_pop($this->work);
        });
    }

    /** @return list<ActivationDeliveryWork> */
    public function all(): array
    {
        return $this->work;
    }
}
