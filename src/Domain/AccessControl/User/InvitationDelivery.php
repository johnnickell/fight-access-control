<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\User;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\User\Exception\InvitationDeliveryNotRetryableException;

/**
 * Represents encrypted activation delivery work awaiting execution or destruction at terminal expiry.
 *
 * @phpstan-consistent-constructor
 */
class InvitationDelivery
{
    /**
     * Creates pending encrypted delivery work.
     */
    protected function __construct(
        private readonly UserId $userId,
        private readonly string $email,
        private ?string $ciphertext,
        private readonly DateTimeImmutable $expiresAt,
        private InvitationDeliveryStatus $status = InvitationDeliveryStatus::PENDING
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
    public function ciphertext(): ?string
    {
        return $this->ciphertext;
    }

    /**
     * Confirms successful delivery and destroys the recoverable credential.
     */
    public function confirm(): void
    {
        if ($this->status === InvitationDeliveryStatus::CONFIRMED) {
            return;
        }

        if ($this->isRetryable() === false) {
            throw new InvitationDeliveryNotRetryableException('The activation delivery work is no longer retryable.');
        }

        $this->ciphertext = null;
        $this->status = InvitationDeliveryStatus::CONFIRMED;
    }

    /**
     * Records an unsuccessful invocation while retaining the recoverable credential for a later retry.
     */
    public function fail(): void
    {
        if ($this->status === InvitationDeliveryStatus::FAILED) {
            return;
        }

        if ($this->isRetryable() === false) {
            throw new InvitationDeliveryNotRetryableException('The activation delivery work is no longer retryable.');
        }

        $this->status = InvitationDeliveryStatus::FAILED;
    }

    /**
     * Expires pending work at its terminal expiry and destroys the recoverable credential.
     */
    public function expireAt(DateTimeImmutable $occurredAt): void
    {
        if ($this->isRetryable() === false || $occurredAt < $this->expiresAt) {
            return;
        }

        $this->ciphertext = null;
        $this->status = InvitationDeliveryStatus::EXPIRED;
    }

    /**
     * Returns the expiry shared with the activation grant that this work delivers.
     */
    public function expiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    /**
     * Returns the operational status without exposing the recoverable credential.
     */
    public function getStatus(): InvitationDeliveryStatus
    {
        return $this->status;
    }

    /**
     * Returns whether the delivery work retains credential material that may be invoked again.
     */
    public function isRetryable(): bool
    {
        return in_array($this->status, [InvitationDeliveryStatus::PENDING, InvitationDeliveryStatus::FAILED], true)
            && $this->ciphertext !== null;
    }
}
