<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\Security;

use Fight\AccessControl\Application\AccessControl\Authorization\Service\ExactPermissionResolutionException;
use Fight\AccessControl\Application\AccessControl\Authorization\Service\ExactPermissionResolver;
use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentAuthenticationDiagnostic;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentAuthenticationDiagnosticClassification;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentRepository;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentState;
use Fight\AccessControl\Domain\AccessControl\Agent\AuthenticatedAgentPrincipal;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentAuthenticationRejectedException;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\CurrentAgentPrincipalResolutionRejectedException;
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

            $this->principal = new AuthenticatedAgentPrincipal(
                $agent->getId(),
                $agent->getCredentialId(),
                $agent->getCredentialRevision(),
                $agent->getPermissionAssignmentRevision(),
                new ExactPermissionResolver($this->permissionRepository)->resolve($agent->getPermissionIds())
            );

            return $this->principal;
        } catch (ExactPermissionResolutionException) {
            $this->deny(AgentAuthenticationDiagnosticClassification::PERMISSION_SNAPSHOT_INVALID, $correlationId);
        } catch (CurrentAgentPrincipalResolutionRejectedException $exception) {
            throw $exception;
        } catch (Throwable) {
            $this->deny(AgentAuthenticationDiagnosticClassification::RESOLUTION_FAILED, $correlationId);
        }
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
