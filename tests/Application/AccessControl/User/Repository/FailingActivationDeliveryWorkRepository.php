<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User\Repository;

use Fight\AccessControl\Domain\AccessControl\User\ActivationDeliveryWork;
use Fight\AccessControl\Domain\AccessControl\User\ActivationDeliveryWorkRepository;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use RuntimeException;

final readonly class FailingActivationDeliveryWorkRepository implements ActivationDeliveryWorkRepository
{
    public function __construct(private ActivationDeliveryWork $work)
    {
    }

    public function add(ActivationDeliveryWork $work): void
    {
        throw new RuntimeException('The replacement delivery could not be stored.');
    }

    public function getByUserId(UserId $userId): ?ActivationDeliveryWork
    {
        if ($this->work->userId()->equals($userId)) {
            return $this->work;
        }

        return null;
    }
}
