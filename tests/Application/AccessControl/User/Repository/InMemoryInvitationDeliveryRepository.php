<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User\Repository;

use Fight\AccessControl\Domain\AccessControl\User\InvitationDelivery;
use Fight\AccessControl\Domain\AccessControl\User\InvitationDeliveryRepository;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;

final class InMemoryInvitationDeliveryRepository implements InvitationDeliveryRepository
{
    /** @var list<InvitationDelivery> */
    private array $work = [];

    public function __construct(private readonly ?InMemoryUnitOfWork $unitOfWork = null)
    {
    }

    public function add(InvitationDelivery $work): void
    {
        $this->work[] = $work;
        $this->unitOfWork?->onRollback(function (): void {
            array_pop($this->work);
        });
    }

    public function getByUserId(UserId $userId): ?InvitationDelivery
    {
        foreach ($this->work as $work) {
            if ($work->userId()->equals($userId)) {
                return $work;
            }
        }

        return null;
    }

    /** @return list<InvitationDelivery> */
    public function all(): array
    {
        return $this->work;
    }
}
