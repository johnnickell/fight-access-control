<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery;

use Fight\AccessControl\Application\AccessControl\Agent\Credential\AgentCredentialLifecycleService;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery\AgentCredentialDeliveryService;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery\AgentCredentialSink;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery\AgentDeliveryResult;
use Fight\AccessControl\Domain\AccessControl\Agent\Command\ProvisionAgent;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentCredentialOperation;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentIssuance;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Delivery\AgentDeliveryPolicy;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
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
        $this->issuance = $issuance ?? $this->provisioning->bus()->dispatchAndRead(new ProvisionAgent(
            $this->provisioning->key,
            $this->provisioning->request
        ))->getIssuance() ?? throw new LogicException('Provisioning fixture failed.');
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
            $this->provisioning->operationRepository,
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
        return $this->provisioning->operationRepository->operations[$this->provisioning->key->toString()];
    }

    public function revoke(): void
    {
        new AgentCredentialLifecycleService(
            $this->provisioning->agentRepository,
            $this->provisioning->auditEvidenceRepository,
            $this->clock,
            $this->provisioning->transaction,
            $this->provisioning->eventDispatcher
        )->revoke(UserId::generate(), $this->issuance->getAgentId());
    }
}
