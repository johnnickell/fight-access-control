<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery;

use Closure;
use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery\AgentDeliveryAuthorization;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentCredentialDestination;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentIssuance;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationFailure;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationScope;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Delivery\AgentDeliveryAuthority;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Exception\AgentOperationRejectedException;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Credential\ProvisioningEnvironment;
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
        $operationAuthorization = $this->environment->operationAuthorization;
        if (
            $this->worker === '' || !$this->delegated || !$this->permitted || $now >= $this->expiresAt
            || !($operationAuthorization->scopes[$scope->toString()] ?? false)
            || $operationAuthorization->delegationExpired
            || ($operationAuthorization->destinations[
                $destination->getId()->toString()
            ] ?? null) !== $destination->getRevision()
            || ($issuance !== null && (!$this->targetAllowed
                || in_array($issuance->getAgentId()->toString(), $operationAuthorization->deniedTargets, true)))
        ) {
            throw new AgentOperationRejectedException(AgentOperationFailure::UNAUTHORIZED);
        }

        $this->afterDiscovery?->__invoke($issuance);
    }

    public function authorize(AgentIssuance $issuance, DateTimeImmutable $now): AgentDeliveryAuthority
    {
        $this->environment->operationAuthorization->authorize(
            $issuance->getKey()->getScope(),
            $issuance->getDestination()
        );
        $slot = $issuance->getDestination()->getId()->toString();
        $currentWrite = $this->environment->operationRepository->versions[$slot] ?? null;
        if (
            !$this->delegated || !$this->permitted || !$this->targetAllowed
            || $now >= $this->expiresAt
            || $currentWrite !== $issuance->getDestinationWriteVersion()
        ) {
            throw new AgentOperationRejectedException(AgentOperationFailure::UNAUTHORIZED);
        }

        return new AgentDeliveryAuthority(
            $this->worker.':'.$this->environment->operationAuthorization->epoch,
            $this->expiresAt
        );
    }
}
