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
use Fight\AccessControl\Domain\AccessControl\Agent\Event\AgentProvisioned;
use Fight\AccessControl\Domain\AccessControl\Agent\Event\AgentProvisioningFailed;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidenceRepository;
use Fight\Common\Application\Messaging\Event\EventDispatcher;
use Fight\Common\Application\Repository\UnitOfWork;
use Throwable;

/**
 * Atomically provisions one Agent and returns its first raw HMAC shared secret.
 */
final readonly class AgentProvisioningService
{
    private const string FAILURE_MESSAGE = 'Agent provisioning failed.';

    /**
     * Creates the synchronous Agent provisioning service.
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
     * Provisions an Agent for one safe, consumer-supplied maintainer actor identifier.
     */
    public function provision(string $actorId): AgentProvisioningResult
    {
        try {
            /** @var array{AgentProvisioningResult, AgentProvisioned} $outcome */
            $outcome = $this->unitOfWork->commitTransactional(function () use ($actorId): array {
                $provisionedAt = $this->clock->now();
                $agentId = AgentId::generate();
                $credentialId = AgentCredentialId::generate();
                $hmacSharedSecret = $this->hmacSharedSecretGenerator->generate();
                $agent = Agent::provision(
                    $agentId,
                    $credentialId,
                    $this->hmacSharedSecretCipher->encrypt($hmacSharedSecret),
                    $provisionedAt
                );
                $this->agentRepository->add($agent);
                $this->auditEvidenceRepository->add(AuditEvidence::agentProvisioned($actorId, $agentId));

                return [
                    new AgentProvisioningResult($agentId, $credentialId, $hmacSharedSecret),
                    new AgentProvisioned($agentId, $credentialId, $agent->getCredentialRevision(), $provisionedAt),
                ];
            });

            $this->eventDispatcher->trigger($outcome[1]);

            return $outcome[0];
        } catch (Throwable $throwable) {
            $this->publishFailure($actorId, $throwable);

            throw $throwable;
        }
    }

    /**
     * Publishes safe failure evidence without allowing a publication fault to replace the original failure.
     */
    private function publishFailure(string $actorId, Throwable $throwable): void
    {
        try {
            $this->eventDispatcher->trigger(new AgentProvisioningFailed($actorId, self::FAILURE_MESSAGE));
        } catch (Throwable) {
        }
    }
}
