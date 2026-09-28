<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Service;

use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentCredentialDeliveryService;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentCredentialLifecycleService;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentDeliveryResult;
use Fight\AccessControl\Application\AccessControl\Agent\Service\AgentCredentialSink;
use Fight\AccessControl\Domain\AccessControl\Agent\Delivery\AgentDeliveryPolicy;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentCredentialOperation;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentIssuance;
use LogicException;

final readonly class DeliveryEnvironment
{
    public ProvisioningEnvironment $provisioning;

    public DeliveryClock $clock;

    public DeliveryUnitOfWork $transaction;

    public InMemoryAgentDeliveryAuthorization $authorization;

    public AdmittedAgentDeliveryDecipher $decipher;

    public InMemoryAgentCredentialSink $sink;

    public AgentIssuance $issuance;

    public function __construct()
    {
        $this->provisioning = new ProvisioningEnvironment();
        $this->issuance = $this->provisioning->service()->provision(
            $this->provisioning->key,
            $this->provisioning->request
        )->getIssuance() ?? throw new LogicException('Provisioning fixture failed.');
        $this->clock = new DeliveryClock();
        $this->transaction = new DeliveryUnitOfWork($this->provisioning->transaction);
        $this->authorization = new InMemoryAgentDeliveryAuthorization($this->provisioning);
        $this->decipher = new AdmittedAgentDeliveryDecipher($this);
        $this->sink = new InMemoryAgentCredentialSink($this->provisioning->transaction);
    }

    public function service(
        ?AgentDeliveryPolicy $policy = null,
        ?AgentCredentialSink $sink = null
    ): AgentCredentialDeliveryService {
        return new AgentCredentialDeliveryService(
            $this->provisioning->agents,
            $this->provisioning->operations,
            $this->provisioning->authorization,
            $this->authorization,
            $this->decipher,
            $sink ?? $this->sink,
            $this->clock,
            $this->transaction,
            $policy ?? new AgentDeliveryPolicy()
        );
    }

    /** @phpstan-impure */
    public function deliver(?AgentDeliveryPolicy $policy = null): AgentDeliveryResult
    {
        return $this->service($policy)->deliver(
            $this->provisioning->key,
            $this->provisioning->request->getDestination(),
            $this->issuance->getDeliveryId()
        );
    }

    public function operation(): AgentCredentialOperation
    {
        return $this->provisioning->operations->operations[$this->provisioning->key->toString()];
    }

    public function revoke(): void
    {
        new AgentCredentialLifecycleService(
            $this->provisioning->agents,
            $this->provisioning->audit,
            $this->clock,
            $this->provisioning->transaction,
            $this->provisioning->events
        )->revoke('maintainer-42', $this->issuance->getAgentId());
    }
}
