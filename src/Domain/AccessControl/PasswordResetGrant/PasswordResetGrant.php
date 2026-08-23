<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\PasswordResetGrant;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use LogicException;

/**
 * Owns one generation of password-reset authority and its delivery work.
 *
 * @phpstan-consistent-constructor
 */
class PasswordResetGrant
{
    /**
     * Creates one immutable aggregate generation.
     */
    protected function __construct(
        private readonly PasswordResetGrantId $id,
        private readonly UserId $userId,
        private readonly string $credentialHash,
        private readonly DateTimeImmutable $expiresAt,
        private readonly PasswordResetDelivery $delivery,
        private readonly ?DateTimeImmutable $consumedAt = null,
        private readonly ?DateTimeImmutable $revokedAt = null,
        private readonly int $revision = 0
    ) {
    }

    /**
     * Issues an expiring aggregate generation.
     */
    public static function issue(
        UserId $userId,
        PasswordResetCredential $credential,
        DateTimeImmutable $issuedAt,
        DateTimeImmutable $expiresAt,
        EmailAddress $email,
        string $ciphertext
    ): static {
        if ($expiresAt <= $issuedAt) {
            throw new DomainException('The password-reset grant expiry must be later than its issuance time.');
        }

        return new static(
            PasswordResetGrantId::generate(),
            $userId,
            hash('sha256', $credential->toString()),
            $expiresAt,
            PasswordResetDelivery::create(
                PasswordResetDeliveryId::generate(),
                $userId,
                $email,
                $ciphertext,
                $expiresAt
            )
        );
    }

    /**
     * Returns the aggregate-generation identifier.
     */
    public function getId(): PasswordResetGrantId
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
     * Returns the monotonic state revision used for compare-and-set persistence.
     */
    public function getRevision(): int
    {
        return $this->revision;
    }

    /**
     * Returns the one-way credential hash.
     */
    public function getCredentialHash(): string
    {
        return $this->credentialHash;
    }

    /**
     * Compares raw credential input without retaining it.
     */
    public function matchesCredential(PasswordResetCredential $credential): bool
    {
        return hash_equals($this->credentialHash, hash('sha256', $credential->toString()));
    }

    /**
     * Returns the credential expiry.
     */
    public function getExpiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    /**
     * Returns the owned delivery entity.
     */
    public function getDelivery(): PasswordResetDelivery
    {
        return $this->delivery;
    }

    /**
     * Returns whether authority remains issued.
     */
    public function isIssued(): bool
    {
        return !$this->consumedAt instanceof DateTimeImmutable && !$this->revokedAt instanceof DateTimeImmutable;
    }

    /**
     * Returns whether authority was consumed.
     */
    public function isConsumed(): bool
    {
        return $this->consumedAt instanceof DateTimeImmutable;
    }

    /**
     * Returns when authority was consumed.
     */
    public function getConsumedAt(): ?DateTimeImmutable
    {
        return $this->consumedAt;
    }

    /**
     * Returns whether authority was revoked.
     */
    public function isRevoked(): bool
    {
        return $this->revokedAt instanceof DateTimeImmutable;
    }

    /**
     * Returns when authority was revoked.
     */
    public function getRevokedAt(): ?DateTimeImmutable
    {
        return $this->revokedAt;
    }

    /**
     * Returns whether authority can be consumed at the supplied time.
     */
    public function isUsableAt(DateTimeImmutable $at): bool
    {
        return $this->isIssued() && $at < $this->expiresAt;
    }

    /**
     * Consumes usable authority.
     */
    public function consume(DateTimeImmutable $at): self
    {
        if ($this->isUsableAt($at) === false) {
            throw new LogicException('The password-reset grant is no longer usable.');
        }

        return new static(
            $this->id,
            $this->userId,
            $this->credentialHash,
            $this->expiresAt,
            $this->delivery->invalidate(),
            $at,
            $this->revokedAt,
            $this->revision + 1
        );
    }

    /**
     * Revokes issued authority and destroys its delivery ciphertext.
     */
    public function revoke(DateTimeImmutable $at): self
    {
        if ($this->isIssued() === false) {
            throw new LogicException('The password-reset grant is no longer issued.');
        }

        return new static(
            $this->id,
            $this->userId,
            $this->credentialHash,
            $this->expiresAt,
            $this->delivery->invalidate(),
            $this->consumedAt,
            $at,
            $this->revision + 1
        );
    }

    /**
     * Confirms the owned delivery.
     */
    public function confirmDelivery(): self
    {
        return $this->withDelivery($this->delivery->confirm());
    }

    /**
     * Expires the owned delivery at its terminal boundary.
     */
    public function expireDeliveryAt(DateTimeImmutable $occurredAt): self
    {
        return $this->withDelivery($this->delivery->expireAt($occurredAt));
    }

    /**
     * Destroys recoverable delivery ciphertext.
     */
    public function invalidateDelivery(): self
    {
        return $this->withDelivery($this->delivery->invalidate());
    }

    /**
     * Returns the fixed grant purpose.
     */
    public function purpose(): string
    {
        return 'password_reset';
    }

    /**
     * Replaces only the owned delivery within this immutable generation.
     */
    private function withDelivery(PasswordResetDelivery $delivery): self
    {
        if ($delivery === $this->delivery) {
            return $this;
        }

        return new static(
            $this->id,
            $this->userId,
            $this->credentialHash,
            $this->expiresAt,
            $delivery,
            $this->consumedAt,
            $this->revokedAt,
            $this->revision + 1
        );
    }
}
