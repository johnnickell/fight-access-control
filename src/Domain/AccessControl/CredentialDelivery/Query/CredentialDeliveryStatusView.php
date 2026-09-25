<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\CredentialDelivery\Query;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDelivery;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryFailure;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryStatus;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Type\Arrayable;

/**
 * Class CredentialDeliveryStatusView
 *
 * Provides secret-free operational state for one delivery generation.
 */
final readonly class CredentialDeliveryStatusView implements Arrayable
{
    /**
     * Constructs CredentialDeliveryStatusView
     */
    private function __construct(
        private string $purpose,
        private string $deliveryId,
        private UserId $userId,
        private int $revision,
        private CredentialDeliveryStatus $status,
        private DateTimeImmutable $dueAt,
        private DateTimeImmutable $expiresAt,
        private int $attemptCount,
        private ?DateTimeImmutable $lastAttemptAt,
        private ?DateTimeImmutable $lastOutcomeAt,
        private ?CredentialDeliveryFailure $lastFailure
    ) {
    }

    /**
     * Creates a safe view from package-owned delivery state
     */
    public static function fromDelivery(string $purpose, CredentialDelivery $delivery, int $revision): self
    {
        return new self(
            $purpose,
            $delivery->getId()->toString(),
            $delivery->getUserId(),
            $revision,
            $delivery->getStatus(),
            $delivery->getNextAttemptAt(),
            $delivery->getExpiresAt(),
            $delivery->getAttemptCount(),
            $delivery->getLastAttemptAt(),
            $delivery->getLastOutcomeAt(),
            $delivery->getLastFailure()
        );
    }

    /**
     * Returns the credential purpose
     */
    public function getPurpose(): string
    {
        return $this->purpose;
    }

    /**
     * Returns the delivery-generation identifier
     */
    public function getDeliveryId(): string
    {
        return $this->deliveryId;
    }

    /**
     * Returns the owning User identifier
     */
    public function getUserId(): UserId
    {
        return $this->userId;
    }

    /**
     * Returns the aggregate revision
     */
    public function getRevision(): int
    {
        return $this->revision;
    }

    /**
     * Returns the safe lifecycle status
     */
    public function getStatus(): CredentialDeliveryStatus
    {
        return $this->status;
    }

    /**
     * Returns the next discovery time
     */
    public function getDueAt(): DateTimeImmutable
    {
        return $this->dueAt;
    }

    /**
     * Returns the terminal expiry
     */
    public function getExpiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    /**
     * Returns the persisted attempt count
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
     * Returns when the latest outcome was recorded
     */
    public function getLastOutcomeAt(): ?DateTimeImmutable
    {
        return $this->lastOutcomeAt;
    }

    /**
     * Returns the latest safe failure classification
     */
    public function getLastFailure(): ?CredentialDeliveryFailure
    {
        return $this->lastFailure;
    }

    /**
     * Returns the canonical secret-free representation
     *
     * @return array{
     *     purpose: string,
     *     delivery_id: string,
     *     user_id: string,
     *     revision: int,
     *     status: string,
     *     due_at: string,
     *     expires_at: string,
     *     attempt_count: int,
     *     last_attempt_at: ?string,
     *     last_outcome_at: ?string,
     *     last_failure: ?string
     * }
     */
    public function toArray(): array
    {
        return [
            'purpose'         => $this->purpose,
            'delivery_id'     => $this->deliveryId,
            'user_id'         => $this->userId->toString(),
            'revision'        => $this->revision,
            'status'          => $this->status->value,
            'due_at'          => $this->dueAt->format(DATE_ATOM),
            'expires_at'      => $this->expiresAt->format(DATE_ATOM),
            'attempt_count'   => $this->attemptCount,
            'last_attempt_at' => $this->lastAttemptAt?->format(DATE_ATOM),
            'last_outcome_at' => $this->lastOutcomeAt?->format(DATE_ATOM),
            'last_failure'    => $this->lastFailure?->value
        ];
    }
}
