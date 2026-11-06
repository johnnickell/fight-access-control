<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\ManagedPolicy\Command;

use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\ManagedPolicy;
use Fight\Common\Domain\Messaging\Command\Command;

/**
 * Class ReconcileManagedPolicy
 *
 * Requests atomic reconciliation of version-controlled authorization policy.
 *
 * @phpstan-import-type SerializedPolicy from ManagedPolicy
 */
final readonly class ReconcileManagedPolicy implements Command
{
    /**
     * Constructs ReconcileManagedPolicy
     *
     * Constructs a managed-policy reconciliation command.
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
     * Returns the complete desired managed policy
     */
    public function getPolicy(): ManagedPolicy
    {
        return $this->policy;
    }

    /**
     * Returns the canonical command payload
     *
     * @phpstan-return SerializedPolicy
     */
    public function toArray(): array
    {
        return $this->policy->toArray();
    }
}
