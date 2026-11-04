<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Delivery;

use Fight\AccessControl\Application\AccessControl\Agent\Credential\AgentCredentialLifecycleService;
use Fight\AccessControl\Application\AccessControl\Agent\Delivery\AgentCredentialDeliveryService;
use Fight\AccessControl\Application\AccessControl\Agent\Delivery\AgentCredentialSink;
use Fight\AccessControl\Application\AccessControl\Agent\Delivery\AgentDeliveryResult;
use Fight\AccessControl\Domain\AccessControl\Agent\Delivery\AgentDeliveryPolicy;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentCredentialOperation;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentIssuance;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Credential\ProvisioningEnvironment;
use LogicException;

final readonly class DeliveryEnvironment
{
    public ProvisioningEnvironment $provisioning;

    public DeliveryClock $clock;

    public DeliveryUnitOfWork $transaction;

    public InMemoryAgentDeliveryAuthorization $deliveryAuthorization;

    public AdmittedAgentDeliveryDecipher $decipher;

    public InMemoryAgentCredentialSink $sink;

    public AgentIssuance $issuance;

    public function __construct(?ProvisioningEnvironment $provisioning = null, ?AgentIssuance $issuance = null)
    {
        $this->provisioning = $provisioning ?? new ProvisioningEnvironment();
        $this->issuance = $issuance ?? $this->provisioning->service()->provision(
            $this->provisioning->key,
            $this->provisioning->request
        )->getIssuance() ?? throw new LogicException('Provisioning fixture failed.');
        $this->clock = new DeliveryClock();
        $this->transaction = new DeliveryUnitOfWork($this->provisioning->transaction);
        $this->deliveryAuthorization = new InMemoryAgentDeliveryAuthorization($this->provisioning);
        $this->decipher = new AdmittedAgentDeliveryDecipher($this);
        $this->sink = new InMemoryAgentCredentialSink($this->provisioning->transaction);
    }

    public function service(
        ?AgentDeliveryPolicy $policy = null,
        ?AgentCredentialSink $sink = null
    ): AgentCredentialDeliveryService {
        return new AgentCredentialDeliveryService(
            $this->provisioning->agentRepository,
            $this->provisioning->agentOperationRepository,
            $this->provisioning->operationAuthorization,
            $this->deliveryAuthorization,
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
        return $this->provisioning->agentOperationRepository->operations[$this->provisioning->key->toString()];
    }

    public function revoke(): void
    {
        new AgentCredentialLifecycleService(
            $this->provisioning->agentRepository,
            $this->provisioning->auditEvidenceRepository,
            $this->clock,
            $this->provisioning->transaction,
            $this->provisioning->eventDispatcher
        )->revoke('maintainer-42', $this->issuance->getAgentId());
    }
}
