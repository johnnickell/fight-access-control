<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\ActivationGrant\Command;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationDeliveryId;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryTimestamp;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\Command;

/**
 * Class ExpireInvitationDelivery
 *
 * Processes terminal expiry for invitation delivery work.
 */
final readonly class ExpireInvitationDelivery implements Command
{
    /**
     * Constructs ExpireInvitationDelivery
     *
     * Creates the terminal-expiry command.
     */
    public function __construct(
        private string $actorId,
        private UserId $userId,
        private ActivationDeliveryId $activationDeliveryId,
        private DateTimeImmutable $occurredAt
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function fromArray(array $data): static
    {
        foreach (['actor_id', 'user_id', 'activation_delivery_id', 'occurred_at'] as $key) {
            if (!array_key_exists($key, $data)) {
                throw new DomainException(sprintf('Missing required key "%s" in data array', $key));
            }
        }

        return new static(
            (string) $data['actor_id'],
            UserId::fromString((string) $data['user_id']),
            ActivationDeliveryId::fromString((string) $data['activation_delivery_id']),
            CredentialDeliveryTimestamp::fromString((string) $data['occurred_at'])->toDateTimeImmutable()
        );
    }

    /**
     * @inheritDoc
     */
    public function toArray(): array
    {
        return [
            'actor_id'               => $this->actorId,
            'user_id'                => $this->userId->toString(),
            'activation_delivery_id' => $this->activationDeliveryId->toString(),
            'occurred_at'            => $this->occurredAt->format('Y-m-d\TH:i:s.uP')
        ];
    }

    /**
     * Returns the actor processing terminal expiry
     */
    public function getActorId(): string
    {
        return $this->actorId;
    }

    /**
     * Returns the target user identifier
     */
    public function getUserId(): UserId
    {
        return $this->userId;
    }

    /**
     * Returns the exact delivery-generation identifier
     */
    public function getActivationDeliveryId(): ActivationDeliveryId
    {
        return $this->activationDeliveryId;
    }

    /**
     * Returns when terminal expiry was processed
     */
    public function getOccurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
