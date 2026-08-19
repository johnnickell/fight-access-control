<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\User;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\User\UserId;

/**
 * Represents encrypted activation delivery work awaiting execution or destruction at terminal expiry.
 */
final readonly class ActivationDeliveryWork
{
    /**
     * Creates pending encrypted delivery work.
     */
    public function __construct(
        private UserId $userId,
        private string $email,
        private string $ciphertext,
        private DateTimeImmutable $expiresAt
    ) {
    }

    /**
     * Returns the target user identifier.
     */
    public function userId(): UserId
    {
        return $this->userId;
    }

    /**
     * Returns the target email address.
     */
    public function email(): string
    {
        return $this->email;
    }

    /**
     * Returns the encrypted credential payload.
     */
    public function ciphertext(): string
    {
        return $this->ciphertext;
    }

    /**
     * Returns the expiry shared with the activation grant that this work delivers.
     */
    public function expiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }
}
