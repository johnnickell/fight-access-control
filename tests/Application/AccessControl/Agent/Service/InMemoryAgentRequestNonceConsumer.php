<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Service;

use Closure;
use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\Agent\Service\AgentRequestNonceConsumer;
use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentRepository;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentState;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use Throwable;

/**
 * Deterministic consumer-composed nonce and current-credential authority fixture.
 */
final class InMemoryAgentRequestNonceConsumer implements AgentRequestNonceConsumer
{
    /** @var array<string, DateTimeImmutable> */
    private array $consumedNonces = [];

    private int $consumptionCalls = 0;

    private ?DateTimeImmutable $expiresAt = null;

    /**
     * Creates the atomic nonce-consumption fixture.
     */
    public function __construct(
        private readonly AgentRepository $agentRepository,
        private readonly InMemoryUnitOfWork $unitOfWork,
        private readonly ?Closure $beforeConsume = null,
        private readonly ?Throwable $failure = null
    ) {
    }

    public function consume(
        AgentId $agentId,
        AgentCredentialId $credentialId,
        int $credentialRevision,
        string $nonce,
        DateTimeImmutable $expiresAt
    ): bool {
        ++$this->consumptionCalls;
        $this->beforeConsume?->__invoke();

        $agent = $this->agentRepository->getById($agentId);
        if (
            !$agent instanceof Agent
            || $agent->getState() !== AgentState::ACTIVE
            || !$agent->getCredentialId()->equals($credentialId)
            || $agent->getCredentialRevision() !== $credentialRevision
            || isset($this->consumedNonces[$nonce])
        ) {
            return false;
        }

        if ($this->failure instanceof Throwable) {
            throw $this->failure;
        }

        $this->consumedNonces[$nonce] = $expiresAt;
        $this->expiresAt = $expiresAt;
        $this->unitOfWork->onRollback(function () use ($nonce): void {
            unset($this->consumedNonces[$nonce]);
        });

        return true;
    }

    /**
     * Returns the number of atomic consumption attempts.
     */
    public function consumptionCalls(): int
    {
        return $this->consumptionCalls;
    }

    /**
     * Returns the expiry supplied for the successfully consumed nonce.
     */
    public function expiresAt(): ?DateTimeImmutable
    {
        return $this->expiresAt;
    }
}
