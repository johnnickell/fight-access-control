<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Command;

use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeDeliveryId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\Command;

/**
 * Invokes one exact email-change delivery generation.
 */
final readonly class DeliverEmailChange implements Command
{
    /**
     * Constructs the invocation-neutral delivery command.
     */
    public function __construct(
        private UserId $actorId,
        private UserId $userId,
        private EmailChangeDeliveryId $emailChangeDeliveryId
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function fromArray(array $data): static
    {
        foreach (['actor_id', 'user_id', 'email_change_delivery_id'] as $key) {
            if (!array_key_exists($key, $data)) {
                $message = sprintf('Missing required key "%s" in data array', $key);
                throw new DomainException($message);
            }
        }

        return new static(
            UserId::fromString((string) $data['actor_id']),
            UserId::fromString((string) $data['user_id']),
            EmailChangeDeliveryId::fromString((string) $data['email_change_delivery_id'])
        );
    }

    /**
     * @inheritDoc
     */
    public function toArray(): array
    {
        return [
            'actor_id' => $this->actorId->toString(),
            'user_id' => $this->userId->toString(),
            'email_change_delivery_id' => $this->emailChangeDeliveryId->toString(),
        ];
    }

    /**
     * Returns the actor that caused delivery invocation.
     */
    public function getActorId(): UserId
    {
        return $this->actorId;
    }

    /**
     * Returns the owning User.
     */
    public function getUserId(): UserId
    {
        return $this->userId;
    }

    /**
     * Returns the exact delivery generation.
     */
    public function getEmailChangeDeliveryId(): EmailChangeDeliveryId
    {
        return $this->emailChangeDeliveryId;
    }
}
