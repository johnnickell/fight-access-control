<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Audit;

use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Type\Arrayable;

/**
 * Class AuditAgentNameOutcome
 *
 * Admits only a real persisted name change, without names, snapshots or invented revisions.
 *
 * @phpstan-type AgentNameOutcomePayload array{name_changed: true}
 */
final readonly class AuditAgentNameOutcome implements Arrayable
{
    /**
     * Constructs AuditAgentNameOutcome
     */
    public function __construct()
    {
    }

    /**
     * Creates facts from their complete persisted shape
     *
     * @phpstan-param array<array-key, mixed> $data
     *
     * @throws DomainException When facts do not describe exactly a real name change
     */
    public static function fromArray(array $data): self
    {
        if ($data !== ['name_changed' => true]) {
            throw new DomainException('Audit Agent name facts require only a confirmed name change.');
        }

        return new self();
    }

    /**
     * Returns the bounded name-change fact without personal text or unrelated authority
     *
     * @phpstan-return AgentNameOutcomePayload
     */
    public function toArray(): array
    {
        return ['name_changed' => true];
    }
}
