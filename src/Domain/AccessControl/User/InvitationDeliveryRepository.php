<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\User;

use Exception;

/**
 * Interface InvitationDeliveryRepository
 */
interface InvitationDeliveryRepository
{
    /**
     * Retrieves activation delivery work for a user.
     *
     * @throws Exception When an error occurs
     */
    public function getByUserId(UserId $userId): ?InvitationDelivery;

    /**
     * Adds encrypted activation delivery work.
     *
     * @throws Exception When an error occurs
     */
    public function add(InvitationDelivery $work): void;
}
