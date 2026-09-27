<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\CommandHandler;

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
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionTier;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Application\Repository\TransactionalUnitOfWork;

/**
 * Class AgentPermissionAssignmentCoordinator
 *
 * Coordinates the internal desired-state mutation of direct Agent Permissions.
 *
 * @internal
 */
final readonly class AgentPermissionAssignmentCoordinator
{
    /**
     * Constructs AgentPermissionAssignmentCoordinator
     *
     * Creates the direct Agent Permission-assignment coordinator.
     */
    public function __construct(
        private AgentRepository $agentRepository,
        private PermissionRepository $permissionRepository,
        private Clock $clock,
        private TransactionalUnitOfWork $unitOfWork
    ) {
    }

    /**
     * Grants the Permission when it is not already directly assigned
     */
    public function grant(UserId $actorId, AgentId $agentId, PermissionId $permissionId): ?PermissionGrantedToAgent
    {
        return $this->unitOfWork->commitTransactional(
            function () use ($actorId, $agentId, $permissionId): ?PermissionGrantedToAgent {
                $agent = $this->getAgent($agentId);
                $permission = $this->permissionRepository->getById($permissionId);
                if (!$permission instanceof Permission || !$permission->getId()->equals($permissionId)) {
                    throw new AgentPermissionAssignmentException('The Permission does not exist.');
                }

                if ($permission->getTier() !== PermissionTier::ADMIN_SAFE) {
                    throw new AgentPermissionAssignmentException('The Permission is not eligible for an Agent.');
                }

                if (!$this->agentRepository->validatePermissionAssignments([$permission])) {
                    throw new AgentPermissionAssignmentException('The authoritative Permission changed concurrently.');
                }

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
     * Revokes the Permission when it is directly assigned
     */
    public function revoke(UserId $actorId, AgentId $agentId, PermissionId $permissionId): ?PermissionRevokedFromAgent
    {
        return $this->unitOfWork->commitTransactional(
            function () use ($actorId, $agentId, $permissionId): ?PermissionRevokedFromAgent {
                $agent = $this->getAgent($agentId);
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
     * Replaces the complete direct-Permission assignment set when it differs
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
                $agent = $this->getAgent($agentId);
                $normalizedPermissionIds = $this->normalizePermissionIds($permissionIds);
                $this->assertPermissionsAreEligible($normalizedPermissionIds);
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
     * Returns the target Agent
     */
    private function getAgent(AgentId $agentId): Agent
    {
        $agent = $this->agentRepository->getById($agentId);
        if (!$agent instanceof Agent) {
            throw new AgentPermissionAssignmentException('The Agent does not exist.');
        }

        return $agent;
    }

    /**
     * Validates one authoritative Permission reference
     */
    private function assertPermissionExists(PermissionId $permissionId): void
    {
        $permission = $this->permissionRepository->getById($permissionId);
        if (!$permission instanceof Permission || !$permission->getId()->equals($permissionId)) {
            throw new AgentPermissionAssignmentException('The Permission does not exist.');
        }
    }

    /**
     * Validates the normalized complete Permission set exactly
     *
     * @phpstan-param list<PermissionId> $permissionIds
     */
    private function assertPermissionsAreEligible(array $permissionIds): void
    {
        try {
            $permissions = new ExactPermissionResolver($this->permissionRepository)->resolveDefinitions($permissionIds);
        } catch (ExactPermissionResolutionException) {
            throw new AgentPermissionAssignmentException(
                'The complete Agent Permission assignment set is not authoritative.'
            );
        }

        foreach ($permissions as $permission) {
            if ($permission->getTier() !== PermissionTier::ADMIN_SAFE) {
                throw new AgentPermissionAssignmentException('The Permission is not eligible for an Agent.');
            }
        }

        if (!$this->agentRepository->validatePermissionAssignments($permissions)) {
            throw new AgentPermissionAssignmentException('The authoritative Permissions changed concurrently.');
        }
    }

    /**
     * Normalizes supplied Permission identities to their first-occurring set order
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
     * Persists exactly one real Agent Permission-assignment transition
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
