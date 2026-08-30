<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\Security;

use DateInterval;
use Fight\AccessControl\Application\AccessControl\Agent\Service\AgentRequestNonceConsumer;
use Fight\AccessControl\Application\AccessControl\Agent\Service\HmacSharedSecretDecipher;
use Fight\AccessControl\Application\AccessControl\Agent\Service\HmacSignedAgentRequestVerifier;
use Fight\AccessControl\Application\AccessControl\Authorization\Service\ExactPermissionResolutionException;
use Fight\AccessControl\Application\AccessControl\Authorization\Service\ExactPermissionResolver;
use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentAuthenticationDiagnostic;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentAuthenticationDiagnosticClassification;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentRepository;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentState;
use Fight\AccessControl\Domain\AccessControl\Agent\AuthenticatedAgentPrincipal;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentAuthenticationRejectedException;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\CurrentAgentPrincipalResolutionRejectedException;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionRepository;
use Fight\Common\Application\Repository\UnitOfWork;
use Throwable;

/**
 * Resolves one authenticated Agent principal and caches only its complete immutable snapshot for one request.
 */
final class CurrentAgentPrincipalProvider
{
    private const string HMAC_SHA256 = 'HMAC-SHA256';

    private ?AuthenticatedAgentPrincipal $principal = null;

    /**
     * Creates the request-scoped current Agent-principal provider.
     */
    public function __construct(
        private readonly AgentRepository $agentRepository,
        private readonly PermissionRepository $permissionRepository,
        private readonly HmacSharedSecretDecipher $hmacSharedSecretDecipher,
        private readonly HmacSignedAgentRequestVerifier $hmacSignedAgentRequestVerifier,
        private readonly Clock $clock,
        private readonly AgentRequestNonceConsumer $agentRequestNonceConsumer,
        private readonly UnitOfWork $unitOfWork
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
            $agent = $this->authenticateAndConsumeNonce($signedAgentRequest, $correlationId);

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
        } catch (AgentAuthenticationRejectedException) {
            $this->deny(AgentAuthenticationDiagnosticClassification::AUTHENTICATION_REJECTED, $correlationId);
        } catch (CurrentAgentPrincipalResolutionRejectedException $exception) {
            throw $exception;
        } catch (Throwable) {
            $this->deny(AgentAuthenticationDiagnosticClassification::RESOLUTION_FAILED, $correlationId);
        }
    }

    /**
     * Validates the signed request, atomically consumes its nonce, and rechecks its current authority.
     */
    private function authenticateAndConsumeNonce(
        SignedAgentRequest $signedAgentRequest,
        string $correlationId
    ): Agent {
        $now = $this->clock->now();
        if (
            $signedAgentRequest->getTimestamp() > $now
            || $signedAgentRequest->getTimestamp() < $now->sub(new DateInterval('PT5M'))
            || $signedAgentRequest->getAuthorizationAlgorithm() !== self::HMAC_SHA256
            || ($signedAgentRequest->getBody() === '' && $signedAgentRequest->getBodyDigest() !== null)
            || (
                $signedAgentRequest->getBody() !== ''
                && (
                    $signedAgentRequest->getBodyDigest() === null
                    || !hash_equals(
                        hash('sha256', $signedAgentRequest->getBody()),
                        $signedAgentRequest->getBodyDigest()
                    )
                )
            )
        ) {
            throw new AgentAuthenticationRejectedException('Agent authentication rejected.');
        }

        $agent = $this->agentRepository->getByCredentialId($signedAgentRequest->getCredentialId());
        if (!$agent instanceof Agent || $agent->getState() !== AgentState::ACTIVE) {
            throw new AgentAuthenticationRejectedException('Agent authentication rejected.');
        }

        $hmacSharedSecret = $this->hmacSharedSecretDecipher->decrypt($agent->getEncryptedHmacSharedSecretEnvelope());
        if (!$this->hmacSignedAgentRequestVerifier->verifies($signedAgentRequest, $hmacSharedSecret)) {
            throw new AgentAuthenticationRejectedException('Agent authentication rejected.');
        }

        $agentId = $agent->getId();
        $credentialId = $agent->getCredentialId();
        $credentialRevision = $agent->getCredentialRevision();
        $permissionAssignmentRevision = $agent->getPermissionAssignmentRevision();
        $nonceConsumed = $this->unitOfWork->commitTransactional(
            fn(): bool => $this->agentRequestNonceConsumer->consume(
                $agentId,
                $credentialId,
                $credentialRevision,
                $permissionAssignmentRevision,
                $signedAgentRequest->getNonce(),
                $signedAgentRequest->getTimestamp()->add(new DateInterval('PT5M'))
            )
        );
        if (!$nonceConsumed) {
            throw new AgentAuthenticationRejectedException('Agent authentication rejected.');
        }

        $currentAgent = $this->agentRepository->getById($agentId);
        if (
            !$currentAgent instanceof Agent
            || $currentAgent->getState() !== AgentState::ACTIVE
            || !$currentAgent->getId()->equals($agentId)
            || !$currentAgent->getCredentialId()->equals($credentialId)
            || $currentAgent->getCredentialRevision() !== $credentialRevision
            || $currentAgent->getPermissionAssignmentRevision() !== $permissionAssignmentRevision
        ) {
            $this->deny(AgentAuthenticationDiagnosticClassification::AGENT_AUTHORITY_NOT_CURRENT, $correlationId);
        }

        return $currentAgent;
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
