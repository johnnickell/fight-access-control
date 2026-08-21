<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\User;

use DateTimeImmutable;

/**
 * Represents bounded encrypted password-reset delivery work.
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
        private readonly string $email,
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
        string $email,
        string $ciphertext,
        DateTimeImmutable $expiresAt
    ): static {
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
     * Returns the target user identifier.
     */
    public function getUserId(): UserId
    {
        return $this->userId;
    }

    /**
     * Returns the canonical destination email.
     */
    public function getEmail(): string
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
     * Confirms successful delivery and destroys recoverable credential material.
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
     * Returns a terminal replacement with recoverable credential material destroyed.
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
     * Returns the terminal expiry shared with the reset grant.
     */
    public function getExpiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }
}
