<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Role\Command;

use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\Role\RoleName;
use Fight\AccessControl\Domain\AccessControl\Role\RoleRevision;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\Command;

/**
 * Class RenameCustomRole
 *
 * Requests a runtime-owned custom-role rename.
 */
final readonly class RenameCustomRole implements Command
{
    /**
     * Constructs RenameCustomRole
     *
     * Creates a custom-role rename command.
     */
    public function __construct(
        private UserId $actorId,
        private RoleId $roleId,
        private RoleName $name,
        private RoleRevision $expectedRevision
    ) {
    }

    /** @inheritDoc */
    public static function fromArray(array $data): static
    {
        foreach (['actor_id', 'role_id', 'name', 'expected_revision'] as $key) {
            if (!array_key_exists($key, $data)) {
                throw new DomainException(sprintf('Missing required key "%s" in data array', $key));
            }

            if ($key !== 'expected_revision' && (!is_string($data[$key]) || $data[$key] === '')) {
                throw new DomainException(sprintf('Required key "%s" must be a nonempty string.', $key));
            }
        }

        return new static(
            UserId::fromString($data['actor_id']),
            RoleId::fromString($data['role_id']),
            RoleName::fromString($data['name']),
            RoleRevision::fromString($data['expected_revision'])
        );
    }

    /**
     * Returns the canonical command payload
     *
     * @return array{actor_id: string, role_id: string, name: string, expected_revision: string}
     */
    public function toArray(): array
    {
        return [
            'actor_id'          => $this->actorId->toString(),
            'role_id'           => $this->roleId->toString(),
            'name'              => $this->name->toString(),
            'expected_revision' => $this->expectedRevision->toString()
        ];
    }

    /**
     * Returns the revision of the Role displayed to the caller
     */
    public function getExpectedRevision(): RoleRevision
    {
        return $this->expectedRevision;
    }

    /**
     * Returns the administrative actor
     */
    public function getActorId(): UserId
    {
        return $this->actorId;
    }

    /**
     * Returns the stable role identifier
     */
    public function getRoleId(): RoleId
    {
        return $this->roleId;
    }

    /**
     * Returns the replacement custom role name
     */
    public function getName(): RoleName
    {
        return $this->name;
    }
}
