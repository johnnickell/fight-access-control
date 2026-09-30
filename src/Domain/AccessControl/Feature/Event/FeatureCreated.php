<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Feature\Event;

use Fight\AccessControl\Domain\AccessControl\Feature\FeatureId;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureName;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Event\Event;

/**
 * Class FeatureCreated
 *
 * Records committed creation OFF at revision one; establishes no activation readiness or principal grant.
 */
final readonly class FeatureCreated implements Event
{
    /**
     * Constructs FeatureCreated
     */
    public function __construct(
        private FeatureId $featureId,
        private FeatureName $name,
        private PermissionId $permissionId
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function fromArray(array $data): static
    {
        foreach (['feature_id', 'name', 'permission_id'] as $key) {
            if (!isset($data[$key]) || !is_string($data[$key])) {
                throw new DomainException(sprintf('Missing or invalid required string "%s" in data array', $key));
            }
        }

        return new self(
            FeatureId::fromString($data['feature_id']),
            FeatureName::fromString($data['name']),
            PermissionId::fromString($data['permission_id'])
        );
    }

    /**
     * @inheritDoc
     */
    public function toArray(): array
    {
        return [
            'feature_id'    => $this->featureId->toString(),
            'name'          => $this->name->toString(),
            'permission_id' => $this->permissionId->toString()
        ];
    }

    /**
     * Returns the new Feature identity
     */
    public function getFeatureId(): FeatureId
    {
        return $this->featureId;
    }

    /**
     * Returns the immutable Feature name
     */
    public function getName(): FeatureName
    {
        return $this->name;
    }

    /**
     * Returns the selected testing Permission identity
     */
    public function getPermissionId(): PermissionId
    {
        return $this->permissionId;
    }
}
