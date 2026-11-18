<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Feature\Command;

use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Audit\AnonymousId;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditActorKind;
use Fight\AccessControl\Domain\AccessControl\Audit\SystemId;
use Fight\AccessControl\Domain\AccessControl\Feature\Exception\FeatureRevisionException;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\Command;

/**
 * Class RemoveFeature
 *
 * Retires a Feature only at its expected revision.
 */
final readonly class RemoveFeature implements Command
{
    /**
     * Constructs RemoveFeature
     */
    public function __construct(
        private FeatureId $featureId,
        private int $expectedRevision,
        private UserId|AgentId|SystemId|AnonymousId|null $actorId = null
    ) {
        if ($expectedRevision < 1) {
            throw new FeatureRevisionException('The expected Feature revision must be positive.');
        }
    }

    /**
     * @inheritDoc
     */
    public static function fromArray(array $data): static
    {
        if (
            !isset($data['feature_id'], $data['expected_revision'])
            || !is_string($data['feature_id']) || !is_int($data['expected_revision'])
        ) {
            throw new DomainException('Missing or invalid Feature retirement data.');
        }

        $actorId = null;
        if (($data['actor_kind'] ?? null) !== null || ($data['actor_id'] ?? null) !== null) {
            if (!is_string($data['actor_kind'] ?? null) || !is_string($data['actor_id'] ?? null)) {
                throw new DomainException('Actor provenance requires both kind and identity.');
            }

            $kind = AuditActorKind::tryFrom($data['actor_kind']) ?? throw new DomainException('Invalid actor kind.');
            $actorId = $kind->idFromString($data['actor_id']);
        }

        return new self(FeatureId::fromString($data['feature_id']), $data['expected_revision'], $actorId);
    }

    /**
     * Returns the canonical command payload
     *
     * @return array{feature_id: string, expected_revision: int, actor_kind: ?string, actor_id: ?string}
     */
    public function toArray(): array
    {
        return [
            'feature_id'        => $this->featureId->toString(),
            'expected_revision' => $this->expectedRevision,
            'actor_kind'        => $this->actorId === null ? null : AuditActorKind::forId($this->actorId)->value,
            'actor_id'          => $this->actorId?->toString()
        ];
    }

    /**
     * Returns explicit originating provenance without granting authority
     */
    public function getActorId(): UserId|AgentId|SystemId|AnonymousId|null
    {
        return $this->actorId;
    }

    /**
     * Returns the target Feature identity
     */
    public function getFeatureId(): FeatureId
    {
        return $this->featureId;
    }

    /**
     * Returns the expected current revision
     */
    public function getExpectedRevision(): int
    {
        return $this->expectedRevision;
    }
}
