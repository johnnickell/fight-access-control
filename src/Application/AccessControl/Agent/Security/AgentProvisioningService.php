<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\Security;

use Fight\AccessControl\Application\AccessControl\Agent\Service\AgentDeliveryCipher;
use Fight\AccessControl\Application\AccessControl\Agent\Service\AgentOperationAuthorization;
use Fight\AccessControl\Application\AccessControl\Agent\Service\HmacSharedSecretCipher;
use Fight\AccessControl\Application\AccessControl\Agent\Service\HmacSharedSecretGenerator;
use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentName;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentRepository;
use Fight\AccessControl\Domain\AccessControl\Agent\Event\AgentProvisioned;
use Fight\AccessControl\Domain\AccessControl\Agent\Event\AgentProvisioningFailed;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentOperationCollisionException;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentOperationRejectedException;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentCredentialOperation;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentDeliveryId;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentIssuance;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationCanonicalization;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationFailure;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationKey;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationLimits;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationRepository;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentProvisioningRequest;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidenceRepository;
use Fight\Common\Application\Messaging\Event\EventDispatcher;
use Fight\Common\Application\Repository\TransactionalUnitOfWork;
use Throwable;

/**
 * Class AgentProvisioningService
 *
 * Commits recoverable issuance and prepared delivery without returning raw credential material.
 */
final readonly class AgentProvisioningService
{
    /**
     * Constructs AgentProvisioningService
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
     * Provisions or resolves one retained key under current transactional authorization
     */
    public function provision(AgentOperationKey $key, AgentProvisioningRequest $request): AgentProvisioningResult
    {
        $resolveOnly = false;
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
                    &$callbackCompleted,
                    &$created
                ): AgentIssuance {
                    $this->operationRepository->getOperationContract()->assertSameCohort(
                        $this->agentRepository->getOperationContract()
                    );
                    $actor = $this->authorization->authorize($key->getScope(), $request->getDestination());
                    if (preg_match('/\A[A-Za-z0-9_.:-]{1,128}\z/D', $actor) !== 1) {
                        throw new AgentOperationRejectedException(AgentOperationFailure::UNAVAILABLE);
                    }

                    $operation = $this->operationRepository->getByKey($key);
                    if ($operation !== null) {
                        $issuance = $operation->resolve($request);
                    } else {
                        if ($resolveOnly) {
                            throw new AgentOperationRejectedException(AgentOperationFailure::CONTENTION);
                        }

                        $issuance = $this->issue($key, $request, $actor);
                        $created = true;
                    }

                    $callbackCompleted = true;

                    return $issuance;
                });
            } catch (Throwable $failure) {
                // A lost commit acknowledgement cannot be classified as rollback, even after a read-only retry.
                if ($callbackCompleted) {
                    $this->publishFailure();

                    return AgentProvisioningResult::indeterminate();
                }

                if ($failure instanceof AgentOperationCollisionException && !$resolveOnly) {
                    $resolveOnly = true;
                    continue;
                }

                $this->publishFailure();
                $reason = AgentOperationFailure::UNAVAILABLE;
                if ($failure instanceof AgentOperationRejectedException) {
                    $reason = $failure->getReason();
                }

                // Do not chain arbitrary exceptions or expose their provider messages or trace arguments.
                throw new AgentOperationRejectedException($reason);
            }

            if (!$created) {
                return AgentProvisioningResult::confirmed($issuance);
            }

            return $this->publishIssuance($issuance);
        }
    }

    /**
     * Creates all issuance state inside the package-owned transaction
     */
    private function issue(
        AgentOperationKey $key,
        AgentProvisioningRequest $request,
        string $actor
    ): AgentIssuance {
        $this->limits->validateNewRequest($request);
        $canonicalRequest = $request->canonicalize();
        $issuedAt = $this->clock->now();
        $agentId = AgentId::generate();
        $credentialId = AgentCredentialId::generate();
        $issuance = new AgentIssuance(
            $key,
            AgentDeliveryId::generate(),
            $agentId,
            $credentialId,
            0,
            $request->getDestination(),
            $this->operationRepository->reserveDestinationWrite($request->getDestination()),
            $issuedAt
        );
        $secret = $this->hmacSharedSecretGenerator->generate();
        $agent = Agent::provision(
            $agentId,
            AgentName::fromString(AgentOperationCanonicalization::name($request->getName())),
            $credentialId,
            $this->hmacSharedSecretCipher->encrypt($secret),
            $issuedAt
        );
        $material = $this->deliveryCipher->encrypt($secret, $issuance);
        $this->agentRepository->add($agent);
        $this->operationRepository->add(
            new AgentCredentialOperation(
                AgentOperationCanonicalization::VERSION,
                $canonicalRequest,
                $issuance,
                $material
            ),
            $this->limits
        );
        $this->auditEvidenceRepository->add(AuditEvidence::agentProvisioned($actor, $agentId));

        return $issuance;
    }

    /**
     * Dispatches a confirmed fact without allowing notification faults to disguise committed issuance
     */
    private function publishIssuance(AgentIssuance $issuance): AgentProvisioningResult
    {
        try {
            $this->eventDispatcher->trigger(new AgentProvisioned(
                $issuance->getAgentId(),
                $issuance->getCredentialId(),
                $issuance->getCredentialRevision(),
                $issuance->getIssuedAt()
            ));
        } catch (Throwable) {
            $this->publishFailure();

            return AgentProvisioningResult::confirmed($issuance, AgentPublicationWarning::PUBLICATION_FAILED);
        }

        return AgentProvisioningResult::confirmed($issuance);
    }

    /**
     * Dispatches sanitized evidence without replacing the actual operation outcome
     */
    private function publishFailure(): void
    {
        try {
            $this->eventDispatcher->trigger(new AgentProvisioningFailed(
                'credential-operation',
                'Agent operation failed.'
            ));
        } catch (Throwable) {
        }
    }
}
