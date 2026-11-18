<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Audit;

use Fight\AccessControl\Domain\AccessControl\Feature\Feature;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureStatus;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Type\Arrayable;

/**
 * Class AuditFeatureState
 *
 * @phpstan-type FeatureStatePayload array{revision: int, status: string, permission_id: string}
 */
final readonly class AuditFeatureState implements Arrayable
{
    /**
     * Constructs AuditFeatureState
     */
    public function __construct(
        private int $revision,
        private FeatureStatus $status,
        private PermissionId $permissionId
    ) {
        if ($revision < 1) {
            throw new DomainException('Audit Feature revision must be positive.');
        }
    }

    /**
     * Creates bounded facts from authoritative Feature state
     */
    public static function fromFeature(Feature $feature): self
    {
        return new self($feature->getRevision(), $feature->getStatus(), $feature->getPermissionId());
    }

    /**
     * Creates the exact current persistence representation
     *
     * @phpstan-param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        if (
            count($data) !== 3 || !isset($data['revision'], $data['status'], $data['permission_id'])
            || !is_int($data['revision']) || !is_string($data['status']) || !is_string($data['permission_id'])
        ) {
            throw new DomainException('Audit Feature state requires its complete current representation.');
        }

        return new self(
            $data['revision'],
            FeatureStatus::from($data['status']),
            PermissionId::fromString($data['permission_id'])
        );
    }

    /**
     * Returns the authoritative revision
     */
    public function getRevision(): int
    {
        return $this->revision;
    }

    /**
     * Returns bounded Feature facts and its related Permission
     *
     * @phpstan-return FeatureStatePayload
     */
    public function toArray(): array
    {
        return [
            'revision'      => $this->revision,
            'status'        => $this->status->value,
            'permission_id' => $this->permissionId->toString()
        ];
    }
}
