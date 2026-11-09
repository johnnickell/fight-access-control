<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Audit;

use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Type\Arrayable;

/**
 * Class AuditUserOutcome
 *
 * Carries bounded User revisions and applicable before/after grant relationships.
 *
 * @phpstan-import-type UserRevisionsPayload from AuditUserRevisions
 * @phpstan-import-type GrantStatePayload from AuditGrantState
 * @phpstan-type UserOutcomePayload array{
 *     before: ?UserRevisionsPayload, after: UserRevisionsPayload,
 *     grant_before: ?GrantStatePayload, grant_after: ?GrantStatePayload, replaced_predecessor: ?GrantStatePayload
 * }
 */
final readonly class AuditUserOutcome implements Arrayable
{
    /**
     * Constructs AuditUserOutcome
     */
    public function __construct(
        private ?AuditUserRevisions $before,
        private AuditUserRevisions $after,
        private ?AuditGrantState $grantBefore = null,
        private ?AuditGrantState $grantAfter = null,
        private ?AuditGrantState $replacedPredecessor = null
    ) {
        if ($grantBefore !== null && $grantAfter !== null) {
            $grantBefore->assertReplacement($grantAfter, $replacedPredecessor);
        } elseif ($replacedPredecessor !== null) {
            throw new DomainException('A replaced predecessor requires both grant generations.');
        }
    }

    /**
     * Creates admitted facts from their exact persisted shape
     *
     * @phpstan-param array<array-key, mixed> $data
     *
     * @throws DomainException When the current shape is incomplete or contains unadmitted fields
     */
    public static function fromArray(array $data): self
    {
        if (count($data) !== 5 || !isset($data['after']) || !is_array($data['after'])) {
            throw new DomainException('Audit User outcome requires its complete current representation.');
        }

        foreach (['before', 'grant_before', 'grant_after', 'replaced_predecessor'] as $field) {
            if (!array_key_exists($field, $data) || ($data[$field] !== null && !is_array($data[$field]))) {
                throw new DomainException('Audit User outcome requires explicit nullable states.');
            }
        }

        return new self(
            $data['before'] === null ? null : AuditUserRevisions::fromArray($data['before']),
            AuditUserRevisions::fromArray($data['after']),
            $data['grant_before'] === null ? null : AuditGrantState::fromArray($data['grant_before']),
            $data['grant_after'] === null ? null : AuditGrantState::fromArray($data['grant_after']),
            $data['replaced_predecessor'] === null ? null : AuditGrantState::fromArray($data['replaced_predecessor'])
        );
    }

    /**
     * Returns the authoritative predecessor or absence on User creation
     */
    public function getBefore(): ?AuditUserRevisions
    {
        return $this->before;
    }

    /**
     * Returns the authoritative successor User counters
     */
    public function getAfter(): AuditUserRevisions
    {
        return $this->after;
    }

    /**
     * Returns the actual predecessor grant relationship when applicable
     */
    public function getGrantBefore(): ?AuditGrantState
    {
        return $this->grantBefore;
    }

    /**
     * Returns the actual successor grant relationship when applicable
     */
    public function getGrantAfter(): ?AuditGrantState
    {
        return $this->grantAfter;
    }

    /**
     * Returns the retained predecessor after cancellation or its unchanged terminal state
     */
    public function getReplacedPredecessor(): ?AuditGrantState
    {
        return $this->replacedPredecessor;
    }

    /**
     * Returns bounded revision and relationship facts without a context bag
     *
     * @phpstan-return UserOutcomePayload
     */
    public function toArray(): array
    {
        return [
            'before'               => $this->before?->toArray(),
            'after'                => $this->after->toArray(),
            'grant_before'         => $this->grantBefore?->toArray(),
            'grant_after'          => $this->grantAfter?->toArray(),
            'replaced_predecessor' => $this->replacedPredecessor?->toArray()
        ];
    }
}
