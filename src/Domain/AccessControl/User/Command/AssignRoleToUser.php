<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\User\Command;

use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\Command;

/**
 * Requests assigning an authoritative role to a User.
 */
final readonly class AssignRoleToUser implements Command
{
    /**
     * Creates the User role-assignment command.
     */
    public function __construct(
        private UserId $actorId,
        private UserId $targetUserId,
        private RoleId $roleId
    ) {
    }

    /** @inheritDoc */
    public static function fromArray(array $data): static
    {
        foreach (['actor_id', 'target_user_id', 'role_id'] as $key) {
            if (!array_key_exists($key, $data)) {
                throw new DomainException(sprintf('Missing required key "%s" in data array', $key));
            }
        }

        return new static(
            UserId::fromString((string) $data['actor_id']),
            UserId::fromString((string) $data['target_user_id']),
            RoleId::fromString((string) $data['role_id'])
        );
    }

    /** @inheritDoc */
    public function toArray(): array
    {
        return [
            'actor_id' => $this->actorId->toString(),
            'target_user_id' => $this->targetUserId->toString(),
            'role_id' => $this->roleId->toString(),
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
     * Returns the role to assign.
     */
    public function getRoleId(): RoleId
    {
        return $this->roleId;
    }
}
