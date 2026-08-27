<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\Security;

use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentAuthenticationDiagnostic;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentAuthenticationDiagnosticClassification;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentPrincipalPermission;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentRepository;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentState;
use Fight\AccessControl\Domain\AccessControl\Agent\AuthenticatedAgentPrincipal;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentAuthenticationRejectedException;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\CurrentAgentPrincipalResolutionRejectedException;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionRepository;
use Throwable;

/**
 * Resolves one authenticated Agent principal and caches only its complete immutable snapshot for one request.
 */
final class CurrentAgentPrincipalProvider
{
    private ?AuthenticatedAgentPrincipal $principal = null;

    /**
     * Creates the request-scoped current Agent-principal provider.
     */
    public function __construct(
        private readonly AgentAuthenticationService $agentAuthenticationService,
        private readonly AgentRepository $agentRepository,
        private readonly PermissionRepository $permissionRepository
    ) {
    }

    /**
     * Authenticates and authoritatively resolves the current Agent principal once for this request.
     */
    public function resolve(
        SignedAgentRequest $signedAgentRequest,
        string $correlationId
    ): AuthenticatedAgentPrincipal {
        if ($this->principal instanceof AuthenticatedAgentPrincipal) {
            return $this->principal;
        }

        try {
            $authentication = $this->agentAuthenticationService->authenticate($signedAgentRequest);
        } catch (AgentAuthenticationRejectedException) {
            $this->deny(AgentAuthenticationDiagnosticClassification::AUTHENTICATION_REJECTED, $correlationId);
        }

        try {
            $agent = $this->agentRepository->getById($authentication->getAgentId());
            if (
                !$agent instanceof Agent
                || $agent->getState() !== AgentState::ACTIVE
                || !$agent->getId()->equals($authentication->getAgentId())
                || !$agent->getCredentialId()->equals($authentication->getCredentialId())
                || $agent->getCredentialRevision() !== $authentication->getCredentialRevision()
                || $agent->getPermissionAssignmentRevision() !== $authentication->getPermissionAssignmentRevision()
            ) {
                $this->deny(AgentAuthenticationDiagnosticClassification::AGENT_AUTHORITY_NOT_CURRENT, $correlationId);
            }

            $permissions = $this->permissionRepository->getByIds($agent->getPermissionIds());
            $this->principal = new AuthenticatedAgentPrincipal(
                $agent->getId(),
                $agent->getCredentialId(),
                $agent->getCredentialRevision(),
                $agent->getPermissionAssignmentRevision(),
                $this->snapshots($agent->getPermissionIds(), $permissions, $correlationId)
            );

            return $this->principal;
        } catch (CurrentAgentPrincipalResolutionRejectedException $exception) {
            throw $exception;
        } catch (Throwable) {
            $this->deny(AgentAuthenticationDiagnosticClassification::RESOLUTION_FAILED, $correlationId);
        }
    }

    /**
     * Returns ordered snapshots only when authoritative Permission resolution matches exactly.
     *
     * @phpstan-param list<PermissionId> $requestedIds
     * @phpstan-param list<Permission> $permissions
     *
     * @return list<AgentPrincipalPermission>
     */
    private function snapshots(array $requestedIds, array $permissions, string $correlationId): array
    {
        if (count($requestedIds) !== count($permissions)) {
            $this->deny(AgentAuthenticationDiagnosticClassification::PERMISSION_SNAPSHOT_INVALID, $correlationId);
        }

        $snapshots = [];
        foreach ($requestedIds as $requestedId) {
            $matches = array_values(array_filter(
                $permissions,
                static fn(Permission $permission): bool => $permission->getId()->equals($requestedId)
            ));
            if (count($matches) !== 1) {
                $this->deny(AgentAuthenticationDiagnosticClassification::PERMISSION_SNAPSHOT_INVALID, $correlationId);
            }

            $snapshots[] = new AgentPrincipalPermission($requestedId, $matches[0]->getName());
        }

        return $snapshots;
    }

    /**
     * Throws the one generic caller-facing denial with a secret-free diagnostic.
     */
    private function deny(AgentAuthenticationDiagnosticClassification $classification, string $correlationId): never
    {
        throw new CurrentAgentPrincipalResolutionRejectedException(
            new AgentAuthenticationDiagnostic($classification, $correlationId)
        );
    }
}
