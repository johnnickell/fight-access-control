<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\PasswordResetGrant;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Value\Internet\EmailAddress;

/**
 * Represents bounded encrypted delivery work owned by a password-reset grant.
 *
 * @phpstan-consistent-constructor
 */
class PasswordResetDelivery
{
    /**
     * Creates pending password-reset delivery work.
     */
    protected function __construct(
        private readonly PasswordResetDeliveryId $id,
        private readonly UserId $userId,
        private readonly EmailAddress $email,
        private readonly ?string $ciphertext,
        private readonly DateTimeImmutable $expiresAt
    ) {
    }

    /**
     * Creates encrypted password-reset delivery work.
     */
    public static function create(
        PasswordResetDeliveryId $id,
        UserId $userId,
        EmailAddress $email,
        string $ciphertext,
        DateTimeImmutable $expiresAt
    ): static {
        if ($ciphertext === '') {
            throw new DomainException('The password-reset delivery ciphertext must not be empty.');
        }

        return new static($id, $userId, $email, $ciphertext, $expiresAt);
    }

    /**
     * Returns the delivery-generation identifier.
     */
    public function getId(): PasswordResetDeliveryId
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
        return $this->invalidate();
    }

    /**
     * Destroys recoverable credential material at terminal expiry.
     */
    public function expireAt(DateTimeImmutable $occurredAt): self
    {
        if ($occurredAt < $this->expiresAt) {
            return $this;
        }

        return $this->invalidate();
    }

    /**
     * Returns a terminal ciphertext-free entity.
     */
    public function invalidate(): self
    {
        if (!$this->isRecoverable()) {
            return $this;
        }

        return new self($this->id, $this->userId, $this->email, null, $this->expiresAt);
    }

    /**
     * Returns whether encrypted credential material remains recoverable.
     */
    public function isRecoverable(): bool
    {
        return $this->ciphertext !== null;
    }

    /**
     * Returns the terminal expiry shared with the owning grant.
     */
    public function getExpiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }
}
