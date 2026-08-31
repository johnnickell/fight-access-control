<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\CommandHandler;

use Fight\AccessControl\Application\AccessControl\Agent\Service\AgentPermissionAdministrationAuthorization;
use Fight\AccessControl\Application\AccessControl\Authorization\Service\ExactPermissionResolutionException;
use Fight\AccessControl\Application\AccessControl\Authorization\Service\ExactPermissionResolver;
use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentRepository;
use Fight\AccessControl\Domain\AccessControl\Agent\Event\AgentPermissionsReplaced;
use Fight\AccessControl\Domain\AccessControl\Agent\Event\PermissionGrantedToAgent;
use Fight\AccessControl\Domain\AccessControl\Agent\Event\PermissionRevokedFromAgent;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentPermissionAssignmentException;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionRepository;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Application\Repository\UnitOfWork;

/**
 * Coordinates the internal desired-state mutation of direct Agent Permissions.
 *
 * @internal
 */
final readonly class AgentPermissionAssignmentCoordinator
{
    /**
     * Creates the direct Agent Permission-assignment coordinator.
     */
    public function __construct(
        private AgentRepository $agentRepository,
        private PermissionRepository $permissionRepository,
        private AgentPermissionAdministrationAuthorization $agentPermissionAdministrationAuthorization,
        private Clock $clock,
        private UnitOfWork $unitOfWork
    ) {
    }

    /**
     * Grants the Permission when it is not already directly assigned.
     */
    public function grant(UserId $actorId, AgentId $agentId, PermissionId $permissionId): ?PermissionGrantedToAgent
    {
        return $this->unitOfWork->commitTransactional(
            function () use ($actorId, $agentId, $permissionId): ?PermissionGrantedToAgent {
                $agent = $this->authorizedAgent($actorId, $agentId);
                $this->assertPermissionExists($permissionId);
                if ($agent->hasPermission($permissionId)) {
                    return null;
                }

                $grantedAt = $this->clock->now();
                $replacement = $agent->grantPermission($permissionId, $grantedAt);
                $this->persistReplacement($agent, $replacement);

                return new PermissionGrantedToAgent($actorId, $agentId, $permissionId, $grantedAt);
            }
        );
    }

    /**
     * Revokes the Permission when it is directly assigned.
     */
    public function revoke(UserId $actorId, AgentId $agentId, PermissionId $permissionId): ?PermissionRevokedFromAgent
    {
        return $this->unitOfWork->commitTransactional(
            function () use ($actorId, $agentId, $permissionId): ?PermissionRevokedFromAgent {
                $agent = $this->authorizedAgent($actorId, $agentId);
                $this->assertPermissionExists($permissionId);
                if (!$agent->hasPermission($permissionId)) {
                    return null;
                }

                $revokedAt = $this->clock->now();
                $replacement = $agent->revokePermission($permissionId, $revokedAt);
                $this->persistReplacement($agent, $replacement);

                return new PermissionRevokedFromAgent($actorId, $agentId, $permissionId, $revokedAt);
            }
        );
    }

    /**
     * Replaces the complete direct-Permission assignment set when it differs.
     *
     * @phpstan-param list<PermissionId> $permissionIds
     */
    public function replace(
        UserId $actorId,
        AgentId $agentId,
        int $expectedPermissionAssignmentRevision,
        array $permissionIds
    ): ?AgentPermissionsReplaced {
        return $this->unitOfWork->commitTransactional(
            function () use (
                $actorId,
                $agentId,
                $expectedPermissionAssignmentRevision,
                $permissionIds
            ): ?AgentPermissionsReplaced {
                $agent = $this->authorizedAgent($actorId, $agentId);
                $normalizedPermissionIds = $this->normalizePermissionIds($permissionIds);
                $this->assertPermissionsResolveExactly($normalizedPermissionIds);
                $replacedAt = $this->clock->now();
                $replacement = $agent->replacePermissions(
                    $normalizedPermissionIds,
                    $expectedPermissionAssignmentRevision,
                    $replacedAt
                );
                if ($replacement === $agent) {
                    return null;
                }

                $this->persistReplacement($agent, $replacement);

                return new AgentPermissionsReplaced(
                    $actorId,
                    $agentId,
                    $replacement->getPermissionIds(),
                    $replacement->getPermissionAssignmentRevision(),
                    $replacedAt
                );
            }
        );
    }

    /**
     * Returns the authorized target Agent.
     */
    private function authorizedAgent(UserId $actorId, AgentId $agentId): Agent
    {
        $this->agentPermissionAdministrationAuthorization->assertCanManageAgentPermissions($actorId);
        $agent = $this->agentRepository->getById($agentId);
        if (!$agent instanceof Agent) {
            throw new AgentPermissionAssignmentException('The Agent does not exist.');
        }

        return $agent;
    }

    /**
     * Validates one authoritative Permission reference.
     */
    private function assertPermissionExists(PermissionId $permissionId): void
    {
        if (!$this->permissionRepository->getById($permissionId) instanceof Permission) {
            throw new AgentPermissionAssignmentException('The Permission does not exist.');
        }
    }

    /**
     * Validates the normalized complete Permission set exactly.
     *
     * @phpstan-param list<PermissionId> $permissionIds
     */
    private function assertPermissionsResolveExactly(array $permissionIds): void
    {
        try {
            new ExactPermissionResolver($this->permissionRepository)->resolve($permissionIds);
        } catch (ExactPermissionResolutionException) {
            throw new AgentPermissionAssignmentException(
                'The complete Agent Permission assignment set is not authoritative.'
            );
        }
    }

    /**
     * Normalizes supplied Permission identities to their first-occurring set order.
     *
     * @phpstan-param list<PermissionId> $permissionIds
     *
     * @return list<PermissionId>
     */
    private function normalizePermissionIds(array $permissionIds): array
    {
        $normalizedPermissionIds = [];
        $seen = [];
        foreach ($permissionIds as $permissionId) {
            $key = $permissionId->toString();
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $normalizedPermissionIds[] = $permissionId;
        }

        return $normalizedPermissionIds;
    }

    /**
     * Persists exactly one real Agent Permission-assignment transition.
     */
    private function persistReplacement(Agent $agent, Agent $replacement): void
    {
        if (!$this->agentRepository->replacePermissionAssignments($agent, $replacement)) {
            throw new AgentPermissionAssignmentException(
                'The Agent Permission assignments or authoritative Permissions changed concurrently.'
            );
        }
    }
}
