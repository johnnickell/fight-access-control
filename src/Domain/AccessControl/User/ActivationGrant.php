<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\User;

use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;

/**
 * Represents a single-use, purpose-bound activation credential.
 */
class ActivationGrant
{
    /**
     * Creates an activation grant.
     */
    protected function __construct(
        private readonly UserId $userId,
        private readonly string $credentialHash,
        private readonly DateTimeImmutable $expiresAt,
        private readonly ?DateTimeImmutable $consumedAt = null,
        private readonly ?DateTimeImmutable $revokedAt = null
    ) {
    }

    /**
     * Issues an activation grant without retaining its raw credential.
     */
    public static function issue(
        UserId $userId,
        ActivationCredential $credential,
        DateTimeImmutable $issuedAt,
        DateTimeImmutable $expiresAt
    ): self {
        if ($expiresAt <= $issuedAt) {
            throw new InvalidArgumentException('The activation grant expiry must be later than its issuance time.');
        }

        return new self($userId, hash('sha256', $credential->toString()), $expiresAt);
    }

    /**
     * Returns the owning user identifier.
     */
    public function getUserId(): UserId
    {
        return $this->userId;
    }

    /**
     * Returns the one-way credential hash.
     */
    public function getCredentialHash(): string
    {
        return $this->credentialHash;
    }

    /**
     * Returns whether a raw credential matches this grant without retaining it.
     */
    public function matchesCredential(ActivationCredential $credential): bool
    {
        return hash_equals($this->credentialHash, hash('sha256', $credential->toString()));
    }

    /**
     * Returns the credential expiration time.
     */
    public function getExpiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    /**
     * Returns whether the grant remains in its issued state.
     */
    public function isIssued(): bool
    {
        return !$this->consumedAt instanceof DateTimeImmutable && !$this->revokedAt instanceof DateTimeImmutable;
    }

    /**
     * Returns whether the grant has been consumed.
     */
    public function isConsumed(): bool
    {
        return $this->consumedAt instanceof DateTimeImmutable;
    }

    /**
     * Returns when the grant was consumed.
     */
    public function getConsumedAt(): ?DateTimeImmutable
    {
        return $this->consumedAt;
    }

    /**
     * Returns whether the grant has been revoked.
     */
    public function isRevoked(): bool
    {
        return $this->revokedAt instanceof DateTimeImmutable;
    }

    /**
     * Returns when the grant was revoked.
     */
    public function getRevokedAt(): ?DateTimeImmutable
    {
        return $this->revokedAt;
    }

    /**
     * Returns whether the grant can be consumed at the supplied time.
     */
    public function isUsableAt(DateTimeImmutable $at): bool
    {
        return $this->isIssued() && $at < $this->expiresAt;
    }

    /**
     * Consumes the grant at the supplied time.
     *
     * @throws LogicException When the grant is no longer usable.
     */
    public function consume(DateTimeImmutable $at): self
    {
        if ($this->isUsableAt($at) === false) {
            throw new LogicException('The activation grant is no longer usable.');
        }

        return new self($this->userId, $this->credentialHash, $this->expiresAt, $at, $this->revokedAt);
    }

    /**
     * Revokes the issued grant at the supplied time.
     *
     * @throws LogicException When the grant is no longer issued.
     */
    public function revoke(DateTimeImmutable $at): self
    {
        if ($this->isIssued() === false) {
            throw new LogicException('The activation grant is no longer issued.');
        }

        return new self($this->userId, $this->credentialHash, $this->expiresAt, $this->consumedAt, $at);
    }

    /**
     * Returns the grant purpose.
     */
    public function purpose(): string
    {
        return 'activation';
    }
}
