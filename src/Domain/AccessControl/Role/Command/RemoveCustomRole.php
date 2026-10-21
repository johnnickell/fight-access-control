<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Role\Command;

use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\Role\RoleRevision;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\Command;

/**
 * Class RemoveCustomRole
 *
 * Requests removal of an unreferenced custom role.
 */
final readonly class RemoveCustomRole implements Command
{
    /**
     * Constructs RemoveCustomRole
     *
     * Creates the custom-role removal command.
     */
    public function __construct(
        private UserId $actorId,
        private RoleId $roleId,
        private RoleRevision $expectedRevision
    ) {
    }

    /** @inheritDoc */
    public static function fromArray(array $data): static
    {
        foreach (['actor_id', 'role_id', 'expected_revision'] as $key) {
            if (!array_key_exists($key, $data)) {
                throw new DomainException(sprintf('Missing required key "%s" in data array', $key));
            }
        }

        return new static(
            UserId::fromString((string) $data['actor_id']),
            RoleId::fromString((string) $data['role_id']),
            RoleRevision::fromString($data['expected_revision'])
        );
    }

    /** @inheritDoc */
    public function toArray(): array
    {
        return [
            'actor_id'          => $this->actorId->toString(),
            'role_id'           => $this->roleId->toString(),
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
     * Returns the custom role
     */
    public function getRoleId(): RoleId
    {
        return $this->roleId;
    }
}
