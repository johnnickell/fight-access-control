<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\User\Event;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryTimestamp;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Event\Event;

/**
 * Class RoleAssignedToUser
 *
 * Records a User role assignment after durable commit.
 */
final readonly class RoleAssignedToUser implements Event
{
    /**
     * Constructs RoleAssignedToUser
     *
     * Creates the role-assigned event.
     */
    public function __construct(
        private UserId $actorId,
        private UserId $targetUserId,
        private RoleId $roleId,
        private DateTimeImmutable $assignedAt
    ) {
    }

    /** @inheritDoc */
    public static function fromArray(array $data): static
    {
        foreach (['actor_id', 'target_user_id', 'role_id', 'assigned_at'] as $key) {
            if (!array_key_exists($key, $data) || !is_string($data[$key])) {
                throw new DomainException(sprintf('Missing or invalid string "%s" in data array', $key));
            }
        }

        return new static(
            UserId::fromString($data['actor_id']),
            UserId::fromString($data['target_user_id']),
            RoleId::fromString($data['role_id']),
            CredentialDeliveryTimestamp::fromString($data['assigned_at'])->toDateTimeImmutable()
        );
    }

    /**
     * Returns the canonical role-assignment fact
     *
     * @phpstan-return array{actor_id: string, target_user_id: string, role_id: string, assigned_at: string}
     */
    public function toArray(): array
    {
        return [
            'actor_id'       => $this->actorId->toString(),
            'target_user_id' => $this->targetUserId->toString(),
            'role_id'        => $this->roleId->toString(),
            'assigned_at'    => $this->assignedAt->format(DATE_ATOM)
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
     * Returns the target User
     */
    public function getTargetUserId(): UserId
    {
        return $this->targetUserId;
    }

    /**
     * Returns the assigned role
     */
    public function getRoleId(): RoleId
    {
        return $this->roleId;
    }

    /**
     * Returns the assignment time
     */
    public function getAssignedAt(): DateTimeImmutable
    {
        return $this->assignedAt;
    }
}
