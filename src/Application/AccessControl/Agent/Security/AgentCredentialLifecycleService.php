<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\Security;

use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentRepository;
use Fight\AccessControl\Domain\AccessControl\Agent\Event\AgentCredentialLifecycleFailed;
use Fight\AccessControl\Domain\AccessControl\Agent\Event\AgentCredentialRevoked;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentOperationRejectedException;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationFailure;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidenceRepository;
use Fight\Common\Application\Messaging\Event\EventDispatcher;
use Fight\Common\Application\Repository\TransactionalUnitOfWork;
use LogicException;
use Throwable;

/**
 * Class AgentCredentialLifecycleService
 *
 * Revokes authority with repository-owned cancellation and explicitly rejects the retired raw rotation API.
 */
final readonly class AgentCredentialLifecycleService
{
    private const string FAILURE_MESSAGE = 'Agent credential lifecycle failed.';

    /**
     * Constructs AgentCredentialLifecycleService
     *
     * Creates the synchronous revocation service without credential generation or encryption capabilities.
     */
    public function __construct(
        private AgentRepository $agentRepository,
        private AuditEvidenceRepository $auditEvidenceRepository,
        private Clock $clock,
        private TransactionalUnitOfWork $unitOfWork,
        private EventDispatcher $eventDispatcher
    ) {
    }

    /**
     * Rejects the retired raw-return signature without inventing correlation, destination or authority
     *
     * Use AgentCredentialRotationService with a retained key and original AgentRotationRequest instead.
     */
    public function rotate(
        string $actorId,
        AgentId $agentId,
        AgentCredentialId $expectedCredentialId
    ): never {
        throw new AgentOperationRejectedException(AgentOperationFailure::INVALID_REQUEST);
    }

    /**
     * Revokes one authoritative Agent credential and its pending delivery in the package transaction
     *
     * Caller policy is consumer-owned. The repository fences the exact loaded predecessor and cancels its original
     * operation without keys/sinks; audit commits with both changes. Publication faults still rethrow after commit.
     */
    public function revoke(string $actorId, AgentId $agentId): void
    {
        try {
            /** @var AgentCredentialRevoked $event */
            $event = $this->unitOfWork->commitTransactional(
                function () use ($actorId, $agentId): AgentCredentialRevoked {
                    $this->agentRepository->getOperationContract()->assertCompatible();
                    $agent = $this->agentRepository->getById($agentId);

                    if (!$agent instanceof Agent) {
                        throw new LogicException('The Agent does not exist.');
                    }

                    $revokedAt = $this->clock->now();
                    $revoked = $agent->revoke($revokedAt);

                    if (!$this->agentRepository->replace($agent, $revoked)) {
                        throw new LogicException('The expected Agent authority is no longer authoritative.');
                    }

                    $this->auditEvidenceRepository->add(AuditEvidence::agentCredentialRevoked($actorId, $agentId));

                    return new AgentCredentialRevoked($agentId, $revokedAt);
                }
            );

            $this->eventDispatcher->trigger($event);
        } catch (Throwable $throwable) {
            $this->publishFailure($actorId);

            throw $throwable;
        }
    }

    /**
     * Dispatches safe failure evidence without allowing a publication fault to replace the original failure
     */
    private function publishFailure(string $actorId): void
    {
        try {
            $this->eventDispatcher->trigger(new AgentCredentialLifecycleFailed($actorId, self::FAILURE_MESSAGE));
        } catch (Throwable) {
        }
    }
}
