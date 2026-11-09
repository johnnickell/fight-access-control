<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Audit;

use Fight\AccessControl\Domain\AccessControl\Role\RoleRevision;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Type\Arrayable;

/**
 * Class AuditRoleRevisions
 *
 * Preserves the complete Role incarnation and revision, including absent creation/removal state.
 *
 * @phpstan-type RevisionsPayload array{before: ?string, after: ?string}
 */
final readonly class AuditRoleRevisions implements Arrayable
{
    /**
     * Constructs AuditRoleRevisions
     *
     * @throws DomainException When the values do not describe a Role creation, change or removal
     */
    public function __construct(private ?RoleRevision $before, private ?RoleRevision $after)
    {
        if ($before === null && $after === null) {
            throw new DomainException('Audit Role revisions require an existing state.');
        }

        if ($before !== null && $after !== null && !$before->next()->equals($after)) {
            throw new DomainException('Audit Role change must retain incarnation and advance one revision.');
        }
    }

    /**
     * Creates complete Role revisions without generating a missing incarnation or state
     *
     * @phpstan-param array<array-key, mixed> $data
     *
     * @throws DomainException When the representation is incomplete or invalid
     */
    public static function fromArray(array $data): self
    {
        if (
            count($data) !== 2
            || !array_key_exists('before', $data)
            || !array_key_exists('after', $data)
            || ($data['before'] !== null && !is_string($data['before']))
            || ($data['after'] !== null && !is_string($data['after']))
        ) {
            throw new DomainException('Audit Role revisions require explicit before and after states.');
        }

        return new self(
            $data['before'] === null ? null : RoleRevision::fromString($data['before']),
            $data['after'] === null ? null : RoleRevision::fromString($data['after'])
        );
    }

    /**
     * Returns the authoritative predecessor or its explicit absence on creation
     */
    public function getBefore(): ?RoleRevision
    {
        return $this->before;
    }

    /**
     * Returns the authoritative successor or its explicit absence on removal
     */
    public function getAfter(): ?RoleRevision
    {
        return $this->after;
    }

    /**
     * Returns complete revision values without inventing creation/removal state
     *
     * @phpstan-return RevisionsPayload
     */
    public function toArray(): array
    {
        return ['before' => $this->before?->toString(), 'after' => $this->after?->toString()];
    }
}
