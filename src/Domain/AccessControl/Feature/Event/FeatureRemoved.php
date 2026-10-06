<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Feature\Event;

use Fight\AccessControl\Domain\AccessControl\Feature\FeatureId;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureName;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Event\Event;

/**
 * Class FeatureRemoved
 *
 * Records the identity and name of a committed Feature retirement.
 */
final readonly class FeatureRemoved implements Event
{
    /**
     * Constructs FeatureRemoved
     */
    public function __construct(private FeatureId $featureId, private FeatureName $name)
    {
    }

    /**
     * @inheritDoc
     */
    public static function fromArray(array $data): static
    {
        if (
            !isset($data['feature_id'], $data['name']) || !is_string($data['feature_id']) || !is_string($data['name'])
        ) {
            throw new DomainException('Missing or invalid Feature removal fact.');
        }

        return new self(FeatureId::fromString($data['feature_id']), FeatureName::fromString($data['name']));
    }

    /**
     * @inheritDoc
     */
    public function toArray(): array
    {
        return ['feature_id' => $this->featureId->toString(), 'name' => $this->name->toString()];
    }

    /**
     * Returns the removed Feature identity
     */
    public function getFeatureId(): FeatureId
    {
        return $this->featureId;
    }

    /**
     * Returns the removed Feature name
     */
    public function getName(): FeatureName
    {
        return $this->name;
    }
}
