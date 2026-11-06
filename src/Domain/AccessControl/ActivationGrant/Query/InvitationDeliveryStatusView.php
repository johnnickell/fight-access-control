<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\ActivationGrant\Query;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationDelivery;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationDeliveryId;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationOrigin;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryStatus;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Type\Arrayable;

/**
 * Class InvitationDeliveryStatusView
 *
 * Provides the safe operational view of activation delivery work.
 */
final readonly class InvitationDeliveryStatusView implements Arrayable
{
    /**
     * Constructs InvitationDeliveryStatusView
     *
     * Constructs the safe delivery-status view.
     */
    public function __construct(
        private UserId $userId,
        private CredentialDeliveryStatus $status,
        private DateTimeImmutable $expiresAt,
        private ActivationDeliveryId $activationDeliveryId,
        private ActivationOrigin $origin
    ) {
    }

    /**
     * Creates the safe view from delivery work without exposing credential material
     */
    public static function fromWork(ActivationDelivery $work, ActivationOrigin $origin): self
    {
        return new self($work->getUserId(), $work->getStatus(), $work->getExpiresAt(), $work->getId(), $origin);
    }

    /**
     * Returns the recorded activation journey without granting delivery authority
     */
    public function getOrigin(): ActivationOrigin
    {
        return $this->origin;
    }

    /**
     * Returns the delivery work owner
     */
    public function getUserId(): UserId
    {
        return $this->userId;
    }

    /**
     * Returns the current generation identity for protected administrator inspection
     */
    public function getActivationDeliveryId(): ActivationDeliveryId
    {
        return $this->activationDeliveryId;
    }

    /**
     * Returns the safe operational delivery status
     */
    public function getStatus(): CredentialDeliveryStatus
    {
        return $this->status;
    }

    /**
     * Returns the terminal expiry shared with the activation grant
     */
    public function getExpiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    /**
     * Returns the canonical safe array representation
     *
     * @return array{
     *     user_id: string, status: string, expires_at: string, activation_delivery_id: string,
     *     origin: 'private_invitation'|'registration'
     * }
     */
    public function toArray(): array
    {
        return [
            'user_id'                => $this->userId->toString(),
            'status'                 => $this->status->value,
            'expires_at'             => $this->expiresAt->format(DATE_ATOM),
            'activation_delivery_id' => $this->activationDeliveryId->toString(),
            'origin'                 => $this->origin->value
        ];
    }
}
