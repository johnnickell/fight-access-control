<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Role\Query;

use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Query\Query;

/**
 * Queries one role identity by its stable identifier.
 */
final readonly class GetRoleById implements Query
{
    /**
     * Constructs the role-identity query.
     */
    public function __construct(private RoleId $roleId)
    {
    }

    /**
     * @inheritDoc
     */
    public static function fromArray(array $data): static
    {
        if (!array_key_exists('role_id', $data)) {
            throw new DomainException('Missing required key "role_id" in data array');
        }

        return new static(RoleId::fromString((string) $data['role_id']));
    }

    /**
     * @inheritDoc
     */
    public function toArray(): array
    {
        return ['role_id' => $this->roleId->toString()];
    }

    /**
     * Returns the stable role identifier.
     */
    public function getRoleId(): RoleId
    {
        return $this->roleId;
    }
}
