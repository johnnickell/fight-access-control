<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\ActivationGrant\Query;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationDelivery;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationDeliveryStatus;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Type\Arrayable;

/**
 * Provides the safe operational view of activation delivery work.
 */
final readonly class InvitationDeliveryStatusView implements Arrayable
{
    /**
     * Constructs the safe delivery-status view.
     */
    public function __construct(
        private UserId $userId,
        private ActivationDeliveryStatus $status,
        private DateTimeImmutable $expiresAt
    ) {
    }

    /**
     * Creates the safe view from delivery work without exposing credential material.
     */
    public static function fromWork(ActivationDelivery $work): self
    {
        return new self($work->getUserId(), $work->getStatus(), $work->getExpiresAt());
    }

    /**
     * Returns the delivery work owner.
     */
    public function getUserId(): UserId
    {
        return $this->userId;
    }

    /**
     * Returns the safe operational delivery status.
     */
    public function getStatus(): ActivationDeliveryStatus
    {
        return $this->status;
    }

    /**
     * Returns the terminal expiry shared with the activation grant.
     */
    public function getExpiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    /**
     * Returns the canonical safe array representation.
     *
     * @return array{user_id: string, status: string, expires_at: string}
     */
    public function toArray(): array
    {
        return [
            'user_id' => $this->userId->toString(),
            'status' => $this->status->value,
            'expires_at' => $this->expiresAt->format(DATE_ATOM),
        ];
    }
}
