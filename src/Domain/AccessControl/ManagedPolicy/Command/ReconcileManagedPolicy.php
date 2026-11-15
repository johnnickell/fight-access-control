<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\ManagedPolicy\Command;

use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Audit\AnonymousId;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditActorKind;
use Fight\AccessControl\Domain\AccessControl\Audit\SystemId;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\ManagedPolicy;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\Command;

/**
 * Class ReconcileManagedPolicy
 *
 * Requests atomic reconciliation of version-controlled authorization policy.
 */
final readonly class ReconcileManagedPolicy implements Command
{
    /**
     * Constructs ReconcileManagedPolicy
     *
     * Constructs a managed-policy reconciliation command.
     */
    public function __construct(
        private ManagedPolicy $policy,
        private UserId|AgentId|SystemId|AnonymousId|null $actorId = null
    ) {
    }

    /** @inheritDoc */
    public static function fromArray(array $data): static
    {
        $actorId = null;
        if (($data['actor_kind'] ?? null) !== null || ($data['actor_id'] ?? null) !== null) {
            if (!is_string($data['actor_kind'] ?? null) || !is_string($data['actor_id'] ?? null)) {
                throw new DomainException('Policy provenance requires both actor kind and identity.');
            }

            $kind = AuditActorKind::tryFrom($data['actor_kind']) ?? throw new DomainException('Invalid actor kind.');
            $actorId = $kind->idFromString($data['actor_id']);
        }

        return new static(ManagedPolicy::fromArray($data), $actorId);
    }

    /**
     * Returns the complete desired managed policy
     */
    public function getPolicy(): ManagedPolicy
    {
        return $this->policy;
    }

    /**
     * Returns explicit originating provenance without granting authority
     */
    public function getActorId(): UserId|AgentId|SystemId|AnonymousId|null
    {
        return $this->actorId;
    }

    /**
     * Returns the canonical command payload
     *
     * @phpstan-return array{permissions: list<array{id: string, name: string, tier: string}>,
     *     roles: list<array{id: string, name: string, permission_ids: list<string>}>,
     *     referenced_permission_ids: list<string>, actor_kind: ?string, actor_id: ?string}
     */
    public function toArray(): array
    {
        return [
            ...$this->policy->toArray(),
            'actor_kind' => $this->actorId === null ? null : AuditActorKind::forId($this->actorId)->value,
            'actor_id'   => $this->actorId?->toString()
        ];
    }
}
