<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Command;

use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\Command;

/**
 * Requests cancellation of a target user's issued email change.
 */
final readonly class CancelEmailChange implements Command
{
    /**
     * Creates an email-change cancellation.
     */
    public function __construct(
        private UserId $actorId,
        private UserId $userId
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function fromArray(array $data): static
    {
        foreach (['actor_id', 'user_id'] as $key) {
            if (!array_key_exists($key, $data)) {
                throw new DomainException(sprintf('Missing required key "%s" in data array', $key));
            }
        }

        return new static(
            UserId::fromString((string) $data['actor_id']),
            UserId::fromString((string) $data['user_id'])
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
        ];
    }

    /**
     * Returns the requesting actor identifier.
     */
    public function getActorId(): UserId
    {
        return $this->actorId;
    }

    /**
     * Returns the target user identifier.
     */
    public function getUserId(): UserId
    {
        return $this->userId;
    }
}
