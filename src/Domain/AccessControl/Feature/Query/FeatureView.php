<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Feature\Query;

use Fight\AccessControl\Domain\AccessControl\Feature\Exception\FeatureStateException;
use Fight\AccessControl\Domain\AccessControl\Feature\Feature;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureId;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureName;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureStatus;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\Common\Domain\Type\Arrayable;

/**
 * Class FeatureView
 *
 * Carries safe editable state and explicitly marks a missing Permission binding.
 */
final readonly class FeatureView implements Arrayable
{
    /**
     * Constructs FeatureView
     */
    public function __construct(
        private FeatureId $featureId,
        private FeatureName $name,
        private FeatureStatus $status,
        private PermissionId $permissionId,
        private ?PermissionName $permissionName,
        private int $revision
    ) {
    }

    /**
     * Creates a safe result retaining the original binding even if its definition is missing
     */
    public static function fromFeature(Feature $feature, ?Permission $permission): self
    {
        if ($permission instanceof Permission && !$permission->getId()->equals($feature->getPermissionId())) {
            throw new FeatureStateException('The selected Permission does not match the Feature binding.');
        }

        return new self(
            $feature->getId(),
            $feature->getName(),
            $feature->getStatus(),
            $feature->getPermissionId(),
            $permission?->getName(),
            $feature->getRevision()
        );
    }

    /**
     * Returns the Feature identity
     */
    public function getFeatureId(): FeatureId
    {
        return $this->featureId;
    }

    /**
     * Returns the Feature name
     */
    public function getName(): FeatureName
    {
        return $this->name;
    }

    /**
     * Returns the availability setting
     */
    public function getStatus(): FeatureStatus
    {
        return $this->status;
    }

    /**
     * Returns the stored testing Permission identity even when missing
     */
    public function getPermissionId(): PermissionId
    {
        return $this->permissionId;
    }

    /**
     * Returns the display name of a resolved Permission or null if missing
     */
    public function getPermissionName(): ?PermissionName
    {
        return $this->permissionName;
    }

    /**
     * Returns whether the binding is missing
     */
    public function isBindingMissing(): bool
    {
        return $this->permissionName === null;
    }

    /**
     * Returns the current expected-state revision
     */
    public function getRevision(): int
    {
        return $this->revision;
    }

    /**
     * Returns safe management data without an availability verdict
     */
    public function toArray(): array
    {
        return [
            'feature_id'      => $this->featureId->toString(),
            'name'            => $this->name->toString(),
            'status'          => $this->status->value,
            'permission_id'   => $this->permissionId->toString(),
            'permission_name' => $this->permissionName?->toString(),
            'binding_missing' => $this->isBindingMissing(),
            'revision'        => $this->revision
        ];
    }
}
