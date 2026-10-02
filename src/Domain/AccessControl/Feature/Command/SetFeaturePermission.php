<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Feature\Command;

use Fight\AccessControl\Domain\AccessControl\Feature\Exception\FeatureRevisionException;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\Command;

/**
 * Class SetFeaturePermission
 *
 * Selects a testing Permission against an expected revision.
 */
final readonly class SetFeaturePermission implements Command
{
    /**
     * Constructs SetFeaturePermission
     */
    public function __construct(
        private FeatureId $featureId,
        private PermissionId $permissionId,
        private int $expectedRevision
    ) {
        if ($expectedRevision < 1) {
            throw new FeatureRevisionException('The expected Feature revision must be positive.');
        }
    }

    /**
     * @inheritDoc
     */
    public static function fromArray(array $data): static
    {
        if (
            !isset($data['feature_id'], $data['permission_id'], $data['expected_revision'])
            || !is_string($data['feature_id']) || !is_string($data['permission_id'])
            || !is_int($data['expected_revision'])
        ) {
            throw new DomainException('Missing or invalid Feature Permission edit data.');
        }

        return new self(
            FeatureId::fromString($data['feature_id']),
            PermissionId::fromString($data['permission_id']),
            $data['expected_revision']
        );
    }

    /**
     * @inheritDoc
     */
    public function toArray(): array
    {
        return [
            'feature_id'        => $this->featureId->toString(),
            'permission_id'     => $this->permissionId->toString(),
            'expected_revision' => $this->expectedRevision
        ];
    }

    /**
     * Returns the target Feature identity
     */
    public function getFeatureId(): FeatureId
    {
        return $this->featureId;
    }

    /**
     * Returns the selected testing Permission identity
     */
    public function getPermissionId(): PermissionId
    {
        return $this->permissionId;
    }

    /**
     * Returns the required current revision
     */
    public function getExpectedRevision(): int
    {
        return $this->expectedRevision;
    }
}
