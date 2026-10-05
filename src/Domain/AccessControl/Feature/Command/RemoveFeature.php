<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Feature\Command;

use Fight\AccessControl\Domain\AccessControl\Feature\Exception\FeatureRevisionException;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\Command;

/**
 * Class RemoveFeature
 *
 * Retires a Feature only at its expected revision.
 */
final readonly class RemoveFeature implements Command
{
    /**
     * Constructs RemoveFeature
     */
    public function __construct(private FeatureId $featureId, private int $expectedRevision)
    {
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
            !isset($data['feature_id'], $data['expected_revision'])
            || !is_string($data['feature_id']) || !is_int($data['expected_revision'])
        ) {
            throw new DomainException('Missing or invalid Feature retirement data.');
        }

        return new self(FeatureId::fromString($data['feature_id']), $data['expected_revision']);
    }

    /**
     * @inheritDoc
     */
    public function toArray(): array
    {
        return [
            'feature_id'        => $this->featureId->toString(),
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
     * Returns the expected current revision
     */
    public function getExpectedRevision(): int
    {
        return $this->expectedRevision;
    }
}
