<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\Security;

use Fight\AccessControl\Application\AccessControl\Agent\Service\AgentDeliveryCipher;
use Fight\AccessControl\Application\AccessControl\Agent\Service\AgentOperationAuthorization;
use Fight\AccessControl\Application\AccessControl\Agent\Service\HmacSharedSecretCipher;
use Fight\AccessControl\Application\AccessControl\Agent\Service\HmacSharedSecretGenerator;
use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentRepository;
use Fight\AccessControl\Domain\AccessControl\Agent\Event\AgentCredentialLifecycleFailed;
use Fight\AccessControl\Domain\AccessControl\Agent\Event\AgentCredentialRotated;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentCredentialException;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentOperationCollisionException;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentOperationRejectedException;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentCredentialOperation;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentDeliveryId;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentIssuance;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationFailure;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationKey;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationLimits;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationRepository;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentRotationRequest;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidenceRepository;
use Fight\Common\Application\Messaging\Event\EventDispatcher;
use Fight\Common\Application\Repository\TransactionalUnitOfWork;
use Throwable;

/**
 * Class AgentCredentialRotationService
 *
 * Commits a correlated successor and retirement together, resolving original requests without rotating again.
 */
final readonly class AgentCredentialRotationService
{
    /**
     * Constructs AgentCredentialRotationService
     */
    public function __construct(
        private AgentRepository $agentRepository,
        private AgentOperationRepository $operationRepository,
        private AuditEvidenceRepository $auditEvidenceRepository,
        private AgentOperationAuthorization $authorization,
        private HmacSharedSecretGenerator $hmacSharedSecretGenerator,
        private HmacSharedSecretCipher $hmacSharedSecretCipher,
        private AgentDeliveryCipher $deliveryCipher,
        private Clock $clock,
        private TransactionalUnitOfWork $unitOfWork,
        private EventDispatcher $eventDispatcher,
        private AgentOperationLimits $limits = new AgentOperationLimits()
    ) {
    }

    /**
     * Updates or resolves the original key under current fenced scope, target and destination authority
     */
    public function rotate(AgentOperationKey $key, AgentRotationRequest $request): AgentCredentialRotationResult
    {
        $resolveOnly = false;
        $missingReason = AgentOperationFailure::CONTENTION;
        while (true) {
            $callbackCompleted = false;
            $created = false;
            try {
                if ($this->unitOfWork->isClosed()) {
                    throw new AgentOperationRejectedException(AgentOperationFailure::UNAVAILABLE);
                }

                /** @var AgentIssuance $issuance */
                $issuance = $this->unitOfWork->commitTransactional(function () use (
                    $key,
                    $request,
                    $resolveOnly,
                    $missingReason,
                    &$callbackCompleted,
                    &$created
                ): AgentIssuance {
                    $this->operationRepository->getOperationContract()->assertSameCohort(
                        $this->agentRepository->getOperationContract()
                    );
                    $actor = $this->authorization->authorizeRotation(
                        $key->getScope(),
                        $request->getDestination(),
                        $request->getAgentId()
                    );
                    if (preg_match('/\A[A-Za-z0-9_.:-]{1,128}\z/D', $actor) !== 1) {
                        throw new AgentOperationRejectedException(AgentOperationFailure::UNAVAILABLE);
                    }

                    $operation = $this->operationRepository->getByKey($key);
                    if ($operation !== null) {
                        $issuance = $operation->resolve($request);
                    } else {
                        if ($resolveOnly) {
                            throw new AgentOperationRejectedException($missingReason);
                        }

                        $issuance = $this->issue($key, $request, $actor);
                        $created = true;
                    }

                    $callbackCompleted = true;

                    return $issuance;
                });
            } catch (Throwable $failure) {
                if ($callbackCompleted) {
                    $this->publishFailure();

                    return AgentCredentialRotationResult::indeterminate();
                }

                // A concurrent same-key winner may have replaced the predecessor after our absent-key read.
                // Resolve only after all losing writes roll back; never generate again inside this invocation.
                if (
                    !$resolveOnly
                    && ($failure instanceof AgentOperationCollisionException
                        || $failure instanceof AgentCredentialException)
                ) {
                    $resolveOnly = true;
                    if ($failure instanceof AgentCredentialException) {
                        $missingReason = AgentOperationFailure::CONFLICT;
                    }

                    continue;
                }

                $this->publishFailure();
                $reason = AgentOperationFailure::UNAVAILABLE;
                if ($failure instanceof AgentOperationRejectedException) {
                    $reason = $failure->getReason();
                }

                throw new AgentOperationRejectedException($reason);
            }

            if (!$created) {
                return AgentCredentialRotationResult::confirmed($issuance);
            }

            return $this->publishIssuance($issuance);
        }
    }

    /**
     * Creates the successor through the existing atomic retirement seam inside one package transaction
     */
    private function issue(AgentOperationKey $key, AgentRotationRequest $request, string $actor): AgentIssuance
    {
        $version = $this->operationRepository->getOperationContract()->getCreationVersion();
        $canonicalRequest = $request->canonicalize($version);
        $agent = $this->agentRepository->getById($request->getAgentId());
        if ($agent === null) {
            throw new AgentOperationRejectedException(AgentOperationFailure::CONFLICT);
        }

        $agent->assertRecoverableRotation(
            $request->getExpectedCredentialId(),
            $request->getExpectedCredentialRevision()
        );
        $issuedAt = $this->clock->now();
        $credentialId = AgentCredentialId::generate();
        $issuance = new AgentIssuance(
            $key,
            AgentDeliveryId::generate(),
            $request->getAgentId(),
            $credentialId,
            $request->getExpectedCredentialRevision() + 1,
            $request->getDestination(),
            $this->operationRepository->reserveDestinationWrite($request->getDestination()),
            $issuedAt
        );
        $secret = $this->hmacSharedSecretGenerator->generate();
        $successor = $agent->rotateRecoverableCredential(
            $request->getExpectedCredentialId(),
            $request->getExpectedCredentialRevision(),
            $credentialId,
            $this->hmacSharedSecretCipher->encrypt($secret),
            $issuedAt
        );
        $material = $this->deliveryCipher->encrypt($secret, $issuance);
        if (!$this->agentRepository->replace($agent, $successor)) {
            throw new AgentCredentialException('The expected Agent credential is no longer authoritative.');
        }

        $this->operationRepository->add(
            new AgentCredentialOperation($version, $canonicalRequest, $issuance, $material),
            $this->limits
        );
        $this->auditEvidenceRepository->add(AuditEvidence::agentCredentialRotated($actor, $request->getAgentId()));

        return $issuance;
    }

    /**
     * Dispatches a confirmed fact without disguising committed issuance when publication fails
     */
    private function publishIssuance(AgentIssuance $issuance): AgentCredentialRotationResult
    {
        try {
            $this->eventDispatcher->trigger(new AgentCredentialRotated(
                $issuance->getAgentId(),
                $issuance->getCredentialId(),
                $issuance->getCredentialRevision(),
                $issuance->getIssuedAt()
            ));
        } catch (Throwable) {
            $this->publishFailure();

            return AgentCredentialRotationResult::confirmed($issuance, AgentPublicationWarning::PUBLICATION_FAILED);
        }

        return AgentCredentialRotationResult::confirmed($issuance);
    }

    /**
     * Dispatches sanitized evidence without replacing the original outcome
     */
    private function publishFailure(): void
    {
        try {
            $this->eventDispatcher->trigger(new AgentCredentialLifecycleFailed(
                'credential-operation',
                'Agent operation failed.'
            ));
        } catch (Throwable) {
        }
    }
}
