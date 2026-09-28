<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Service;

use Closure;
use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\Agent\Service\AgentDeliveryAuthorization;
use Fight\AccessControl\Domain\AccessControl\Agent\Delivery\AgentDeliveryAuthority;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentOperationRejectedException;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentCredentialDestination;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentIssuance;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationFailure;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationScope;
use LogicException;

final class InMemoryAgentDeliveryAuthorization implements AgentDeliveryAuthorization
{
    public bool $delegated = true;

    public bool $permitted = true;

    public bool $targetAllowed = true;

    public string $worker = 'delivery-worker';

    public DateTimeImmutable $expiresAt;

    /** @var list<string> */
    public array $discoveryWorkers = [];

    public ?Closure $afterDiscovery = null;

    public function __construct(private readonly ProvisioningEnvironment $environment)
    {
        $this->expiresAt = new DateTimeImmutable('2026-09-28T12:00:00+00:00');
    }

    public function authorizeDiscovery(
        AgentOperationScope $scope,
        AgentCredentialDestination $destination,
        ?AgentIssuance $issuance,
        DateTimeImmutable $now
    ): void {
        if ($this->environment->transaction->transactionActive) {
            throw new LogicException('Discovery authorization must be read-only.');
        }

        $this->discoveryWorkers[] = $this->worker;
        $authorization = $this->environment->authorization;
        if (
            $this->worker === '' || !$this->delegated || !$this->permitted || $now >= $this->expiresAt
            || !($authorization->scopes[$scope->toString()] ?? false) || $authorization->delegationExpired
            || ($authorization->destinations[$destination->getId()->toString()] ?? null) !== $destination->getRevision()
            || ($issuance !== null && (!$this->targetAllowed
                || in_array($issuance->getAgentId()->toString(), $authorization->deniedTargets, true)))
        ) {
            throw new AgentOperationRejectedException(AgentOperationFailure::UNAUTHORIZED);
        }

        $this->afterDiscovery?->__invoke($issuance);
    }

    public function authorize(AgentIssuance $issuance, DateTimeImmutable $now): AgentDeliveryAuthority
    {
        $this->environment->authorization->authorize($issuance->getKey()->getScope(), $issuance->getDestination());
        $slot = $issuance->getDestination()->getId()->toString();
        $currentWrite = $this->environment->operations->versions[$slot] ?? null;
        if (
            !$this->delegated || !$this->permitted || !$this->targetAllowed
            || $now >= $this->expiresAt
            || $currentWrite !== $issuance->getDestinationWriteVersion()
        ) {
            throw new AgentOperationRejectedException(AgentOperationFailure::UNAUTHORIZED);
        }

        return new AgentDeliveryAuthority(
            $this->worker.':'.$this->environment->authorization->epoch,
            $this->expiresAt
        );
    }
}
