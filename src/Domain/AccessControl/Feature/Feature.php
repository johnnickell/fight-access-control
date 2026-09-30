<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Feature;

use Fight\AccessControl\Domain\AccessControl\Feature\Exception\FeatureStateException;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;

/**
 * Class Feature
 *
 * Owns the immutable identity and name, stored testing Permission and availability setting.
 *
 * @phpstan-consistent-constructor
 */
class Feature
{
    /**
     * Constructs Feature
     */
    protected function __construct(
        private readonly FeatureId $id,
        private readonly FeatureName $name,
        private readonly PermissionId $permissionId,
        private readonly FeatureStatus $status,
        private readonly int $revision
    ) {
        if ($revision < 1) {
            throw new FeatureStateException('A Feature revision must be positive.');
        }
    }

    /**
     * Creates an OFF Feature with its initial revision
     *
     * Persistence must enforce name uniqueness and the existence of the referenced Permission through commit.
     */
    public static function define(FeatureId $id, FeatureName $name, PermissionId $permissionId): static
    {
        return new static($id, $name, $permissionId, FeatureStatus::OFF, 1);
    }

    /**
     * Reconstitutes authoritative persisted state without resetting operator choices
     *
     * Hydration preserves a missing Permission's identity; it does not certify activation readiness or repair data.
     * This is an adapter boundary, not a management transition or permission to replace stored state.
     */
    public static function reconstitute(
        FeatureId $id,
        FeatureName $name,
        PermissionId $permissionId,
        FeatureStatus $status,
        int $revision
    ): static {
        return new static($id, $name, $permissionId, $status, $revision);
    }

    /**
     * Returns the stable Feature identity
     */
    public function getId(): FeatureId
    {
        return $this->id;
    }

    /**
     * Returns the immutable Feature name
     */
    public function getName(): FeatureName
    {
        return $this->name;
    }

    /**
     * Returns the stored testing Permission identity
     */
    public function getPermissionId(): PermissionId
    {
        return $this->permissionId;
    }

    /**
     * Returns the stored availability setting
     */
    public function getStatus(): FeatureStatus
    {
        return $this->status;
    }

    /**
     * Returns the stored optimistic concurrency revision
     */
    public function getRevision(): int
    {
        return $this->revision;
    }
}
