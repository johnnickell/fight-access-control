<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Feature\Command;

use Fight\AccessControl\Domain\AccessControl\Feature\Exception\FeatureRevisionException;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureId;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureStatus;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\Command;

/**
 * Class SetFeatureStatus
 *
 * Selects availability against an expected revision.
 */
final readonly class SetFeatureStatus implements Command
{
    /**
     * Constructs SetFeatureStatus
     */
    public function __construct(
        private FeatureId $featureId,
        private FeatureStatus $status,
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
            !isset($data['feature_id'], $data['status'], $data['expected_revision'])
            || !is_string($data['feature_id']) || !is_string($data['status'])
            || !is_int($data['expected_revision'])
        ) {
            throw new DomainException('Missing or invalid Feature status edit data.');
        }

        return new self(
            FeatureId::fromString($data['feature_id']),
            FeatureStatus::from($data['status']),
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
            'status'            => $this->status->value,
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
     * Returns the selected availability setting
     */
    public function getStatus(): FeatureStatus
    {
        return $this->status;
    }

    /**
     * Returns the required current revision
     */
    public function getExpectedRevision(): int
    {
        return $this->expectedRevision;
    }
}
