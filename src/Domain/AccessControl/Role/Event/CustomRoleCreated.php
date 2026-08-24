<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Role\Event;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\Role\RoleName;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Event\Event;

/**
 * Records a custom role creation after durable commit.
 */
final readonly class CustomRoleCreated implements Event
{
    /**
     * Creates a custom-role-created event.
     */
    public function __construct(
        private UserId $actorId,
        private RoleId $roleId,
        private RoleName $name,
        private DateTimeImmutable $createdAt
    ) {
    }

    /** @inheritDoc */
    public static function fromArray(array $data): static
    {
        foreach (['actor_id', 'role_id', 'name', 'created_at'] as $key) {
            if (!array_key_exists($key, $data)) {
                throw new DomainException(sprintf('Missing required key "%s" in data array', $key));
            }
        }

        return new static(
            UserId::fromString((string) $data['actor_id']),
            RoleId::fromString((string) $data['role_id']),
            RoleName::fromString((string) $data['name']),
            new DateTimeImmutable((string) $data['created_at'])
        );
    }

    /** @inheritDoc */
    public function toArray(): array
    {
        return [
            'actor_id' => $this->actorId->toString(),
            'role_id' => $this->roleId->toString(),
            'name' => $this->name->toString(),
            'created_at' => $this->createdAt->format(DATE_ATOM),
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
     * Returns the created role.
     */
    public function getRoleId(): RoleId
    {
        return $this->roleId;
    }

    /**
     * Returns the created role name.
     */
    public function getName(): RoleName
    {
        return $this->name;
    }

    /**
     * Returns the creation time.
     */
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
