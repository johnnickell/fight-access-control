<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Feature\Event;

use Fight\AccessControl\Domain\AccessControl\Feature\FeatureId;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureStatus;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Event\Event;

/**
 * Class FeatureStatusChanged
 *
 * Records one committed availability change, not action authorization.
 */
final readonly class FeatureStatusChanged implements Event
{
    /**
     * Constructs FeatureStatusChanged
     */
    public function __construct(private FeatureId $featureId, private FeatureStatus $status, private int $revision)
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
            !isset($data['feature_id'], $data['status'], $data['revision'])
            || !is_string($data['feature_id']) || !is_string($data['status']) || !is_int($data['revision'])
        ) {
            throw new DomainException('Missing or invalid Feature status fact.');
        }

        return new self(
            FeatureId::fromString($data['feature_id']),
            FeatureStatus::from($data['status']),
            $data['revision']
        );
    }

    /**
     * @inheritDoc
     */
    public function toArray(): array
    {
        return [
            'feature_id' => $this->featureId->toString(),
            'status'     => $this->status->value,
            'revision'   => $this->revision
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
     * Returns the committed setting
     */
    public function getStatus(): FeatureStatus
    {
        return $this->status;
    }

    /**
     * Returns the committed revision
     */
    public function getRevision(): int
    {
        return $this->revision;
    }
}
