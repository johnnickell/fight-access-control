<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\Security;

use Fight\AccessControl\Application\AccessControl\Agent\Service\HmacSharedSecretCipher;
use Fight\AccessControl\Application\AccessControl\Agent\Service\HmacSharedSecretGenerator;
use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentRepository;
use Fight\AccessControl\Domain\AccessControl\Agent\Event\AgentCredentialLifecycleFailed;
use Fight\AccessControl\Domain\AccessControl\Agent\Event\AgentCredentialRevoked;
use Fight\AccessControl\Domain\AccessControl\Agent\Event\AgentCredentialRotated;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidenceRepository;
use Fight\Common\Application\Messaging\Event\EventDispatcher;
use Fight\Common\Application\Repository\UnitOfWork;
use LogicException;
use Throwable;

/**
 * Atomically rotates an Agent credential and returns its replacement raw HMAC shared secret.
 */
final readonly class AgentCredentialLifecycleService
{
    private const string FAILURE_MESSAGE = 'Agent credential lifecycle failed.';

    /**
     * Creates the synchronous Agent credential lifecycle service.
     */
    public function __construct(
        private AgentRepository $agentRepository,
        private AuditEvidenceRepository $auditEvidenceRepository,
        private HmacSharedSecretGenerator $hmacSharedSecretGenerator,
        private HmacSharedSecretCipher $hmacSharedSecretCipher,
        private Clock $clock,
        private UnitOfWork $unitOfWork,
        private EventDispatcher $eventDispatcher
    ) {
    }

    /**
     * Rotates one authoritative Agent credential only when the expected current identifier still matches.
     */
    public function rotate(
        string $actorId,
        AgentId $agentId,
        AgentCredentialId $expectedCredentialId
    ): AgentCredentialRotationResult {
        try {
            /** @var array{AgentCredentialRotationResult, AgentCredentialRotated} $outcome */
            $outcome = $this->unitOfWork->commitTransactional(function () use (
                $actorId,
                $agentId,
                $expectedCredentialId
            ): array {
                $agent = $this->agentRepository->getById($agentId);

                if (!$agent instanceof Agent) {
                    throw new LogicException('The Agent does not exist.');
                }

                $rotatedAt = $this->clock->now();
                $successorCredentialId = AgentCredentialId::generate();
                $hmacSharedSecret = $this->hmacSharedSecretGenerator->generate();
                $successor = $agent->rotateCredential(
                    $expectedCredentialId,
                    $successorCredentialId,
                    $this->hmacSharedSecretCipher->encrypt($hmacSharedSecret),
                    $rotatedAt
                );

                if (!$this->agentRepository->replace($agent, $successor)) {
                    throw new LogicException('The expected Agent credential is no longer authoritative.');
                }

                $this->auditEvidenceRepository->add(AuditEvidence::agentCredentialRotated($actorId, $agentId));

                return [
                    new AgentCredentialRotationResult($agentId, $successorCredentialId, $hmacSharedSecret),
                    new AgentCredentialRotated(
                        $agentId,
                        $successorCredentialId,
                        $successor->getCredentialRevision(),
                        $rotatedAt
                    ),
                ];
            });

            $this->eventDispatcher->trigger($outcome[1]);

            return $outcome[0];
        } catch (Throwable $throwable) {
            $this->publishFailure($actorId);

            throw $throwable;
        }
    }

    /**
     * Terminally revokes one authoritative Agent credential.
     */
    public function revoke(string $actorId, AgentId $agentId): void
    {
        try {
            /** @var AgentCredentialRevoked $event */
            $event = $this->unitOfWork->commitTransactional(
                function () use ($actorId, $agentId): AgentCredentialRevoked {
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
     * Publishes safe failure evidence without allowing a publication fault to replace the original failure.
     */
    private function publishFailure(string $actorId): void
    {
        try {
            $this->eventDispatcher->trigger(new AgentCredentialLifecycleFailed($actorId, self::FAILURE_MESSAGE));
        } catch (Throwable) {
        }
    }
}
