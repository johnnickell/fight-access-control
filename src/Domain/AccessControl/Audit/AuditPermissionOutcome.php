<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Audit;

use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Type\Arrayable;

/**
 * Class AuditPermissionOutcome
 *
 * @phpstan-import-type PermissionStatePayload from AuditPermissionState
 * @phpstan-type PermissionOutcomePayload array{before: ?PermissionStatePayload, after: ?PermissionStatePayload}
 */
final readonly class AuditPermissionOutcome implements Arrayable
{
    /**
     * Constructs AuditPermissionOutcome
     */
    public function __construct(private ?AuditPermissionState $before, private ?AuditPermissionState $after)
    {
        if ($before === null && $after === null) {
            throw new DomainException('Audit Permission outcome requires an existing definition.');
        }

        if ($before !== null && $after !== null && $before->toArray() === $after->toArray()) {
            throw new DomainException('An unchanged Permission definition is not a mutation outcome.');
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
            throw new DomainException('Audit Permission outcome requires explicit nullable definitions.');
        }

        return new self(
            $data['before'] === null ? null : AuditPermissionState::fromArray($data['before']),
            $data['after'] === null ? null : AuditPermissionState::fromArray($data['after'])
        );
    }

    /**
     * Returns the actual predecessor or absence on creation
     */
    public function getBefore(): ?AuditPermissionState
    {
        return $this->before;
    }

    /**
     * Returns the actual successor or absence on removal
     */
    public function getAfter(): ?AuditPermissionState
    {
        return $this->after;
    }

    /**
     * Returns admitted definition facts without fabricated revisions
     *
     * @phpstan-return PermissionOutcomePayload
     */
    public function toArray(): array
    {
        return ['before' => $this->before?->toArray(), 'after' => $this->after?->toArray()];
    }
}
