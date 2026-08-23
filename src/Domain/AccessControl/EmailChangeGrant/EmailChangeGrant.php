<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\EmailChangeGrant;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Exception\EmailChangeGrantException;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Value\Internet\EmailAddress;

/**
 * Owns one generation of email-change confirmation authority and delivery work.
 *
 * @phpstan-consistent-constructor
 */
class EmailChangeGrant
{
    /**
     * Creates an immutable email-change authority generation.
     */
    protected function __construct(
        private readonly EmailChangeGrantId $id,
        private readonly UserId $userId,
        private readonly string $credentialHash,
        private readonly DateTimeImmutable $expiresAt,
        private readonly EmailChangeDelivery $delivery,
        private readonly ?DateTimeImmutable $consumedAt = null,
        private readonly ?DateTimeImmutable $revokedAt = null,
        private readonly ?DateTimeImmutable $expiredAt = null,
        private readonly int $revision = 0
    ) {
    }

    /**
     * Issues unrelated expiring confirmation authority.
     */
    public static function issue(
        UserId $userId,
        EmailChangeCredential $credential,
        DateTimeImmutable $issuedAt,
        DateTimeImmutable $expiresAt,
        EmailAddress $email,
        string $ciphertext
    ): static {
        if ($expiresAt <= $issuedAt) {
            throw new EmailChangeGrantException('The email-change grant expiry must follow issuance.');
        }

        return new static(
            EmailChangeGrantId::generate(),
            $userId,
            hash('sha256', $credential->toString()),
            $expiresAt,
            EmailChangeDelivery::create(
                EmailChangeDeliveryId::generate(),
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
    public function getId(): EmailChangeGrantId
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
     * Returns the one-way credential hash.
     */
    public function getCredentialHash(): string
    {
        return $this->credentialHash;
    }

    /**
     * Compares raw credential input without retaining it.
     */
    public function matchesCredential(EmailChangeCredential $credential): bool
    {
        return hash_equals($this->credentialHash, hash('sha256', $credential->toString()));
    }

    /**
     * Returns the confirmation-authority expiry.
     */
    public function getExpiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    /**
     * Returns whether confirmation authority is usable at the supplied time.
     */
    public function isUsableAt(DateTimeImmutable $at): bool
    {
        return $this->isIssued() && $at < $this->expiresAt;
    }

    /**
     * Consumes usable authority and destroys recoverable credential material.
     */
    public function consume(DateTimeImmutable $at): self
    {
        if (!$this->isUsableAt($at)) {
            throw new EmailChangeGrantException('The email-change grant is no longer usable.');
        }

        return new static(
            $this->id,
            $this->userId,
            $this->credentialHash,
            $this->expiresAt,
            $this->delivery->invalidate(),
            $at,
            $this->revokedAt,
            $this->expiredAt,
            $this->revision + 1
        );
    }

    /**
     * Revokes issued authority and destroys recoverable delivery material.
     */
    public function revoke(DateTimeImmutable $at): self
    {
        if (!$this->isIssued()) {
            throw new EmailChangeGrantException('The email-change grant is no longer issued.');
        }

        return new static(
            $this->id,
            $this->userId,
            $this->credentialHash,
            $this->expiresAt,
            $this->delivery->invalidate(),
            $this->consumedAt,
            $at,
            $this->expiredAt,
            $this->revision + 1
        );
    }

    /**
     * Terminalizes issued authority once its expiry boundary is reached.
     */
    public function expireAt(DateTimeImmutable $at): self
    {
        if (!$this->isIssued() || $at < $this->expiresAt) {
            return $this;
        }

        return new static(
            $this->id,
            $this->userId,
            $this->credentialHash,
            $this->expiresAt,
            $this->delivery->invalidate(),
            $this->consumedAt,
            $this->revokedAt,
            $at,
            $this->revision + 1
        );
    }

    /**
     * Returns whether authority remains issued.
     */
    public function isIssued(): bool
    {
        return !$this->consumedAt instanceof DateTimeImmutable
            && !$this->revokedAt instanceof DateTimeImmutable
            && !$this->expiredAt instanceof DateTimeImmutable;
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
     * Returns whether authority expired.
     */
    public function isExpired(): bool
    {
        return $this->expiredAt instanceof DateTimeImmutable;
    }

    /**
     * Returns when authority was terminalized by expiry.
     */
    public function getExpiredAt(): ?DateTimeImmutable
    {
        return $this->expiredAt;
    }

    /**
     * Returns the monotonic aggregate revision.
     */
    public function getRevision(): int
    {
        return $this->revision;
    }

    /**
     * Returns the owned recoverable delivery work.
     */
    public function getDelivery(): EmailChangeDelivery
    {
        return $this->delivery;
    }

    /**
     * Claims the owned delivery before invoking its transport.
     */
    public function claimDelivery(): self
    {
        return $this->withDelivery($this->delivery->claim());
    }

    /**
     * Confirms the owned delivery after successful invocation.
     */
    public function confirmDelivery(): self
    {
        return $this->withDelivery($this->delivery->confirm());
    }

    /**
     * Records a failed owned-delivery invocation.
     */
    public function failDelivery(): self
    {
        return $this->withDelivery($this->delivery->fail());
    }

    /**
     * Returns the fixed grant purpose.
     */
    public function purpose(): string
    {
        return 'email_change';
    }

    /**
     * Replaces only the owned delivery within this generation.
     */
    private function withDelivery(EmailChangeDelivery $delivery): self
    {
        return new static(
            $this->id,
            $this->userId,
            $this->credentialHash,
            $this->expiresAt,
            $delivery,
            $this->consumedAt,
            $this->revokedAt,
            $this->expiredAt,
            $this->revision + 1
        );
    }
}
