<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\PasswordResetGrant;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryClaimToken;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryFailure;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryStatus;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\Exception\PasswordResetGrantException;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Value\Internet\EmailAddress;

/**
 * Class PasswordResetGrant
 *
 * Owns one generation of password-reset authority and its delivery work.
 *
 * @phpstan-consistent-constructor
 */
class PasswordResetGrant
{
    /**
     * Constructs PasswordResetGrant
     *
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
     * Issues an expiring aggregate generation
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
            throw new PasswordResetGrantException(
                'The password-reset grant expiry must be later than its issuance time.'
            );
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
                $expiresAt,
                $issuedAt
            )
        );
    }

    /**
     * Returns the aggregate-generation identifier
     */
    public function getId(): PasswordResetGrantId
    {
        return $this->id;
    }

    /**
     * Returns the owning user identifier
     */
    public function getUserId(): UserId
    {
        return $this->userId;
    }

    /**
     * Returns the monotonic state revision used for compare-and-set persistence
     */
    public function getRevision(): int
    {
        return $this->revision;
    }

    /**
     * Returns the one-way credential hash
     */
    public function getCredentialHash(): string
    {
        return $this->credentialHash;
    }

    /**
     * Checks raw credential input without retaining it
     */
    public function matchesCredential(PasswordResetCredential $credential): bool
    {
        return hash_equals($this->credentialHash, hash('sha256', $credential->toString()));
    }

    /**
     * Returns the credential expiry
     */
    public function getExpiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    /**
     * Returns the owned delivery entity
     */
    public function getDelivery(): PasswordResetDelivery
    {
        return $this->delivery;
    }

    /**
     * Returns whether authority remains issued
     */
    public function isIssued(): bool
    {
        return !$this->consumedAt instanceof DateTimeImmutable && !$this->revokedAt instanceof DateTimeImmutable;
    }

    /**
     * Returns whether authority was consumed
     */
    public function isConsumed(): bool
    {
        return $this->consumedAt instanceof DateTimeImmutable;
    }

    /**
     * Returns when authority was consumed
     */
    public function getConsumedAt(): ?DateTimeImmutable
    {
        return $this->consumedAt;
    }

    /**
     * Returns whether authority was revoked
     */
    public function isRevoked(): bool
    {
        return $this->revokedAt instanceof DateTimeImmutable;
    }

    /**
     * Returns when authority was revoked
     */
    public function getRevokedAt(): ?DateTimeImmutable
    {
        return $this->revokedAt;
    }

    /**
     * Returns whether authority can be consumed at the supplied time
     */
    public function isUsableAt(DateTimeImmutable $at): bool
    {
        return $this->isIssued() && $at < $this->expiresAt;
    }

    /**
     * Uses usable authority
     */
    public function consume(DateTimeImmutable $at): self
    {
        if ($this->isUsableAt($at) === false) {
            throw new PasswordResetGrantException('The password-reset grant is no longer usable.');
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
     * Revokes issued authority and destroys its delivery ciphertext
     */
    public function revoke(DateTimeImmutable $at): self
    {
        if ($this->isIssued() === false) {
            throw new PasswordResetGrantException('The password-reset grant is no longer issued.');
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
     * Acquires the owned delivery under one opaque lease
     */
    public function claimDelivery(
        CredentialDeliveryClaimToken $claimToken,
        DateTimeImmutable $claimedAt,
        DateTimeImmutable $leaseUntil
    ): self {
        return $this->withDelivery($this->delivery->claim($claimToken, $claimedAt, $leaseUntil));
    }

    /**
     * Completes the owned delivery
     */
    public function confirmDelivery(
        ?CredentialDeliveryClaimToken $claimToken = null,
        ?DateTimeImmutable $occurredAt = null
    ): self {
        if ($claimToken === null) {
            if (!$this->delivery->hasRecoverableMaterial()) {
                return $this;
            }

            if ($this->delivery->getStatus() === CredentialDeliveryStatus::PENDING) {
                return $this->withDelivery($this->delivery->claim()->confirm());
            }
        }

        return $this->withDelivery($this->delivery->confirm($claimToken, $occurredAt));
    }

    /**
     * Records a retryable owned-delivery outcome
     */
    public function failDelivery(
        CredentialDeliveryClaimToken $claimToken,
        DateTimeImmutable $occurredAt,
        CredentialDeliveryFailure $failure
    ): self {
        return $this->withDelivery($this->delivery->fail($claimToken, $occurredAt, $failure));
    }

    /**
     * Records a permanent owned-delivery outcome
     */
    public function failDeliveryPermanently(
        CredentialDeliveryClaimToken $claimToken,
        DateTimeImmutable $occurredAt
    ): self {
        return $this->withDelivery($this->delivery->failPermanently($claimToken, $occurredAt));
    }

    /**
     * Returns retry work to immediate eligibility
     */
    public function requestDeliveryRetry(): self
    {
        return $this->withDelivery($this->delivery->requestRetry());
    }

    /**
     * Marks the owned delivery at its terminal boundary
     */
    public function expireDeliveryAt(DateTimeImmutable $occurredAt): self
    {
        return $this->withDelivery($this->delivery->expireAt($occurredAt));
    }

    /**
     * Deletes recoverable delivery ciphertext
     */
    public function invalidateDelivery(): self
    {
        return $this->withDelivery($this->delivery->invalidate());
    }

    /**
     * Returns the fixed grant purpose
     */
    public function purpose(): string
    {
        return 'password_reset';
    }

    /**
     * Replaces only the owned delivery within this immutable generation
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
