<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Role\Event;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Event\Event;

/**
 * Records a custom-role permission grant after durable commit.
 */
final readonly class CustomRolePermissionGranted implements Event
{
    /**
     * Creates the permission-granted event.
     */
    public function __construct(
        private UserId $actorId,
        private RoleId $roleId,
        private PermissionId $permissionId,
        private DateTimeImmutable $grantedAt
    ) {
    }

    /** @inheritDoc */
    public static function fromArray(array $data): static
    {
        foreach (['actor_id', 'role_id', 'permission_id', 'granted_at'] as $key) {
            if (!array_key_exists($key, $data)) {
                throw new DomainException(sprintf('Missing required key "%s" in data array', $key));
            }
        }

        return new static(
            UserId::fromString((string) $data['actor_id']),
            RoleId::fromString((string) $data['role_id']),
            PermissionId::fromString((string) $data['permission_id']),
            new DateTimeImmutable((string) $data['granted_at'])
        );
    }

    /** @inheritDoc */
    public function toArray(): array
    {
        return [
            'actor_id' => $this->actorId->toString(),
            'role_id' => $this->roleId->toString(),
            'permission_id' => $this->permissionId->toString(),
            'granted_at' => $this->grantedAt->format(DATE_ATOM),
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
     * Returns the changed role.
     */
    public function getRoleId(): RoleId
    {
        return $this->roleId;
    }

    /**
     * Returns the granted permission.
     */
    public function getPermissionId(): PermissionId
    {
        return $this->permissionId;
    }

    /**
     * Returns the grant time.
     */
    public function getGrantedAt(): DateTimeImmutable
    {
        return $this->grantedAt;
    }
}
