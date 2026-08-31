<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\EmailChangeGrant;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Exception\EmailChangeDeliveryNotRetryableException;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Exception\EmailChangeGrantException;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Value\Internet\EmailAddress;

/**
 * Represents bounded encrypted delivery work owned by an email-change grant.
 *
 * @phpstan-consistent-constructor
 */
class EmailChangeDelivery
{
    /**
     * Creates pending email-change delivery work.
     */
    protected function __construct(
        private readonly EmailChangeDeliveryId $id,
        private readonly UserId $userId,
        private readonly EmailAddress $email,
        private readonly ?string $ciphertext,
        private readonly DateTimeImmutable $expiresAt,
        private readonly EmailChangeDeliveryStatus $status = EmailChangeDeliveryStatus::PENDING
    ) {
    }

    /**
     * Creates recoverable delivery work.
     */
    public static function create(
        EmailChangeDeliveryId $id,
        UserId $userId,
        EmailAddress $email,
        string $ciphertext,
        DateTimeImmutable $expiresAt
    ): static {
        if ($ciphertext === '') {
            throw new EmailChangeGrantException('The email-change delivery ciphertext must not be empty.');
        }

        return new static($id, $userId, $email, $ciphertext, $expiresAt);
    }

    /**
     * Returns the delivery-generation identifier.
     */
    public function getId(): EmailChangeDeliveryId
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
     * Returns the reserved destination email.
     */
    public function getEmail(): EmailAddress
    {
        return $this->email;
    }

    /**
     * Returns the encrypted confirmation credential.
     */
    public function getCiphertext(): ?string
    {
        return $this->ciphertext;
    }

    /**
     * Claims pending work before invoking its consumer-owned transport.
     */
    public function claim(): self
    {
        if (
            !in_array(
                $this->status,
                [EmailChangeDeliveryStatus::PENDING, EmailChangeDeliveryStatus::FAILED],
                true
            )
            || $this->ciphertext === null
        ) {
            throw new EmailChangeDeliveryNotRetryableException(
                'The email-change delivery work is no longer retryable.'
            );
        }

        return new static(
            $this->id,
            $this->userId,
            $this->email,
            $this->ciphertext,
            $this->expiresAt,
            EmailChangeDeliveryStatus::CLAIMED
        );
    }

    /**
     * Confirms successful invocation and destroys recoverable material.
     */
    public function confirm(): self
    {
        if ($this->status !== EmailChangeDeliveryStatus::CLAIMED || $this->ciphertext === null) {
            throw new EmailChangeDeliveryNotRetryableException(
                'The email-change delivery work is no longer retryable.'
            );
        }

        return new static(
            $this->id,
            $this->userId,
            $this->email,
            null,
            $this->expiresAt,
            EmailChangeDeliveryStatus::CONFIRMED
        );
    }

    /**
     * Records a failed invocation while retaining recoverable material.
     */
    public function fail(): self
    {
        if ($this->status !== EmailChangeDeliveryStatus::CLAIMED || $this->ciphertext === null) {
            throw new EmailChangeDeliveryNotRetryableException(
                'The email-change delivery work is no longer retryable.'
            );
        }

        return new static(
            $this->id,
            $this->userId,
            $this->email,
            $this->ciphertext,
            $this->expiresAt,
            EmailChangeDeliveryStatus::FAILED
        );
    }

    /**
     * Returns the terminal expiry shared with the grant.
     */
    public function getExpiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    /**
     * Destroys recoverable confirmation material.
     */
    public function invalidate(): self
    {
        if (!$this->isRecoverable()) {
            return $this;
        }

        return new static($this->id, $this->userId, $this->email, null, $this->expiresAt, $this->status);
    }

    /**
     * Returns whether encrypted confirmation material remains recoverable.
     */
    public function isRecoverable(): bool
    {
        return $this->ciphertext !== null;
    }

    /**
     * Returns the safe operational status.
     */
    public function getStatus(): EmailChangeDeliveryStatus
    {
        return $this->status;
    }

    /**
     * Returns whether invocation may be attempted now or after a failed transport.
     */
    public function isRetryable(): bool
    {
        return in_array(
            $this->status,
            [EmailChangeDeliveryStatus::PENDING, EmailChangeDeliveryStatus::FAILED],
            true
        ) && $this->ciphertext !== null;
    }
}
