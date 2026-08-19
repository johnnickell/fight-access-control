<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User\CommandHandler;

use Fight\AccessControl\Application\AccessControl\User\ActivationDeliveryWork;
use Fight\AccessControl\Application\AccessControl\User\ActivationDeliveryWorkStore;

final class InMemoryActivationDeliveryWorkStore implements ActivationDeliveryWorkStore
{
    /** @var list<ActivationDeliveryWork> */
    private array $work = [];

    public function __construct(private readonly ?InMemoryUnitOfWork $unitOfWork = null)
    {
    }

    public function save(ActivationDeliveryWork $work): void
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
