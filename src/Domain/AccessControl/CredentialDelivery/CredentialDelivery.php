<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\CredentialDelivery;

use DateInterval;
use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\Exception\CredentialDeliveryTransitionException;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Identity\UniqueId;
use Fight\Common\Domain\Value\Internet\EmailAddress;

/**
 * Class CredentialDelivery
 *
 * Owns the common recoverable lifecycle for one immutable credential-delivery generation.
 *
 * @phpstan-consistent-constructor
 */
abstract class CredentialDelivery
{
    private const int MAXIMUM_RETRY_DELAY_SECONDS = 3600;

    private const int INITIAL_RETRY_DELAY_SECONDS = 60;

    /**
     * Constructs CredentialDelivery
     */
    protected function __construct(
        private readonly UniqueId $id,
        private readonly UserId $userId,
        private readonly EmailAddress $email,
        private readonly ?EncryptedCredentialMaterial $encryptedMaterial,
        private readonly DateTimeImmutable $expiresAt,
        private readonly DateTimeImmutable $dueAt,
        private readonly CredentialDeliveryStatus $status = CredentialDeliveryStatus::PENDING,
        private readonly ?CredentialDeliveryClaimToken $claimToken = null,
        private readonly ?DateTimeImmutable $claimedAt = null,
        private readonly ?DateTimeImmutable $leaseUntil = null,
        private readonly int $attemptCount = 0,
        private readonly ?DateTimeImmutable $lastAttemptAt = null,
        private readonly ?DateTimeImmutable $lastOutcomeAt = null,
        private readonly ?CredentialDeliveryFailure $lastFailure = null
    ) {
    }

    /**
     * Returns the delivery-generation identifier
     */
    public function getId(): UniqueId
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
     * Returns the canonical destination email
     */
    public function getEmail(): EmailAddress
    {
        return $this->email;
    }

    /**
     * Returns encrypted material for persistence without exposing a raw ciphertext string
     */
    public function getEncryptedMaterial(): ?EncryptedCredentialMaterial
    {
        return $this->encryptedMaterial;
    }

    /**
     * Returns the terminal delivery expiry
     */
    public function getExpiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    /**
     * Returns when pending or retry work becomes eligible
     */
    public function getDueAt(): DateTimeImmutable
    {
        return $this->dueAt;
    }

    /**
     * Returns the safe operational status
     */
    public function getStatus(): CredentialDeliveryStatus
    {
        return $this->status;
    }

    /**
     * Returns the current opaque claim token
     */
    public function getClaimToken(): ?CredentialDeliveryClaimToken
    {
        return $this->claimToken;
    }

    /**
     * Returns when the current attempt was claimed
     */
    public function getClaimedAt(): ?DateTimeImmutable
    {
        return $this->claimedAt;
    }

    /**
     * Returns when the current claim lease stops accepting outcomes
     */
    public function getLeaseUntil(): ?DateTimeImmutable
    {
        return $this->leaseUntil;
    }

    /**
     * Returns the number of persisted provider attempts
     */
    public function getAttemptCount(): int
    {
        return $this->attemptCount;
    }

    /**
     * Returns when the latest attempt was claimed
     */
    public function getLastAttemptAt(): ?DateTimeImmutable
    {
        return $this->lastAttemptAt;
    }

    /**
     * Returns when the latest provider outcome was recorded
     */
    public function getLastOutcomeAt(): ?DateTimeImmutable
    {
        return $this->lastOutcomeAt;
    }

    /**
     * Returns the latest secret-free failure classification
     */
    public function getLastFailure(): ?CredentialDeliveryFailure
    {
        return $this->lastFailure;
    }

    /**
     * Returns when this generation next becomes discoverable
     */
    public function getNextAttemptAt(): DateTimeImmutable
    {
        if (
            $this->status === CredentialDeliveryStatus::CLAIMED
            && $this->leaseUntil instanceof DateTimeImmutable
        ) {
            return $this->leaseUntil;
        }

        return $this->dueAt;
    }

    /**
     * Returns whether encrypted credential material remains recoverable
     */
    public function hasRecoverableMaterial(): bool
    {
        return $this->encryptedMaterial instanceof EncryptedCredentialMaterial;
    }

    /**
     * Returns whether work is eligible for a new claim at the supplied time
     */
    public function isDueAt(DateTimeImmutable $at): bool
    {
        if (!$this->hasRecoverableMaterial() || $at >= $this->expiresAt) {
            return false;
        }

        if ($this->status === CredentialDeliveryStatus::CLAIMED) {
            return $this->leaseUntil instanceof DateTimeImmutable && $at >= $this->leaseUntil;
        }

        return in_array(
            $this->status,
            [CredentialDeliveryStatus::PENDING, CredentialDeliveryStatus::RETRY_PENDING],
            true
        ) && $at >= $this->dueAt;
    }

    /**
     * Returns whether delivery remains eligible now or after its due time
     */
    public function isRetryable(): bool
    {
        return $this->hasRecoverableMaterial() && in_array(
            $this->status,
            [CredentialDeliveryStatus::PENDING, CredentialDeliveryStatus::RETRY_PENDING],
            true
        );
    }

    /**
     * Acquires due work under one opaque lease
     */
    public function claim(
        ?CredentialDeliveryClaimToken $claimToken = null,
        ?DateTimeImmutable $claimedAt = null,
        ?DateTimeImmutable $leaseUntil = null
    ): static {
        $claimToken ??= CredentialDeliveryClaimToken::generate();
        $claimedAt ??= $this->getNextAttemptAt();
        $leaseUntil ??= $claimedAt->add(new DateInterval('PT5M'));

        if (!$this->isDueAt($claimedAt)) {
            throw new CredentialDeliveryTransitionException('The credential delivery is not due for a claim.');
        }

        if ($leaseUntil <= $claimedAt || $leaseUntil > $this->expiresAt) {
            throw new CredentialDeliveryTransitionException('The credential delivery claim lease is invalid.');
        }

        return $this->copy(
            status: CredentialDeliveryStatus::CLAIMED,
            claimToken: $claimToken,
            claimedAt: $claimedAt,
            leaseUntil: $leaseUntil,
            attemptCount: $this->attemptCount + 1,
            lastAttemptAt: $claimedAt,
            lastOutcomeAt: $this->lastOutcomeAt,
            lastFailure: $this->lastFailure
        );
    }

    /**
     * Returns encrypted material only for the matching live claim
     */
    public function materialForClaim(
        CredentialDeliveryClaimToken $claimToken,
        DateTimeImmutable $at
    ): EncryptedCredentialMaterial {
        $this->assertLiveClaim($claimToken, $at);

        if (!$this->encryptedMaterial instanceof EncryptedCredentialMaterial) {
            throw new CredentialDeliveryTransitionException('The credential delivery material is unavailable.');
        }

        return $this->encryptedMaterial;
    }

    /**
     * Records delivered outcome and destroys recoverable material
     */
    public function confirm(
        ?CredentialDeliveryClaimToken $claimToken = null,
        ?DateTimeImmutable $occurredAt = null
    ): static {
        [$claimToken, $occurredAt] = $this->resolveOutcomeState($claimToken, $occurredAt);
        $this->assertLiveClaim($claimToken, $occurredAt);

        return $this->copy(
            encryptedMaterial: null,
            status: CredentialDeliveryStatus::DELIVERED,
            claimToken: null,
            claimedAt: null,
            leaseUntil: null,
            lastOutcomeAt: $occurredAt,
            lastFailure: null
        );
    }

    /**
     * Records a retryable outcome under package-owned bounded backoff
     */
    public function fail(
        ?CredentialDeliveryClaimToken $claimToken = null,
        ?DateTimeImmutable $occurredAt = null,
        CredentialDeliveryFailure $failure = CredentialDeliveryFailure::UNEXPECTED_PROVIDER
    ): static {
        if ($failure === CredentialDeliveryFailure::PERMANENT_PROVIDER) {
            throw new CredentialDeliveryTransitionException('A permanent failure cannot be recorded as retryable.');
        }

        [$claimToken, $occurredAt] = $this->resolveOutcomeState($claimToken, $occurredAt);
        $this->assertLiveClaim($claimToken, $occurredAt);
        $nextDueAt = $occurredAt->add(new DateInterval('PT'.$this->retryDelaySeconds().'S'));
        if ($nextDueAt >= $this->expiresAt) {
            return $this->copy(
                encryptedMaterial: null,
                status: CredentialDeliveryStatus::EXPIRED,
                dueAt: $this->expiresAt,
                claimToken: null,
                claimedAt: null,
                leaseUntil: null,
                lastOutcomeAt: $occurredAt,
                lastFailure: $failure
            );
        }

        return $this->copy(
            status: CredentialDeliveryStatus::RETRY_PENDING,
            dueAt: $nextDueAt,
            claimToken: null,
            claimedAt: null,
            leaseUntil: null,
            lastOutcomeAt: $occurredAt,
            lastFailure: $failure
        );
    }

    /**
     * Records a permanent outcome and destroys recoverable material
     */
    public function failPermanently(
        CredentialDeliveryClaimToken $claimToken,
        DateTimeImmutable $occurredAt
    ): static {
        $this->assertLiveClaim($claimToken, $occurredAt);

        return $this->copy(
            encryptedMaterial: null,
            status: CredentialDeliveryStatus::PERMANENT_FAILURE,
            claimToken: null,
            claimedAt: null,
            leaseUntil: null,
            lastOutcomeAt: $occurredAt,
            lastFailure: CredentialDeliveryFailure::PERMANENT_PROVIDER
        );
    }

    /**
     * Returns retry work to immediate eligibility while preserving attempt evidence
     */
    public function requestRetry(): static
    {
        if ($this->status !== CredentialDeliveryStatus::RETRY_PENDING || !$this->hasRecoverableMaterial()) {
            throw new CredentialDeliveryTransitionException('The credential delivery has no retryable outcome.');
        }

        return $this->copy(
            status: CredentialDeliveryStatus::PENDING,
            dueAt: $this->lastAttemptAt ?? $this->dueAt,
            lastOutcomeAt: $this->lastOutcomeAt,
            lastFailure: $this->lastFailure
        );
    }

    /**
     * Marks delivery at its terminal expiry boundary
     */
    public function expireAt(DateTimeImmutable $occurredAt): static
    {
        if (!$this->hasRecoverableMaterial() || $occurredAt < $this->expiresAt) {
            return $this;
        }

        return $this->copy(
            encryptedMaterial: null,
            status: CredentialDeliveryStatus::EXPIRED,
            dueAt: $this->expiresAt,
            claimToken: null,
            claimedAt: null,
            leaseUntil: null,
            lastOutcomeAt: $this->lastOutcomeAt,
            lastFailure: $this->lastFailure
        );
    }

    /**
     * Invalidates delivery and destroys recoverable material
     */
    public function invalidate(): static
    {
        if (!$this->hasRecoverableMaterial()) {
            return $this;
        }

        return $this->copy(
            encryptedMaterial: null,
            status: CredentialDeliveryStatus::INVALIDATED,
            claimToken: null,
            claimedAt: null,
            leaseUntil: null,
            lastOutcomeAt: $this->lastOutcomeAt,
            lastFailure: $this->lastFailure
        );
    }

    /**
     * Returns whether every persisted delivery-state field matches
     */
    public function sameStateAs(self $other): bool
    {
        $materialMatches = $this->encryptedMaterial === null && $other->encryptedMaterial === null;
        if (
            $this->encryptedMaterial instanceof EncryptedCredentialMaterial
            && $other->encryptedMaterial instanceof EncryptedCredentialMaterial
        ) {
            $materialMatches = $this->encryptedMaterial->equals($other->encryptedMaterial);
        }

        return $this->id->equals($other->id)
            && $this->userId->equals($other->userId)
            && $this->email->canonical() === $other->email->canonical()
            && $materialMatches
            && $this->expiresAt == $other->expiresAt
            && $this->dueAt == $other->dueAt
            && $this->status === $other->status
            && $this->sameOptionalToken($this->claimToken, $other->claimToken)
            && $this->claimedAt == $other->claimedAt
            && $this->leaseUntil == $other->leaseUntil
            && $this->attemptCount === $other->attemptCount
            && $this->lastAttemptAt == $other->lastAttemptAt
            && $this->lastOutcomeAt == $other->lastOutcomeAt
            && $this->lastFailure === $other->lastFailure;
    }

    /**
     * Returns an immutable replacement with selected state changes
     */
    private function copy(
        ?EncryptedCredentialMaterial $encryptedMaterial = null,
        ?DateTimeImmutable $dueAt = null,
        ?CredentialDeliveryStatus $status = null,
        ?CredentialDeliveryClaimToken $claimToken = null,
        ?DateTimeImmutable $claimedAt = null,
        ?DateTimeImmutable $leaseUntil = null,
        ?int $attemptCount = null,
        ?DateTimeImmutable $lastAttemptAt = null,
        ?DateTimeImmutable $lastOutcomeAt = null,
        ?CredentialDeliveryFailure $lastFailure = null
    ): static {
        $terminalStatus = in_array(
            $status,
            [
                CredentialDeliveryStatus::DELIVERED,
                CredentialDeliveryStatus::PERMANENT_FAILURE,
                CredentialDeliveryStatus::EXPIRED,
                CredentialDeliveryStatus::INVALIDATED
            ],
            true
        );

        return new static(
            $this->id,
            $this->userId,
            $this->email,
            $encryptedMaterial ?? ($terminalStatus ? null : $this->encryptedMaterial),
            $this->expiresAt,
            $dueAt ?? $this->dueAt,
            $status ?? $this->status,
            $claimToken,
            $claimedAt,
            $leaseUntil,
            $attemptCount ?? $this->attemptCount,
            $lastAttemptAt ?? $this->lastAttemptAt,
            $lastOutcomeAt,
            $lastFailure
        );
    }

    /**
     * Requires a matching claim before its lease expires
     */
    private function assertLiveClaim(
        CredentialDeliveryClaimToken $claimToken,
        DateTimeImmutable $at
    ): void {
        if (
            $this->status !== CredentialDeliveryStatus::CLAIMED
            || !$this->claimToken instanceof CredentialDeliveryClaimToken
            || !$this->claimToken->equals($claimToken)
            || !$this->claimedAt instanceof DateTimeImmutable
            || !$this->leaseUntil instanceof DateTimeImmutable
            || $at < $this->claimedAt
            || $at >= $this->leaseUntil
        ) {
            throw new CredentialDeliveryTransitionException('The credential delivery claim is stale or invalid.');
        }
    }

    /**
     * Resolves compatibility defaults from the persisted claim itself
     *
     * @return array{CredentialDeliveryClaimToken, DateTimeImmutable}
     */
    private function resolveOutcomeState(
        ?CredentialDeliveryClaimToken $claimToken,
        ?DateTimeImmutable $occurredAt
    ): array {
        if (
            !$this->claimToken instanceof CredentialDeliveryClaimToken
            || !$this->claimedAt instanceof DateTimeImmutable
        ) {
            throw new CredentialDeliveryTransitionException('The credential delivery has no active claim.');
        }

        return [$claimToken ?? $this->claimToken, $occurredAt ?? $this->claimedAt];
    }

    /**
     * Returns package-owned bounded exponential retry delay
     */
    private function retryDelaySeconds(): int
    {
        $exponent = max(0, min(20, $this->attemptCount - 1));

        return min(self::MAXIMUM_RETRY_DELAY_SECONDS, self::INITIAL_RETRY_DELAY_SECONDS * (2 ** $exponent));
    }

    /**
     * Returns whether optional claim tokens match
     */
    private function sameOptionalToken(
        ?CredentialDeliveryClaimToken $left,
        ?CredentialDeliveryClaimToken $right
    ): bool {
        if ($left === null || $right === null) {
            return $left === $right;
        }

        return $left->equals($right);
    }
}
