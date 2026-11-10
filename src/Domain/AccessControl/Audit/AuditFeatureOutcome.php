<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Audit;

use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Type\Arrayable;

/**
 * Class AuditFeatureOutcome
 *
 * @phpstan-import-type FeatureStatePayload from AuditFeatureState
 * @phpstan-type FeatureOutcomePayload array{before: ?FeatureStatePayload, after: ?FeatureStatePayload}
 */
final readonly class AuditFeatureOutcome implements Arrayable
{
    /**
     * Constructs AuditFeatureOutcome
     */
    public function __construct(private ?AuditFeatureState $before, private ?AuditFeatureState $after)
    {
        if ($before === null && $after === null) {
            throw new DomainException('Audit Feature outcome requires an existing state.');
        }

        if (
            $before !== null && $after !== null
            && ($before->getRevision() === PHP_INT_MAX || $after->getRevision() !== $before->getRevision() + 1)
        ) {
            throw new DomainException('Audit Feature changes advance one authoritative revision.');
        }
    }

    /**
     * Creates the exact current persistence representation
     *
     * @phpstan-param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        if (
            count($data) !== 2 || !array_key_exists('before', $data) || !array_key_exists('after', $data)
            || ($data['before'] !== null && !is_array($data['before']))
            || ($data['after'] !== null && !is_array($data['after']))
        ) {
            throw new DomainException('Audit Feature outcome requires explicit nullable states.');
        }

        return new self(
            $data['before'] === null ? null : AuditFeatureState::fromArray($data['before']),
            $data['after'] === null ? null : AuditFeatureState::fromArray($data['after'])
        );
    }

    /**
     * Returns the actual predecessor or absence on creation
     */
    public function getBefore(): ?AuditFeatureState
    {
        return $this->before;
    }

    /**
     * Returns the actual successor or absence on removal
     */
    public function getAfter(): ?AuditFeatureState
    {
        return $this->after;
    }

    /**
     * Returns bounded Feature facts
     *
     * @phpstan-return FeatureOutcomePayload
     */
    public function toArray(): array
    {
        return ['before' => $this->before?->toArray(), 'after' => $this->after?->toArray()];
    }
}
