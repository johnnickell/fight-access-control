<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\CommandHandler;

use Fight\AccessControl\Application\AccessControl\Agent\Credential\HmacSharedSecretCipher;
use Fight\AccessControl\Application\AccessControl\Agent\Credential\HmacSharedSecretGenerator;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\AgentOperationAuthorization;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\AgentOperationRejection;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery\AgentDeliveryCipher;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Exception\AgentIssuanceFailed;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Exception\AgentRotationConflict;
use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentRepository;
use Fight\AccessControl\Domain\AccessControl\Agent\Command\RotateAgentCredential;
use Fight\AccessControl\Domain\AccessControl\Agent\Event\AgentCredentialLifecycleFailed;
use Fight\AccessControl\Domain\AccessControl\Agent\Event\AgentCredentialRotated;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentCredentialException;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentCredentialOperation;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentCredentialOperationRepository;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentDeliveryId;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentIssuance;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationCanonicalization;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationFailure;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationKey;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationLimits;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentRotationRequest;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Exception\AgentOperationCollisionException;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Exception\AgentOperationRejectedException;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Exception\AgentOperationUncertainException;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidenceRepository;
use Fight\Common\Application\Messaging\Command\CommandHandler;
use Fight\Common\Application\Messaging\Event\EventDispatcher;
use Fight\Common\Application\Repository\TransactionalUnitOfWork;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Throwable;

/**
 * Class RotateAgentCredentialHandler
 *
 * Commits a correlated successor and retirement together, resolving original requests without rotating again.
 */
final readonly class RotateAgentCredentialHandler implements CommandHandler
{
    /**
     * Constructs RotateAgentCredentialHandler
     */
    public function __construct(
        private AgentRepository $agentRepository,
        private AgentCredentialOperationRepository $operationRepository,
        private AuditEvidenceRepository $auditEvidenceRepository,
        private AgentOperationAuthorization $operationAuthorization,
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
     * @inheritDoc
     */
    public static function commandRegistration(): string
    {
        return RotateAgentCredential::class;
    }

    /**
     * @inheritDoc
     */
    public function handle(CommandMessage $commandMessage): void
    {
        /** @var RotateAgentCredential $command */
        $command = $commandMessage->payload();
        $key = $command->getKey();
        $request = $command->getRequest();
        $resolveOnly = false;
        $missingReason = AgentOperationFailure::CONTENTION;
        while (true) {
            $callbackCompleted = false;
            $created = false;
            try {
                if ($this->unitOfWork->isClosed()) {
                    throw new AgentOperationRejection(AgentOperationFailure::UNAVAILABLE);
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
                    try {
                        $this->operationRepository->getOperationContract()->assertSameCohort(
                            $this->agentRepository->getOperationContract()
                        );
                    } catch (AgentOperationRejectedException $agentOperationRejectedException) {
                        throw AgentOperationRejection::fromDomain(
                            $agentOperationRejectedException,
                            AgentOperationFailure::UNAVAILABLE
                        );
                    }

                    try {
                        $actor = $this->operationAuthorization->authorizeRotation(
                            $key->getScope(),
                            $request->getDestination(),
                            $request->getAgentId()
                        );
                    } catch (AgentOperationRejectedException $agentOperationRejectedException) {
                        throw AgentOperationRejection::fromDomain(
                            $agentOperationRejectedException,
                            AgentOperationFailure::UNAUTHORIZED,
                            AgentOperationFailure::UNAVAILABLE
                        );
                    }

                    if (preg_match('/\A[A-Za-z0-9_.:-]{1,128}\z/D', $actor) !== 1) {
                        throw new AgentIssuanceFailed();
                    }

                    try {
                        $operation = $this->operationRepository->getByKey($key);
                    } catch (AgentOperationRejectedException $agentOperationRejectedException) {
                        throw AgentOperationRejection::fromDomain(
                            $agentOperationRejectedException,
                            AgentOperationFailure::UNAVAILABLE
                        );
                    }

                    if ($operation !== null) {
                        if ($operation->getIssuance()->getKey()->toString() !== $key->toString()) {
                            throw new AgentIssuanceFailed();
                        }

                        try {
                            $issuance = $operation->resolve($request);
                        } catch (AgentOperationRejectedException $rejection) {
                            if (
                                $rejection->getReason() === AgentOperationFailure::CONFLICT
                                && $operation->getCanonicalRequest() === $request->canonicalize()
                            ) {
                                throw new AgentIssuanceFailed();
                            }

                            throw AgentOperationRejection::fromDomain(
                                $rejection,
                                AgentOperationFailure::INVALID_REQUEST,
                                AgentOperationFailure::CONFLICT,
                                AgentOperationFailure::UNSUPPORTED_VERSION
                            );
                        }
                    } else {
                        if ($resolveOnly) {
                            throw new AgentOperationRejection($missingReason);
                        }

                        $issuance = $this->issue($key, $request, $actor);
                        $created = true;
                    }

                    $callbackCompleted = true;

                    return $issuance;
                });
            } catch (Throwable $failure) {
                /** @var bool $callbackCompleted Updated by the owned callback before a possible commit fault */
                if ($callbackCompleted) {
                    $this->publishFailure();

                    throw new AgentOperationUncertainException();
                }

                // A concurrent same-key winner may have replaced the predecessor after our absent-key read.
                // Resolve only after all losing writes roll back; never generate again inside this invocation.
                if (
                    !$resolveOnly
                    && (($failure instanceof AgentOperationRejection
                            && $failure->getReason() === AgentOperationFailure::CONTENTION)
                        || $failure instanceof AgentRotationConflict)
                ) {
                    $resolveOnly = true;
                    if ($failure instanceof AgentRotationConflict) {
                        $missingReason = AgentOperationFailure::CONFLICT;
                    }

                    continue;
                }

                $this->publishFailure();
                if ($failure instanceof AgentOperationRejection) {
                    throw AgentOperationRejection::fromReason($failure->getReason());
                }

                throw new AgentIssuanceFailed();
            }

            if ($created) {
                $this->publishIssuance($issuance);
            }

            return;
        }
    }

    /**
     * Creates the successor through the existing atomic retirement seam inside one package transaction
     */
    private function issue(AgentOperationKey $key, AgentRotationRequest $request, string $actor): AgentIssuance
    {
        $canonicalRequest = $request->canonicalize();
        try {
            $agent = $this->agentRepository->getById($request->getAgentId());
        } catch (AgentOperationRejectedException $agentOperationRejectedException) {
            throw AgentOperationRejection::fromDomain(
                $agentOperationRejectedException,
                AgentOperationFailure::UNAVAILABLE
            );
        }

        if ($agent === null) {
            throw new AgentOperationRejection(AgentOperationFailure::CONFLICT);
        }

        if (!$agent->getId()->equals($request->getAgentId())) {
            throw new AgentIssuanceFailed();
        }

        try {
            $agent->assertRecoverableRotation(
                $request->getExpectedCredentialId(),
                $request->getExpectedCredentialRevision()
            );
        } catch (AgentCredentialException) {
            throw new AgentRotationConflict();
        }

        $issuedAt = $this->clock->now();
        $credentialId = AgentCredentialId::generate();
        try {
            $writeVersion = $this->operationRepository->reserveDestinationWrite($request->getDestination());
        } catch (AgentOperationRejectedException $agentOperationRejectedException) {
            throw AgentOperationRejection::fromDomain(
                $agentOperationRejectedException,
                AgentOperationFailure::CONFLICT,
                AgentOperationFailure::UNAVAILABLE
            );
        }

        if ($writeVersion < 1) {
            throw new AgentIssuanceFailed();
        }

        $issuance = new AgentIssuance(
            $key,
            AgentDeliveryId::generate(),
            $request->getAgentId(),
            $credentialId,
            $request->getExpectedCredentialRevision() + 1,
            $request->getDestination(),
            $writeVersion,
            $issuedAt
        );
        try {
            $secret = $this->hmacSharedSecretGenerator->generate();
            $envelope = $this->hmacSharedSecretCipher->encrypt($secret);
            $material = $this->deliveryCipher->encrypt($secret, $issuance);
        } catch (Throwable) {
            throw new AgentIssuanceFailed();
        }

        $successor = $agent->rotateRecoverableCredential(
            $request->getExpectedCredentialId(),
            $request->getExpectedCredentialRevision(),
            $credentialId,
            $envelope,
            $issuedAt
        );
        try {
            $replaced = $this->agentRepository->replace($agent, $successor);
        } catch (AgentOperationRejectedException $agentOperationRejectedException) {
            throw AgentOperationRejection::fromDomain(
                $agentOperationRejectedException,
                AgentOperationFailure::UNAVAILABLE
            );
        }

        if (!$replaced) {
            throw new AgentRotationConflict();
        }

        $operation = new AgentCredentialOperation(
            AgentOperationCanonicalization::VERSION,
            $canonicalRequest,
            $issuance,
            $material
        );
        try {
            $this->operationRepository->add($operation, $this->limits);
        } catch (AgentOperationCollisionException) {
            throw new AgentOperationRejection(AgentOperationFailure::CONTENTION);
        } catch (AgentOperationRejectedException $rejection) {
            throw AgentOperationRejection::fromDomain(
                $rejection,
                AgentOperationFailure::CAPACITY,
                AgentOperationFailure::CONFLICT,
                AgentOperationFailure::UNAVAILABLE
            );
        }

        $this->auditEvidenceRepository->add(AuditEvidence::agentCredentialRotated($actor, $request->getAgentId()));

        return $issuance;
    }

    /**
     * Dispatches a confirmed fact without disguising committed issuance when publication fails
     */
    private function publishIssuance(AgentIssuance $issuance): void
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
        }
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
