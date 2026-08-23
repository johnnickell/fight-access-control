<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\ActivationGrant;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Exception\ActivationDeliveryException;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Exception\ActivationDeliveryNotRetryableException;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Value\Internet\EmailAddress;

/**
 * Represents bounded encrypted delivery work owned by an activation grant.
 *
 * @phpstan-consistent-constructor
 */
class ActivationDelivery
{
    /**
     * Creates activation delivery state.
     */
    protected function __construct(
        private readonly ActivationDeliveryId $id,
        private readonly UserId $userId,
        private readonly EmailAddress $email,
        private readonly ?string $ciphertext,
        private readonly DateTimeImmutable $expiresAt,
        private readonly ActivationDeliveryStatus $status = ActivationDeliveryStatus::PENDING
    ) {
    }

    /**
     * Creates pending encrypted activation delivery work.
     */
    public static function create(
        ActivationDeliveryId $id,
        UserId $userId,
        EmailAddress $email,
        string $ciphertext,
        DateTimeImmutable $expiresAt
    ): static {
        if ($ciphertext === '') {
            throw new ActivationDeliveryException('The activation delivery ciphertext must not be empty.');
        }

        return new static($id, $userId, $email, $ciphertext, $expiresAt);
    }

    /**
     * Returns the delivery-generation identifier.
     */
    public function getId(): ActivationDeliveryId
    {
        return $this->id;
    }

    /**
     * Returns the owning user identifier.
     */
    public function getUserId(): UserId
    {
        return $this->userId;
    }

    /**
     * Returns the canonical destination email.
     */
    public function getEmail(): EmailAddress
    {
        return $this->email;
    }

    /**
     * Returns the encrypted credential payload.
     */
    public function getCiphertext(): ?string
    {
        return $this->ciphertext;
    }

    /**
     * Confirms delivery and destroys recoverable credential material.
     */
    public function confirm(): self
    {
        if ($this->status !== ActivationDeliveryStatus::CLAIMED || $this->ciphertext === null) {
            throw new ActivationDeliveryNotRetryableException('The activation delivery work is no longer retryable.');
        }

        return new static(
            $this->id,
            $this->userId,
            $this->email,
            null,
            $this->expiresAt,
            ActivationDeliveryStatus::CONFIRMED
        );
    }

    /**
     * Claims pending work before its transport invocation.
     */
    public function claim(): self
    {
        if ($this->status !== ActivationDeliveryStatus::PENDING || $this->ciphertext === null) {
            throw new ActivationDeliveryNotRetryableException('The activation delivery work is no longer retryable.');
        }

        return new static(
            $this->id,
            $this->userId,
            $this->email,
            $this->ciphertext,
            $this->expiresAt,
            ActivationDeliveryStatus::CLAIMED
        );
    }

    /**
     * Records a failed invocation while retaining recoverable material.
     */
    public function fail(): self
    {
        if ($this->status !== ActivationDeliveryStatus::CLAIMED || $this->ciphertext === null) {
            throw new ActivationDeliveryNotRetryableException('The activation delivery work is no longer retryable.');
        }

        return new static(
            $this->id,
            $this->userId,
            $this->email,
            $this->ciphertext,
            $this->expiresAt,
            ActivationDeliveryStatus::FAILED
        );
    }

    /**
     * Returns failed work to pending so one later invocation may claim it.
     */
    public function requestRetry(): self
    {
        if ($this->status !== ActivationDeliveryStatus::FAILED || $this->ciphertext === null) {
            throw new ActivationDeliveryNotRetryableException('The activation delivery work is no longer retryable.');
        }

        return new static(
            $this->id,
            $this->userId,
            $this->email,
            $this->ciphertext,
            $this->expiresAt,
            ActivationDeliveryStatus::PENDING
        );
    }

    /**
     * Expires delivery at its terminal boundary.
     */
    public function expireAt(DateTimeImmutable $occurredAt): self
    {
        if (
            $this->ciphertext === null
            || in_array(
                $this->status,
                [ActivationDeliveryStatus::CONFIRMED, ActivationDeliveryStatus::EXPIRED],
                true
            )
            || $occurredAt < $this->expiresAt
        ) {
            return $this;
        }

        return new static(
            $this->id,
            $this->userId,
            $this->email,
            null,
            $this->expiresAt,
            ActivationDeliveryStatus::EXPIRED
        );
    }

    /**
     * Destroys recoverable credential material.
     */
    public function invalidate(): self
    {
        if ($this->ciphertext === null) {
            return $this;
        }

        return new static(
            $this->id,
            $this->userId,
            $this->email,
            null,
            $this->expiresAt,
            $this->status
        );
    }

    /**
     * Returns the terminal delivery expiry.
     */
    public function getExpiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    /**
     * Returns the safe operational status.
     */
    public function getStatus(): ActivationDeliveryStatus
    {
        return $this->status;
    }

    /**
     * Returns whether delivery may be invoked again.
     */
    public function isRetryable(): bool
    {
        return in_array(
            $this->status,
            [ActivationDeliveryStatus::PENDING, ActivationDeliveryStatus::FAILED],
            true
        ) && $this->ciphertext !== null;
    }
}
