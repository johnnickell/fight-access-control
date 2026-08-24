<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\User\Event;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Event\Event;

/**
 * Records a User role removal after durable commit.
 */
final readonly class RoleRemovedFromUser implements Event
{
    /**
     * Creates the role-removed event.
     */
    public function __construct(
        private UserId $actorId,
        private UserId $targetUserId,
        private RoleId $roleId,
        private DateTimeImmutable $removedAt
    ) {
    }

    /** @inheritDoc */
    public static function fromArray(array $data): static
    {
        foreach (['actor_id', 'target_user_id', 'role_id', 'removed_at'] as $key) {
            if (!array_key_exists($key, $data)) {
                throw new DomainException(sprintf('Missing required key "%s" in data array', $key));
            }
        }

        return new static(
            UserId::fromString((string) $data['actor_id']),
            UserId::fromString((string) $data['target_user_id']),
            RoleId::fromString((string) $data['role_id']),
            new DateTimeImmutable((string) $data['removed_at'])
        );
    }

    /** @inheritDoc */
    public function toArray(): array
    {
        return [
            'actor_id' => $this->actorId->toString(),
            'target_user_id' => $this->targetUserId->toString(),
            'role_id' => $this->roleId->toString(),
            'removed_at' => $this->removedAt->format(DATE_ATOM),
        ];
    }

    /**
     * Returns the administrative actor.
     */
    public function getActorId(): UserId
    {
        return $this->actorId;
    }

    /**
     * Returns the target User.
     */
    public function getTargetUserId(): UserId
    {
        return $this->targetUserId;
    }

    /**
     * Returns the removed role.
     */
    public function getRoleId(): RoleId
    {
        return $this->roleId;
    }

    /**
     * Returns the removal time.
     */
    public function getRemovedAt(): DateTimeImmutable
    {
        return $this->removedAt;
    }
}
