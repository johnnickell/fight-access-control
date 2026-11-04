<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Credential;

use Fight\Test\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery\Conformance\{
    InMemoryDeliveryConformanceFixture
};
use Fight\AccessControl\Application\AccessControl\Agent\Credential\AgentCredentialLifecycleService;
use Fight\AccessControl\Application\AccessControl\Agent\Credential\AgentCredentialRotationService;
use Fight\AccessControl\Application\AccessControl\Agent\Credential\AgentProvisioningService;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery\AgentCredentialDeliveryService;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery\AgentDeliveryRecoveryService;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Maintenance\AgentDeliveryMaintenanceService;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\QueryHandler\ListDueAgentDeliveriesHandler;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationContract;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(AgentOperationContract::class)]
#[CoversClass(AgentProvisioningService::class)]
#[CoversClass(AgentCredentialRotationService::class)]
#[CoversClass(AgentCredentialLifecycleService::class)]
#[CoversClass(AgentCredentialDeliveryService::class)]
#[CoversClass(AgentDeliveryMaintenanceService::class)]
#[CoversClass(AgentDeliveryRecoveryService::class)]
#[CoversClass(ListDueAgentDeliveriesHandler::class)]
final class InMemoryAgentRestorationConformanceTest extends AgentRestorationConformance
{
    protected function newFixture(): InMemoryDeliveryConformanceFixture
    {
        return new InMemoryDeliveryConformanceFixture();
    }
}
