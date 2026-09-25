<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\CredentialDelivery;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Identity\UniqueId;
use Fight\Common\Domain\Type\Arrayable;

/**
 * Class DueCredentialDelivery
 *
 * Identifies discoverable work without exposing credential or destination material.
 */
final readonly class DueCredentialDelivery implements Arrayable
{
    /**
     * Constructs DueCredentialDelivery
     */
    public function __construct(
        private string $purpose,
        private UniqueId $deliveryId,
        private UserId $userId,
        private DateTimeImmutable $dueAt,
        private int $revision,
        private CredentialDeliveryStatus $status
    ) {
    }

    /**
     * Returns the credential purpose
     */
    public function getPurpose(): string
    {
        return $this->purpose;
    }

    /**
     * Returns the stable delivery-generation identifier
     */
    public function getDeliveryId(): UniqueId
    {
        return $this->deliveryId;
    }

    /**
     * Returns the owning User identifier needed by the exact delivery command
     */
    public function getUserId(): UserId
    {
        return $this->userId;
    }

    /**
     * Returns the discovery eligibility time
     */
    public function getDueAt(): DateTimeImmutable
    {
        return $this->dueAt;
    }

    /**
     * Returns the aggregate revision observed during discovery
     */
    public function getRevision(): int
    {
        return $this->revision;
    }

    /**
     * Returns the safe operational status
     */
    public function getStatus(): CredentialDeliveryStatus
    {
        return $this->status;
    }

    /**
     * Returns the canonical secret-free representation
     *
     * @return array{
     *     purpose: string,
     *     delivery_id: string,
     *     user_id: string,
     *     due_at: string,
     *     revision: int,
     *     status: string
     * }
     */
    public function toArray(): array
    {
        return [
            'purpose'     => $this->purpose,
            'delivery_id' => $this->deliveryId->toString(),
            'user_id'     => $this->userId->toString(),
            'due_at'      => $this->dueAt->format(DATE_ATOM),
            'revision'    => $this->revision,
            'status'      => $this->status->value
        ];
    }
}
