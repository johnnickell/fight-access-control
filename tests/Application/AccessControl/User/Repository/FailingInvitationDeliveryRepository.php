<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User\Repository;

use Fight\AccessControl\Domain\AccessControl\User\InvitationDelivery;
use Fight\AccessControl\Domain\AccessControl\User\InvitationDeliveryRepository;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use RuntimeException;

final readonly class FailingInvitationDeliveryRepository implements InvitationDeliveryRepository
{
    public function __construct(private InvitationDelivery $work)
    {
    }

    public function add(InvitationDelivery $work): void
    {
        throw new RuntimeException('The replacement delivery could not be stored.');
    }

    public function getByUserId(UserId $userId): ?InvitationDelivery
    {
        if ($this->work->userId()->equals($userId)) {
            return $this->work;
        }

        return null;
    }
}
