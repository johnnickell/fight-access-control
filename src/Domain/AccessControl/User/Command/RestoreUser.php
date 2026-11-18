<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\User\Command;

use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\Command;

/**
 * Class RestoreUser
 *
 * Requests restoration of a deleted identity to active or pending activation.
 */
final readonly class RestoreUser implements Command
{
    /**
     * Constructs RestoreUser
     *
     * Creates a user-restore command.
     */
    public function __construct(
        private UserId $actorId,
        private UserId $userId,
        private UserState $restorationState
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function fromArray(array $data): static
    {
        foreach (['actor_id', 'user_id', 'restoration_state'] as $key) {
            if (!array_key_exists($key, $data) || !is_string($data[$key])) {
                throw new DomainException(sprintf('Missing or invalid string "%s" in data array', $key));
            }
        }

        return new static(
            UserId::fromString($data['actor_id']),
            UserId::fromString($data['user_id']),
            UserState::from($data['restoration_state'])
        );
    }

    /**
     * Returns the canonical restoration payload
     *
     * @phpstan-return array{actor_id: string, user_id: string, restoration_state: value-of<UserState>}
     */
    public function toArray(): array
    {
        return [
            'actor_id'          => $this->actorId->toString(),
            'user_id'           => $this->userId->toString(),
            'restoration_state' => $this->restorationState->value
        ];
    }

    /**
     * Returns the administrative actor
     */
    public function getActorId(): UserId
    {
        return $this->actorId;
    }

    /**
     * Returns the target user
     */
    public function getUserId(): UserId
    {
        return $this->userId;
    }

    /**
     * Returns the chosen restoration target state
     */
    public function getRestorationState(): UserState
    {
        return $this->restorationState;
    }
}
