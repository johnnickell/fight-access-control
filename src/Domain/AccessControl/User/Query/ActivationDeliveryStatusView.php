<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\User\Query;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\User\ActivationDeliveryStatus;
use Fight\AccessControl\Domain\AccessControl\User\ActivationDeliveryWork;
use Fight\AccessControl\Domain\AccessControl\User\UserId;

/**
 * Provides the safe operational view of activation delivery work.
 */
final readonly class ActivationDeliveryStatusView
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
    public static function fromWork(ActivationDeliveryWork $work): self
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
