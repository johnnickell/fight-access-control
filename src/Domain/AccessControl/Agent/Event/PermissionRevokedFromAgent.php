<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent\Event;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Event\Event;

/**
 * Records a direct Agent Permission revoke after durable commit.
 */
final readonly class PermissionRevokedFromAgent implements Event
{
    /**
     * Creates the Agent Permission-revoked event.
     */
    public function __construct(
        private UserId $actorId,
        private AgentId $agentId,
        private PermissionId $permissionId,
        private DateTimeImmutable $revokedAt
    ) {
    }

    /** @inheritDoc */
    public static function fromArray(array $data): static
    {
        foreach (['actor_id', 'agent_id', 'permission_id', 'revoked_at'] as $key) {
            if (!array_key_exists($key, $data)) {
                throw new DomainException(sprintf('Missing required key "%s" in data array', $key));
            }
        }

        return new static(
            UserId::fromString((string) $data['actor_id']),
            AgentId::fromString((string) $data['agent_id']),
            PermissionId::fromString((string) $data['permission_id']),
            new DateTimeImmutable((string) $data['revoked_at'])
        );
    }

    /** @inheritDoc */
    public function toArray(): array
    {
        return [
            'actor_id' => $this->actorId->toString(),
            'agent_id' => $this->agentId->toString(),
            'permission_id' => $this->permissionId->toString(),
            'revoked_at' => $this->revokedAt->format(DATE_ATOM),
        ];
    }

    /**
     * Returns the administrative actor.
     */
    public function getActorId(): UserId
    {
        return $this->actorId;
    }

    /**
     * Returns the changed Agent.
     */
    public function getAgentId(): AgentId
    {
        return $this->agentId;
    }

    /**
     * Returns the revoked Permission.
     */
    public function getPermissionId(): PermissionId
    {
        return $this->permissionId;
    }

    /**
     * Returns the revoke time.
     */
    public function getRevokedAt(): DateTimeImmutable
    {
        return $this->revokedAt;
    }
}
