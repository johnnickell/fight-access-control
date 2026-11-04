<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery;

use Fight\Test\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery\Conformance\{
    DeliveryConformanceFixture
};
use Fight\Test\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery\Conformance\{
    InMemoryDeliveryConformanceFixture
};
use Fight\AccessControl\Application\AccessControl\Agent\Credential\AgentCredentialLifecycleService;
use Fight\AccessControl\Application\AccessControl\Agent\Credential\AgentCredentialRotationService;
use Fight\AccessControl\Application\AccessControl\Agent\Credential\AgentProvisioningService;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery\AgentCredentialDeliveryService;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery\AgentDeliveryRecoveryService;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Maintenance\AgentDeliveryMaintenanceService;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\QueryHandler\GetAgentOperationHandler;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\QueryHandler\ListDueAgentDeliveriesHandler;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentCredentialOperation;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(AgentCredentialDeliveryService::class)]
#[CoversClass(AgentCredentialLifecycleService::class)]
#[CoversClass(AgentCredentialRotationService::class)]
#[CoversClass(AgentDeliveryMaintenanceService::class)]
#[CoversClass(AgentDeliveryRecoveryService::class)]
#[CoversClass(AgentProvisioningService::class)]
#[CoversClass(GetAgentOperationHandler::class)]
#[CoversClass(ListDueAgentDeliveriesHandler::class)]
#[CoversClass(AgentCredentialOperation::class)]
final class InMemoryDeliveryLifecycleConformanceTest extends DeliveryLifecycleConformance
{
    protected function newFixture(): DeliveryConformanceFixture
    {
        return new InMemoryDeliveryConformanceFixture();
    }
}
