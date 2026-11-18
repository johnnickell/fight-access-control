<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\PermissionAssignment;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\Authorization\Service\ExactPermissionResolver;
use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentRepository;
use Fight\AccessControl\Domain\AccessControl\Agent\Event\AgentPermissionsReplaced;
use Fight\AccessControl\Domain\AccessControl\Agent\Event\PermissionGrantedToAgent;
use Fight\AccessControl\Domain\AccessControl\Agent\Event\PermissionRevokedFromAgent;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentPermissionAssignmentException;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentPermissionRevisionMismatchException;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditAgentPermissionOutcome;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionRepository;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionTier;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Application\Error\Exception\PreconditionFailed;
use Fight\Common\Application\Error\Exception\ResourceNotFound;
use Fight\Common\Application\Error\Exception\RuleViolation;
use Fight\Common\Application\Error\Exception\StateConflict;
use LogicException;

/**
 * Class AgentPermissionAssignmentCoordinator
 *
 * Stages direct assignment changes within the caller's transaction, without recording or committing evidence.
 *
 * @internal
 */
final readonly class AgentPermissionAssignmentCoordinator
{
    /**
     * Constructs AgentPermissionAssignmentCoordinator
     */
    public function __construct(
        private AgentRepository $agentRepository,
        private PermissionRepository $permissionRepository,
        private Clock $clock
    ) {
    }

    /**
     * Grants the Permission when it is not already directly assigned
     *
     * @phpstan-return array{PermissionGrantedToAgent, AuditAgentPermissionOutcome, DateTimeImmutable}|null
     */
    public function grant(UserId $actorId, AgentId $agentId, PermissionId $permissionId): ?array
    {
        $agent = $this->getAgent($agentId);
        $permission = $this->getPermission($permissionId);
        if ($permission->getTier() !== PermissionTier::ADMIN_SAFE) {
            throw new RuleViolation('The Permission is not eligible for an Agent.');
        }

        if (!$this->agentRepository->validatePermissionAssignments([$permission])) {
            throw new StateConflict('The authoritative Permission changed concurrently.');
        }

        if ($agent->hasPermission($permissionId)) {
            return null;
        }

        $grantedAt = $this->clock->now();
        try {
            $replacement = $agent->grantPermission($permissionId, $grantedAt);
        } catch (AgentPermissionAssignmentException $agentPermissionAssignmentException) {
            throw new RuleViolation(
                'The Agent assignment cannot change.',
                previous: $agentPermissionAssignmentException
            );
        }

        $this->persistReplacement($agent, $replacement);

        return [
            new PermissionGrantedToAgent($actorId, $agentId, $permissionId, $grantedAt),
            new AuditAgentPermissionOutcome(
                $agent->getPermissionAssignmentRevision(),
                $replacement->getPermissionAssignmentRevision(),
                [$permissionId],
                []
            ),
            $grantedAt
        ];
    }

    /**
     * Revokes the Permission when it is directly assigned
     *
     * @phpstan-return array{PermissionRevokedFromAgent, AuditAgentPermissionOutcome, DateTimeImmutable}|null
     */
    public function revoke(UserId $actorId, AgentId $agentId, PermissionId $permissionId): ?array
    {
        $agent = $this->getAgent($agentId);
        $this->getPermission($permissionId);
        if (!$agent->hasPermission($permissionId)) {
            return null;
        }

        $revokedAt = $this->clock->now();
        try {
            $replacement = $agent->revokePermission($permissionId, $revokedAt);
        } catch (AgentPermissionAssignmentException $agentPermissionAssignmentException) {
            throw new RuleViolation(
                'The Agent assignment cannot change.',
                previous: $agentPermissionAssignmentException
            );
        }

        $this->persistReplacement($agent, $replacement);

        return [
            new PermissionRevokedFromAgent($actorId, $agentId, $permissionId, $revokedAt),
            new AuditAgentPermissionOutcome(
                $agent->getPermissionAssignmentRevision(),
                $replacement->getPermissionAssignmentRevision(),
                [],
                [$permissionId]
            ),
            $revokedAt
        ];
    }

    /**
     * Replaces the complete direct-Permission assignment set when it differs
     *
     * @phpstan-param list<PermissionId> $permissionIds
     * @phpstan-return array{AgentPermissionsReplaced, AuditAgentPermissionOutcome, DateTimeImmutable}|null
     */
    public function replace(
        UserId $actorId,
        AgentId $agentId,
        int $expectedPermissionAssignmentRevision,
        array $permissionIds
    ): ?array {
        $agent = $this->getAgent($agentId);
        $normalizedPermissionIds = $this->normalizePermissionIds($permissionIds);
        $this->assertPermissionsAreEligible($normalizedPermissionIds);
        $replacedAt = $this->clock->now();
        try {
            $replacement = $agent->replacePermissions(
                $normalizedPermissionIds,
                $expectedPermissionAssignmentRevision,
                $replacedAt
            );
        } catch (AgentPermissionRevisionMismatchException $exception) {
            throw new PreconditionFailed('The Agent assignment revision is stale.', previous: $exception);
        } catch (AgentPermissionAssignmentException $exception) {
            throw new RuleViolation('The Agent assignment cannot change.', previous: $exception);
        }

        if ($replacement === $agent) {
            return null;
        }

        $this->persistReplacement($agent, $replacement);
        $granted = array_values(array_filter(
            $replacement->getPermissionIds(),
            static fn(PermissionId $id): bool => !$agent->hasPermission($id)
        ));
        $revoked = array_values(array_filter(
            $agent->getPermissionIds(),
            static fn(PermissionId $id): bool => !$replacement->hasPermission($id)
        ));

        return [
            new AgentPermissionsReplaced(
                $actorId,
                $agentId,
                $replacement->getPermissionIds(),
                $replacement->getPermissionAssignmentRevision(),
                $replacedAt
            ),
            new AuditAgentPermissionOutcome(
                $agent->getPermissionAssignmentRevision(),
                $replacement->getPermissionAssignmentRevision(),
                $granted,
                $revoked
            ),
            $replacedAt
        ];
    }

    /**
     * Returns the exact target Agent after cohort admission
     */
    private function getAgent(AgentId $agentId): Agent
    {
        $this->agentRepository->getOperationContract()->assertCompatible();
        $agent = $this->agentRepository->getById($agentId);
        if ($agent === null) {
            throw new ResourceNotFound('The Agent does not exist.');
        }

        if (!$agent->getId()->equals($agentId)) {
            throw new LogicException('The Agent repository returned an unrelated identity.');
        }

        return $agent;
    }

    /**
     * Returns one exact authoritative Permission reference
     */
    private function getPermission(PermissionId $permissionId): Permission
    {
        $permission = $this->permissionRepository->getById($permissionId);
        if ($permission === null) {
            throw new ResourceNotFound('The Permission does not exist.');
        }

        if (!$permission->getId()->equals($permissionId)) {
            throw new LogicException('The Permission repository returned an unrelated identity.');
        }

        return $permission;
    }

    /**
     * Validates the normalized complete Permission set exactly
     *
     * @phpstan-param list<PermissionId> $permissionIds
     */
    private function assertPermissionsAreEligible(array $permissionIds): void
    {
        // Explicit lookups distinguish genuine absence from a broken bulk snapshot contract.
        foreach ($permissionIds as $permissionId) {
            $this->getPermission($permissionId);
        }

        $permissions = new ExactPermissionResolver($this->permissionRepository)->resolveDefinitions($permissionIds);
        foreach ($permissions as $permission) {
            if ($permission->getTier() !== PermissionTier::ADMIN_SAFE) {
                throw new RuleViolation('The Permission is not eligible for an Agent.');
            }
        }

        if (!$this->agentRepository->validatePermissionAssignments($permissions)) {
            throw new StateConflict('The authoritative Permissions changed concurrently.');
        }
    }

    /**
     * Normalizes supplied Permission identities to their first-occurring set order
     *
     * @phpstan-param list<PermissionId> $permissionIds
     * @phpstan-return list<PermissionId>
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
            throw new StateConflict(
                'The Agent Permission assignments or authoritative Permissions changed concurrently.'
            );
        }
    }
}
