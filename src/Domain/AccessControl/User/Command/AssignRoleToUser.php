<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\User\Command;

use Fight\AccessControl\Domain\AccessControl\Audit\SystemId;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\User\RoleAssignmentActor;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\Command;

/**
 * Class AssignRoleToUser
 *
 * Requests assigning an authoritative role to a User.
 */
final readonly class AssignRoleToUser implements Command
{
    /**
     * Constructs AssignRoleToUser
     *
     * Creates the User role-assignment command.
     */
    public function __construct(
        private UserId|SystemId $actorId,
        private UserId $targetUserId,
        private RoleId $roleId
    ) {
    }

    /** @inheritDoc */
    public static function fromArray(array $data): static
    {
        foreach (['actor_id', 'target_user_id', 'role_id'] as $key) {
            if (!array_key_exists($key, $data) || !is_string($data[$key])) {
                throw new DomainException(sprintf('Missing or invalid string "%s" in data array', $key));
            }
        }

        return new static(
            RoleAssignmentActor::fromArray($data['actor_id'], $data),
            UserId::fromString($data['target_user_id']),
            RoleId::fromString($data['role_id'])
        );
    }

    /**
     * Returns the canonical role-assignment payload
     *
     * @phpstan-return array{actor_id: string, actor_kind?: 'system', target_user_id: string, role_id: string}
     */
    public function toArray(): array
    {
        return RoleAssignmentActor::toArray($this->actorId) + [
            'target_user_id' => $this->targetUserId->toString(),
            'role_id'        => $this->roleId->toString()
        ];
    }

    /**
     * Returns the administrative actor
     */
    public function getActorId(): UserId|SystemId
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
     * Returns the role to assign
     */
    public function getRoleId(): RoleId
    {
        return $this->roleId;
    }
}
