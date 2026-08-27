<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\Security;

use DateInterval;
use Fight\AccessControl\Application\AccessControl\Agent\Service\AgentRequestNonceConsumer;
use Fight\AccessControl\Application\AccessControl\Agent\Service\HmacSharedSecretDecipher;
use Fight\AccessControl\Application\AccessControl\Agent\Service\HmacSignedAgentRequestVerifier;
use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentRepository;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentState;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentAuthenticationRejectedException;
use Fight\Common\Application\Repository\UnitOfWork;
use Throwable;

/**
 * Authenticates one portable Agent HMAC request with atomic replay protection.
 */
final readonly class AgentAuthenticationService
{
    private const string HMAC_SHA256 = 'HMAC-SHA256';

    /**
     * Creates the signed Agent request authentication service.
     */
    public function __construct(
        private AgentRepository $agentRepository,
        private HmacSharedSecretDecipher $hmacSharedSecretDecipher,
        private HmacSignedAgentRequestVerifier $hmacSignedAgentRequestVerifier,
        private Clock $clock,
        private AgentRequestNonceConsumer $agentRequestNonceConsumer,
        private UnitOfWork $unitOfWork
    ) {
    }

    /**
     * Authenticates one request against its current active Agent credential.
     */
    public function authenticate(SignedAgentRequest $signedAgentRequest): AgentAuthenticationResult
    {
        try {
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

            $hmacSharedSecret = $this->hmacSharedSecretDecipher->decrypt(
                $agent->getEncryptedHmacSharedSecretEnvelope()
            );
            if (!$this->hmacSignedAgentRequestVerifier->verifies($signedAgentRequest, $hmacSharedSecret)) {
                throw new AgentAuthenticationRejectedException('Agent authentication rejected.');
            }

            $nonceConsumed = $this->unitOfWork->commitTransactional(
                fn(): bool => $this->agentRequestNonceConsumer->consume(
                    $agent->getId(),
                    $agent->getCredentialId(),
                    $agent->getCredentialRevision(),
                    $signedAgentRequest->getNonce(),
                    $signedAgentRequest->getTimestamp()->add(new DateInterval('PT5M'))
                )
            );
            if (!$nonceConsumed) {
                throw new AgentAuthenticationRejectedException('Agent authentication rejected.');
            }

            return new AgentAuthenticationResult(
                $agent->getId(),
                $agent->getCredentialId(),
                $agent->getCredentialRevision()
            );
        } catch (AgentAuthenticationRejectedException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new AgentAuthenticationRejectedException('Agent authentication rejected.');
        }
    }
}
