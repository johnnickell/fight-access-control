<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Service;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\Agent\Service\AgentDeliveryAuthorization;
use Fight\AccessControl\Domain\AccessControl\Agent\Delivery\AgentDeliveryAuthority;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentOperationRejectedException;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentIssuance;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationFailure;

final class InMemoryAgentDeliveryAuthorization implements AgentDeliveryAuthorization
{
    public bool $delegated = true;

    public bool $permitted = true;

    public bool $targetAllowed = true;

    public string $worker = 'delivery-worker';

    public DateTimeImmutable $expiresAt;

    public function __construct(private readonly ProvisioningEnvironment $environment)
    {
        $this->expiresAt = new DateTimeImmutable('2026-09-28T12:00:00+00:00');
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
