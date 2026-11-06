<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent\Command;

use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\Command;

/**
 * Class GrantPermissionToAgent
 *
 * Requests granting an authoritative Permission directly to an Agent.
 */
final readonly class GrantPermissionToAgent implements Command
{
    /**
     * Constructs GrantPermissionToAgent
     *
     * Creates the Agent Permission-grant command.
     */
    public function __construct(
        private UserId $actorId,
        private AgentId $agentId,
        private PermissionId $permissionId
    ) {
    }

    /** @inheritDoc */
    public static function fromArray(array $data): static
    {
        foreach (['actor_id', 'agent_id', 'permission_id'] as $key) {
            if (!array_key_exists($key, $data)) {
                throw new DomainException(sprintf('Missing required key "%s" in data array', $key));
            }

            if (!is_string($data[$key])) {
                throw new DomainException(sprintf('Required key "%s" must be a string.', $key));
            }
        }

        return new static(
            UserId::fromString($data['actor_id']),
            AgentId::fromString($data['agent_id']),
            PermissionId::fromString($data['permission_id'])
        );
    }

    /**
     * Returns the canonical command payload
     *
     * @return array{actor_id: string, agent_id: string, permission_id: string}
     */
    public function toArray(): array
    {
        return [
            'actor_id'      => $this->actorId->toString(),
            'agent_id'      => $this->agentId->toString(),
            'permission_id' => $this->permissionId->toString()
        ];
    }

    /**
     * Returns the administrative actor
     */
    public function getActorId(): UserId
    {
        return $this->actorId;
    }

    /**
     * Returns the target Agent
     */
    public function getAgentId(): AgentId
    {
        return $this->agentId;
    }

    /**
     * Returns the Permission to grant
     */
    public function getPermissionId(): PermissionId
    {
        return $this->permissionId;
    }
}
