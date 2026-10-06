<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Feature\Query;

use Fight\AccessControl\Domain\AccessControl\Feature\FeatureId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Query\Query;

/**
 * Class GetFeatureById
 *
 * Requests one safe management result.
 */
final readonly class GetFeatureById implements Query
{
    /**
     * Constructs GetFeatureById
     */
    public function __construct(private FeatureId $featureId)
    {
    }

    /**
     * @inheritDoc
     */
    public static function fromArray(array $data): static
    {
        if (!isset($data['feature_id']) || !is_string($data['feature_id'])) {
            throw new DomainException('Missing or invalid required Feature ID.');
        }

        return new self(FeatureId::fromString($data['feature_id']));
    }

    /**
     * @inheritDoc
     */
    public function toArray(): array
    {
        return ['feature_id' => $this->featureId->toString()];
    }

    /**
     * Returns the requested Feature identity
     */
    public function getFeatureId(): FeatureId
    {
        return $this->featureId;
    }
}
