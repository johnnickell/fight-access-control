<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\User\Query;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\User\InvitationDelivery;
use Fight\AccessControl\Domain\AccessControl\User\InvitationDeliveryStatus;
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
        private InvitationDeliveryStatus $status,
        private DateTimeImmutable $expiresAt
    ) {
    }

    /**
     * Creates the safe view from delivery work without exposing credential material.
     */
    public static function fromWork(InvitationDelivery $work): self
    {
        return new self($work->userId(), $work->getStatus(), $work->expiresAt());
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
    public function getStatus(): InvitationDeliveryStatus
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
