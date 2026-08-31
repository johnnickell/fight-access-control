<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\ManagedPolicy\Query;

use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\ManagedPolicy;
use Fight\Common\Domain\Messaging\Query\Query;

/**
 * Requests a deterministic dry-run of managed authorization definitions.
 */
final readonly class PreviewManagedPolicy implements Query
{
    /**
     * Constructs a managed-policy preview query.
     */
    public function __construct(private ManagedPolicy $policy)
    {
    }

    /** @inheritDoc */
    public static function fromArray(array $data): static
    {
        return new static(ManagedPolicy::fromArray($data));
    }

    /**
     * Returns the complete desired managed policy.
     */
    public function getPolicy(): ManagedPolicy
    {
        return $this->policy;
    }

    /** @inheritDoc */
    public function toArray(): array
    {
        return $this->policy->toArray();
    }
}
