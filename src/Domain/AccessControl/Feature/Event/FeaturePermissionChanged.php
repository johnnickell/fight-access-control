<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Feature\Event;

use Fight\AccessControl\Domain\AccessControl\Feature\FeatureId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Event\Event;

/**
 * Class FeaturePermissionChanged
 *
 * Records a committed testing binding, not a principal grant.
 */
final readonly class FeaturePermissionChanged implements Event
{
    /**
     * Constructs FeaturePermissionChanged
     */
    public function __construct(private FeatureId $featureId, private PermissionId $permissionId, private int $revision)
    {
        if ($revision < 2) {
            throw new DomainException('A changed Feature revision must exceed one.');
        }
    }

    /**
     * @inheritDoc
     */
    public static function fromArray(array $data): static
    {
        if (
            !isset($data['feature_id'], $data['permission_id'], $data['revision'])
            || !is_string($data['feature_id']) || !is_string($data['permission_id']) || !is_int($data['revision'])
        ) {
            throw new DomainException('Missing or invalid Feature binding fact.');
        }

        return new self(
            FeatureId::fromString($data['feature_id']),
            PermissionId::fromString($data['permission_id']),
            $data['revision']
        );
    }

    /**
     * @inheritDoc
     */
    public function toArray(): array
    {
        return [
            'feature_id'    => $this->featureId->toString(),
            'permission_id' => $this->permissionId->toString(),
            'revision'      => $this->revision
        ];
    }

    /**
     * Returns the Feature identity
     */
    public function getFeatureId(): FeatureId
    {
        return $this->featureId;
    }

    /**
     * Returns the committed binding
     */
    public function getPermissionId(): PermissionId
    {
        return $this->permissionId;
    }

    /**
     * Returns the committed revision
     */
    public function getRevision(): int
    {
        return $this->revision;
    }
}
