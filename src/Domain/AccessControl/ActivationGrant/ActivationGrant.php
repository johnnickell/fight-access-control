<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\ActivationGrant;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Exception\ActivationDeliveryNotRetryableException;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Exception\ActivationGrantException;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryClaimToken;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryFailure;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\Exception\CredentialDeliveryTransitionException;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Value\Internet\EmailAddress;

/**
 * Class ActivationGrant
 *
 * Owns one generation of activation authority and its delivery work.
 *
 * @phpstan-consistent-constructor
 */
class ActivationGrant
{
    /**
     * Constructs ActivationGrant
     *
     * Creates one immutable activation aggregate generation.
     */
    protected function __construct(
        private readonly ActivationGrantId $id,
        private readonly UserId $userId,
        private readonly string $credentialHash,
        private readonly DateTimeImmutable $expiresAt,
        private readonly ActivationDelivery $delivery,
        private readonly ?DateTimeImmutable $consumedAt = null,
        private readonly ?DateTimeImmutable $revokedAt = null,
        private readonly int $revision = 0
    ) {
    }

    /**
     * Issues activation authority and its owned delivery work
     */
    public static function issue(
        UserId $userId,
        ActivationCredential $credential,
        DateTimeImmutable $issuedAt,
        DateTimeImmutable $expiresAt,
        EmailAddress $email,
        string $ciphertext
    ): static {
        if ($expiresAt <= $issuedAt) {
            throw new ActivationGrantException(
                'The activation grant expiry must be later than its issuance time.'
            );
        }

        return new static(
            ActivationGrantId::generate(),
            $userId,
            hash('sha256', $credential->toString()),
            $expiresAt,
            ActivationDelivery::create(
                ActivationDeliveryId::generate(),
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
    public function getId(): ActivationGrantId
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
     * Checks a raw credential without retaining it
     */
    public function matchesCredential(ActivationCredential $credential): bool
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
    public function getDelivery(): ActivationDelivery
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
     * Uses usable activation authority
     */
    public function consume(DateTimeImmutable $at): self
    {
        if (!$this->isUsableAt($at)) {
            throw new ActivationGrantException('The activation grant is no longer usable.');
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
     * Revokes issued authority and destroys delivery ciphertext
     */
    public function revoke(DateTimeImmutable $at): self
    {
        if (!$this->isIssued()) {
            throw new ActivationGrantException('The activation grant is no longer issued.');
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
     * Acquires the owned delivery before invoking its transport
     */
    public function claimDelivery(
        ?CredentialDeliveryClaimToken $claimToken = null,
        ?DateTimeImmutable $claimedAt = null,
        ?DateTimeImmutable $leaseUntil = null
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
        return $this->withDelivery($this->delivery->confirm($claimToken, $occurredAt));
    }

    /**
     * Records a retryable owned-delivery outcome
     */
    public function failDelivery(
        ?CredentialDeliveryClaimToken $claimToken = null,
        ?DateTimeImmutable $occurredAt = null,
        CredentialDeliveryFailure $failure = CredentialDeliveryFailure::UNEXPECTED_PROVIDER
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
     * Transitions failed owned delivery work back to pending for a later claim
     */
    public function requestDeliveryRetry(): self
    {
        try {
            return $this->withDelivery($this->delivery->requestRetry());
        } catch (CredentialDeliveryTransitionException $credentialDeliveryTransitionException) {
            throw new ActivationDeliveryNotRetryableException(
                'The activation delivery work is no longer retryable.',
                previous: $credentialDeliveryTransitionException
            );
        }
    }

    /**
     * Marks the owned delivery at its terminal boundary
     */
    public function expireDeliveryAt(DateTimeImmutable $occurredAt): self
    {
        return $this->withDelivery($this->delivery->expireAt($occurredAt));
    }

    /**
     * Returns the fixed grant purpose
     */
    public function purpose(): string
    {
        return 'activation';
    }

    /**
     * Replaces only the owned delivery within this generation
     */
    private function withDelivery(ActivationDelivery $delivery): self
    {
        if (
            $delivery->getId()->equals($this->delivery->getId())
            && $delivery->getUserId()->equals($this->delivery->getUserId())
            && $delivery->sameStateAs($this->delivery)
        ) {
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
