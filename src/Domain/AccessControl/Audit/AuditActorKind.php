<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Audit;

use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;

/**
 * Enum AuditActorKind
 *
 * Encodes the finite kind discriminator without wrapping an actor identity or granting authority.
 */
enum AuditActorKind: string
{
    case USER = 'user';
    case AGENT = 'agent';
    case SYSTEM = 'system';
    case ANONYMOUS = 'anonymous';

    /**
     * Returns the discriminator for a direct typed identity
     */
    public static function forId(UserId|AgentId|SystemId|AnonymousId $id): self
    {
        return match (true) {
            $id instanceof UserId => self::USER,
            $id instanceof AgentId => self::AGENT,
            $id instanceof SystemId => self::SYSTEM,
            $id instanceof AnonymousId => self::ANONYMOUS
        };
    }

    /**
     * Creates an explicitly discriminated identity using its owning UUID validation
     */
    public function idFromString(string $id): UserId|AgentId|SystemId|AnonymousId
    {
        return match ($this) {
            self::USER => UserId::fromString($id),
            self::AGENT => AgentId::fromString($id),
            self::SYSTEM => SystemId::fromString($id),
            self::ANONYMOUS => AnonymousId::fromString($id)
        };
    }
}
