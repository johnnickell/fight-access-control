<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Repository;

use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentState;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;

final class RehydratedAgentFixture extends Agent
{
    public static function withCredential(
        Agent $agent,
        AgentCredentialId $credentialId,
        int $revision,
        AgentState $state = AgentState::ACTIVE,
        ?string $envelope = null
    ): self {
        return new self(
            $agent->getId(),
            $agent->getName(),
            $state,
            $credentialId,
            $revision,
            $envelope ?? $agent->getEncryptedHmacSharedSecretEnvelope(),
            $agent->getPermissionIds(),
            $agent->getPermissionAssignmentRevision(),
            $agent->getCreatedAt(),
            $agent->getUpdatedAt()
        );
    }

    /**
     * Rehydrates an Agent with consumer-persisted Permission-assignment authority.
     *
     * @param list<PermissionId> $permissionIds
     */
    public static function fromAgent(
        Agent $agent,
        array $permissionIds,
        int $permissionAssignmentRevision
    ): self {
        return new self(
            $agent->getId(),
            $agent->getName(),
            $agent->getState(),
            $agent->getCredentialId(),
            $agent->getCredentialRevision(),
            $agent->getEncryptedHmacSharedSecretEnvelope(),
            $permissionIds,
            $permissionAssignmentRevision,
            $agent->getCreatedAt(),
            $agent->getUpdatedAt()
        );
    }
}
