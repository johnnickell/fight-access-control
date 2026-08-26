<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent\Query;

use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentName;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentState;
use Fight\Common\Domain\Type\Arrayable;

/**
 * Provides the exact secret-free immutable administrative Agent result.
 */
final readonly class AgentView implements Arrayable
{
    /**
     * Creates the safe Agent view.
     *
     * @phpstan-param list<AgentPermissionView> $permissions
     */
    public function __construct(
        private AgentId $agentId,
        private AgentName $name,
        private AgentState $state,
        private AgentCredentialId $credentialId,
        private int $credentialRevision,
        private int $permissionAssignmentRevision,
        private array $permissions
    ) {
    }

    /**
     * Creates the safe view from aggregate state and resolved Permission snapshots.
     *
     * @phpstan-param list<AgentPermissionView> $permissions
     */
    public static function fromAgent(Agent $agent, array $permissions): self
    {
        return new self(
            $agent->getId(),
            $agent->getName(),
            $agent->getState(),
            $agent->getCredentialId(),
            $agent->getCredentialRevision(),
            $agent->getPermissionAssignmentRevision(),
            $permissions
        );
    }

    /**
     * Returns the stable Agent identifier.
     */
    public function getAgentId(): AgentId
    {
        return $this->agentId;
    }

    /**
     * Returns the operator-facing Agent name.
     */
    public function getName(): AgentName
    {
        return $this->name;
    }

    /**
     * Returns the Agent lifecycle state.
     */
    public function getState(): AgentState
    {
        return $this->state;
    }

    /**
     * Returns the public credential identifier.
     */
    public function getCredentialId(): AgentCredentialId
    {
        return $this->credentialId;
    }

    /**
     * Returns the credential revision.
     */
    public function getCredentialRevision(): int
    {
        return $this->credentialRevision;
    }

    /**
     * Returns the Permission-assignment revision.
     */
    public function getPermissionAssignmentRevision(): int
    {
        return $this->permissionAssignmentRevision;
    }

    /** @return list<AgentPermissionView> */
    public function getPermissions(): array
    {
        return $this->permissions;
    }

    /**
     * Returns the exact secret-free array representation.
     *
     * @return array{
     *     agent_id: string,
     *     name: string,
     *     state: string,
     *     credential_id: string,
     *     credential_revision: int,
     *     permission_assignment_revision: int,
     *     permissions: list<array{permission_id: string, name: string}>
     * }
     */
    public function toArray(): array
    {
        return [
            'agent_id' => $this->agentId->toString(),
            'name' => $this->name->toString(),
            'state' => $this->state->value,
            'credential_id' => $this->credentialId->toString(),
            'credential_revision' => $this->credentialRevision,
            'permission_assignment_revision' => $this->permissionAssignmentRevision,
            'permissions' => array_map(
                static fn(AgentPermissionView $permission): array => $permission->toArray(),
                $this->permissions
            ),
        ];
    }
}
