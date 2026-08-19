<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\Invitation;

use Fight\AccessControl\Application\Invitation\ActivationDeliveryWork;
use Fight\AccessControl\Application\Invitation\ActivationDeliveryWorkStore;

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
