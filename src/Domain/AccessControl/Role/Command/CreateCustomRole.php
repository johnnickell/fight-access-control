<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Role\Command;

use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\Role\RoleName;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\Command;

/**
 * Requests creation of an empty runtime-owned custom role.
 */
final readonly class CreateCustomRole implements Command
{
    /**
     * Creates a custom-role creation command.
     */
    public function __construct(
        private UserId $actorId,
        private RoleId $roleId,
        private RoleName $name
    ) {
    }

    /** @inheritDoc */
    public static function fromArray(array $data): static
    {
        foreach (['actor_id', 'role_id', 'name'] as $key) {
            if (!array_key_exists($key, $data)) {
                throw new DomainException(sprintf('Missing required key "%s" in data array', $key));
            }
        }

        return new static(
            UserId::fromString((string) $data['actor_id']),
            RoleId::fromString((string) $data['role_id']),
            RoleName::fromString((string) $data['name'])
        );
    }

    /** @inheritDoc */
    public function toArray(): array
    {
        return [
            'actor_id' => $this->actorId->toString(),
            'role_id' => $this->roleId->toString(),
            'name' => $this->name->toString(),
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
     * Returns the caller-supplied stable role identifier.
     */
    public function getRoleId(): RoleId
    {
        return $this->roleId;
    }

    /**
     * Returns the custom role name.
     */
    public function getName(): RoleName
    {
        return $this->name;
    }
}
