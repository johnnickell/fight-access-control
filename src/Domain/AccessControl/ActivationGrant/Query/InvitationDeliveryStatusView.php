<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\ActivationGrant\Query;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationDelivery;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationDeliveryStatus;
use Fight\AccessControl\Domain\AccessControl\User\UserId;

/**
 * Provides the safe operational view of activation delivery work.
 */
final readonly class InvitationDeliveryStatusView
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
}
