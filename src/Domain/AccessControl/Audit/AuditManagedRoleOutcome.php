<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Audit;

use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Type\Arrayable;

/**
 * Class AuditManagedRoleOutcome
 *
 * Preserves complete revisions and membership without capturing names or policy snapshots.
 *
 * @phpstan-import-type RevisionsPayload from AuditRoleRevisions
 * @phpstan-type ManagedRolePayload array{revisions: RevisionsPayload,
 *     before_permission_ids: ?list<string>, after_permission_ids: ?list<string>}
 */
final readonly class AuditManagedRoleOutcome implements Arrayable
{
    /** @var list<PermissionId>|null */
    private ?array $beforePermissionIds;

    /** @var list<PermissionId>|null */
    private ?array $afterPermissionIds;

    /**
     * Constructs AuditManagedRoleOutcome
     *
     * @param AuditRoleRevisions $revisions
     * @param array<array-key, mixed>|null $beforePermissionIds
     * @param array<array-key, mixed>|null $afterPermissionIds
     */
    public function __construct(
        private AuditRoleRevisions $revisions,
        ?array $beforePermissionIds,
        ?array $afterPermissionIds
    ) {
        if (
            ($revisions->getBefore() === null) !== ($beforePermissionIds === null)
            || ($revisions->getAfter() === null) !== ($afterPermissionIds === null)
        ) {
            throw new DomainException('Managed Role relationships must match existing revision states.');
        }

        foreach ([$beforePermissionIds, $afterPermissionIds] as $ids) {
            if ($ids === null) {
                continue;
            }

            if (!array_is_list($ids)) {
                throw new DomainException('Managed Role relationships require a list.');
            }

            $seen = [];
            foreach ($ids as $id) {
                if (!$id instanceof PermissionId || isset($seen[$id->toString()])) {
                    throw new DomainException('Managed Role relationships require unique Permission identities.');
                }

                $seen[$id->toString()] = true;
            }
        }

        /** @var list<PermissionId>|null $beforePermissionIds */
        $this->beforePermissionIds = $beforePermissionIds;
        /** @var list<PermissionId>|null $afterPermissionIds */
        $this->afterPermissionIds = $afterPermissionIds;
    }

    /**
     * Creates the complete current persistence representation
     *
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        if (
            count($data) !== 3 || !isset($data['revisions']) || !is_array($data['revisions'])
            || !array_key_exists('before_permission_ids', $data) || !array_key_exists('after_permission_ids', $data)
        ) {
            throw new DomainException('Managed Role evidence requires complete revision and relationship facts.');
        }

        return new self(
            AuditRoleRevisions::fromArray($data['revisions']),
            self::permissionIds($data['before_permission_ids']),
            self::permissionIds($data['after_permission_ids'])
        );
    }

    /**
     * Returns the complete Role incarnation and revisions
     */
    public function getRevisions(): AuditRoleRevisions
    {
        return $this->revisions;
    }

    /**
     * Returns bounded revision and relationship facts
     *
     * @phpstan-return ManagedRolePayload
     */
    public function toArray(): array
    {
        return [
            'revisions'             => $this->revisions->toArray(),
            'before_permission_ids' => $this->beforePermissionIds === null ? null : array_map(
                static fn(PermissionId $id): string => $id->toString(),
                $this->beforePermissionIds
            ),
            'after_permission_ids'  => $this->afterPermissionIds === null ? null : array_map(
                static fn(PermissionId $id): string => $id->toString(),
                $this->afterPermissionIds
            )
        ];
    }

    /**
     * Creates typed relationships without coercion
     *
     * @return list<PermissionId>|null
     */
    private static function permissionIds(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        if (!is_array($value) || !array_is_list($value)) {
            throw new DomainException('Managed Role relationships require an explicit nullable list.');
        }

        $ids = [];
        foreach ($value as $id) {
            if (!is_string($id)) {
                throw new DomainException('Managed Role relationships require UUID strings.');
            }

            $ids[] = PermissionId::fromString($id);
        }

        return $ids;
    }
}
