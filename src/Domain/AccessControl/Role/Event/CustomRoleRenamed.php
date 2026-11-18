<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Role\Event;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\Role\RoleName;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Event\Event;

/**
 * Class CustomRoleRenamed
 *
 * Records a custom role rename after durable commit.
 */
final readonly class CustomRoleRenamed implements Event
{
    /**
     * Constructs CustomRoleRenamed
     *
     * Creates a custom-role-renamed event.
     */
    public function __construct(
        private UserId $actorId,
        private RoleId $roleId,
        private RoleName $name,
        private DateTimeImmutable $renamedAt
    ) {
    }

    /** @inheritDoc */
    public static function fromArray(array $data): static
    {
        foreach (['actor_id', 'role_id', 'name', 'renamed_at'] as $key) {
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
            RoleName::fromString($data['name']),
            new DateTimeImmutable($data['renamed_at'])
        );
    }

    /**
     * Returns the canonical event payload
     *
     * @return array{actor_id: string, role_id: string, name: string, renamed_at: string}
     */
    public function toArray(): array
    {
        return [
            'actor_id'   => $this->actorId->toString(),
            'role_id'    => $this->roleId->toString(),
            'name'       => $this->name->toString(),
            'renamed_at' => $this->renamedAt->format(DATE_ATOM)
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
     * Returns the renamed role
     */
    public function getRoleId(): RoleId
    {
        return $this->roleId;
    }

    /**
     * Returns the replacement role name
     */
    public function getName(): RoleName
    {
        return $this->name;
    }

    /**
     * Returns the rename time
     */
    public function getRenamedAt(): DateTimeImmutable
    {
        return $this->renamedAt;
    }
}
