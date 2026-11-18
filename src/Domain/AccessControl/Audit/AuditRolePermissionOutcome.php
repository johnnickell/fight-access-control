<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Audit;

use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Type\Arrayable;

/**
 * Class AuditRolePermissionOutcome
 *
 * @phpstan-import-type RevisionsPayload from AuditRoleRevisions
 * @phpstan-type RolePermissionPayload array{revisions: RevisionsPayload, permission_id: string}
 */
final readonly class AuditRolePermissionOutcome implements Arrayable
{
    /**
     * Constructs AuditRolePermissionOutcome
     */
    public function __construct(private AuditRoleRevisions $revisions, private PermissionId $permissionId)
    {
        if ($revisions->getBefore() === null || $revisions->getAfter() === null) {
            throw new DomainException('A Role Permission mutation requires both authoritative Role revisions.');
        }
    }

    /**
     * Creates the complete current persistence representation
     *
     * @phpstan-param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        if (
            count($data) !== 2 || !isset($data['revisions'], $data['permission_id'])
            || !is_array($data['revisions']) || !is_string($data['permission_id'])
        ) {
            throw new DomainException('Audit Role Permission outcome requires its complete current representation.');
        }

        return new self(
            AuditRoleRevisions::fromArray($data['revisions']),
            PermissionId::fromString($data['permission_id'])
        );
    }

    /**
     * Returns complete Role incarnation and revision facts
     */
    public function getRevisions(): AuditRoleRevisions
    {
        return $this->revisions;
    }

    /**
     * Returns the related Permission identity
     */
    public function getPermissionId(): PermissionId
    {
        return $this->permissionId;
    }

    /**
     * Returns bounded relationship and revision facts
     *
     * @phpstan-return RolePermissionPayload
     */
    public function toArray(): array
    {
        return ['revisions' => $this->revisions->toArray(), 'permission_id' => $this->permissionId->toString()];
    }
}
