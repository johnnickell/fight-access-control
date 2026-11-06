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
 * Class CustomRolePermissionRevoked
 *
 * Records a custom-role permission revocation after durable commit.
 */
final readonly class CustomRolePermissionRevoked implements Event
{
    /**
     * Constructs CustomRolePermissionRevoked
     *
     * Creates the permission-revoked event.
     */
    public function __construct(
        private UserId $actorId,
        private RoleId $roleId,
        private PermissionId $permissionId,
        private DateTimeImmutable $revokedAt
    ) {
    }

    /** @inheritDoc */
    public static function fromArray(array $data): static
    {
        foreach (['actor_id', 'role_id', 'permission_id', 'revoked_at'] as $key) {
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
            PermissionId::fromString($data['permission_id']),
            new DateTimeImmutable($data['revoked_at'])
        );
    }

    /**
     * Returns the canonical event payload
     *
     * @return array{actor_id: string, role_id: string, permission_id: string, revoked_at: string}
     */
    public function toArray(): array
    {
        return [
            'actor_id'      => $this->actorId->toString(),
            'role_id'       => $this->roleId->toString(),
            'permission_id' => $this->permissionId->toString(),
            'revoked_at'    => $this->revokedAt->format(DATE_ATOM)
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
     * Returns the changed role
     */
    public function getRoleId(): RoleId
    {
        return $this->roleId;
    }

    /**
     * Returns the revoked permission
     */
    public function getPermissionId(): PermissionId
    {
        return $this->permissionId;
    }

    /**
     * Returns the revocation time
     */
    public function getRevokedAt(): DateTimeImmutable
    {
        return $this->revokedAt;
    }
}
