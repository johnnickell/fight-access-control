<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Permission\Query;

use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Query\Query;

/**
 * Queries one permission identity by its stable identifier.
 */
final readonly class GetPermissionById implements Query
{
    /**
     * Constructs the permission-identity query.
     */
    public function __construct(private PermissionId $permissionId)
    {
    }

    /**
     * @inheritDoc
     */
    public static function fromArray(array $data): static
    {
        if (!array_key_exists('permission_id', $data)) {
            throw new DomainException('Missing required key "permission_id" in data array');
        }

        return new static(PermissionId::fromString((string) $data['permission_id']));
    }

    /**
     * @inheritDoc
     */
    public function toArray(): array
    {
        return ['permission_id' => $this->permissionId->toString()];
    }

    /**
     * Returns the stable permission identifier.
     */
    public function getPermissionId(): PermissionId
    {
        return $this->permissionId;
    }
}
