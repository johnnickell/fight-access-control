<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Role\Command;

use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\Command;

/**
 * Requests removal of an unreferenced custom role.
 */
final readonly class RemoveCustomRole implements Command
{
    /**
     * Creates the custom-role removal command.
     */
    public function __construct(private UserId $actorId, private RoleId $roleId)
    {
    }

    /** @inheritDoc */
    public static function fromArray(array $data): static
    {
        foreach (['actor_id', 'role_id'] as $key) {
            if (!array_key_exists($key, $data)) {
                throw new DomainException(sprintf('Missing required key "%s" in data array', $key));
            }
        }

        return new static(
            UserId::fromString((string) $data['actor_id']),
            RoleId::fromString((string) $data['role_id'])
        );
    }

    /** @inheritDoc */
    public function toArray(): array
    {
        return [
            'actor_id' => $this->actorId->toString(),
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
     * Returns the custom role.
     */
    public function getRoleId(): RoleId
    {
        return $this->roleId;
    }
}
