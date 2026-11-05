<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\User\Command;

use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\Command;

/**
 * Class RemoveRoleFromUser
 *
 * Requests removing an authoritative role from a User.
 */
final readonly class RemoveRoleFromUser implements Command
{
    /**
     * Constructs RemoveRoleFromUser
     *
     * Creates the User role-removal command.
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
            if (!array_key_exists($key, $data) || !is_string($data[$key])) {
                throw new DomainException(sprintf('Missing or invalid string "%s" in data array', $key));
            }
        }

        return new static(
            UserId::fromString($data['actor_id']),
            UserId::fromString($data['target_user_id']),
            RoleId::fromString($data['role_id'])
        );
    }

    /**
     * Returns the canonical role-removal payload
     *
     * @phpstan-return array{actor_id: string, target_user_id: string, role_id: string}
     */
    public function toArray(): array
    {
        return [
            'actor_id'       => $this->actorId->toString(),
            'target_user_id' => $this->targetUserId->toString(),
            'role_id'        => $this->roleId->toString()
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
     * Returns the role to remove
     */
    public function getRoleId(): RoleId
    {
        return $this->roleId;
    }
}
