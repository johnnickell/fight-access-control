<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\User;

use DateTimeImmutable;

/**
 * Represents encrypted activation delivery work awaiting execution or destruction at terminal expiry.
 *
 * @phpstan-consistent-constructor
 */
class ActivationDeliveryWork
{
    /**
     * Creates pending encrypted delivery work.
     */
    protected function __construct(
        private readonly UserId $userId,
        private readonly string $email,
        private readonly string $ciphertext,
        private readonly DateTimeImmutable $expiresAt
    ) {
    }

    /**
     * Creates encrypted activation delivery work.
     */
    public static function create(
        UserId $userId,
        string $email,
        string $ciphertext,
        DateTimeImmutable $expiresAt
    ): static {
        return new static($userId, $email, $ciphertext, $expiresAt);
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
