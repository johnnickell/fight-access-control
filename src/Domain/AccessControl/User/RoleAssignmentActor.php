<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\User;

use Fight\AccessControl\Domain\AccessControl\Audit\AuditActorKind;
use Fight\AccessControl\Domain\AccessControl\Audit\SystemId;
use Fight\Common\Domain\Exception\DomainException;

/**
 * Class RoleAssignmentActor
 *
 * @internal
 */
final class RoleAssignmentActor
{
    /**
     * Returns the typed actor from assignment message data
     *
     * @phpstan-param array<string, mixed> $data
     */
    public static function fromArray(string $actorId, array $data): UserId|SystemId
    {
        if (!array_key_exists('actor_kind', $data)) {
            return UserId::fromString($actorId);
        }

        if ($data['actor_kind'] !== AuditActorKind::SYSTEM->value) {
            throw new DomainException('Unsupported role assignment actor kind.');
        }

        return SystemId::fromString($actorId);
    }

    /**
     * Returns the actor fields for an assignment message
     *
     * @phpstan-return array{actor_id: string, actor_kind?: 'system'}
     */
    public static function toArray(UserId|SystemId $actorId): array
    {
        $payload = ['actor_id' => $actorId->toString()];
        if ($actorId instanceof SystemId) {
            $payload['actor_kind'] = AuditActorKind::SYSTEM->value;
        }

        return $payload;
    }
}
