<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Role\Event;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Event\Event;

/**
 * Class CustomRoleRemoved
 *
 * Records custom-role removal after durable commit.
 */
final readonly class CustomRoleRemoved implements Event
{
    /**
     * Constructs CustomRoleRemoved
     *
     * Creates the custom-role-removed event.
     */
    public function __construct(
        private UserId $actorId,
        private RoleId $roleId,
        private DateTimeImmutable $removedAt
    ) {
    }

    /** @inheritDoc */
    public static function fromArray(array $data): static
    {
        foreach (['actor_id', 'role_id', 'removed_at'] as $key) {
            if (!array_key_exists($key, $data)) {
                throw new DomainException(sprintf('Missing required key "%s" in data array', $key));
            }

            if (!is_string($data[$key]) || $data[$key] === '') {
                throw new DomainException(sprintf('Required key "%s" must be a nonempty string.', $key));
            }
        }

        return new static(
            UserId::fromString($data['actor_id']),
            RoleId::fromString($data['role_id']),
            new DateTimeImmutable($data['removed_at'])
        );
    }

    /**
     * Returns the canonical event payload
     *
     * @return array{actor_id: string, role_id: string, removed_at: string}
     */
    public function toArray(): array
    {
        return [
            'actor_id'   => $this->actorId->toString(),
            'role_id'    => $this->roleId->toString(),
            'removed_at' => $this->removedAt->format(DATE_ATOM)
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
     * Returns the removed role
     */
    public function getRoleId(): RoleId
    {
        return $this->roleId;
    }

    /**
     * Returns the removal time
     */
    public function getRemovedAt(): DateTimeImmutable
    {
        return $this->removedAt;
    }
}
