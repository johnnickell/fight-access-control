<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Credential;

use Fight\AccessControl\Application\AccessControl\Agent\Credential\AgentCredentialLifecycleService;
use Fight\AccessControl\Application\AccessControl\Agent\Credential\AgentCredentialRotationService;
use Fight\AccessControl\Application\AccessControl\Agent\Credential\AgentProvisioningService;
use Fight\AccessControl\Application\AccessControl\Agent\Delivery\AgentCredentialDeliveryService;
use Fight\AccessControl\Application\AccessControl\Agent\Delivery\AgentDeliveryRecoveryService;
use Fight\AccessControl\Application\AccessControl\Agent\Maintenance\AgentDeliveryMaintenanceService;
use Fight\AccessControl\Application\AccessControl\Agent\QueryHandler\ListDueAgentDeliveriesHandler;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationContract;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Delivery\Conformance\InMemoryDeliveryConformanceFixture;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(AgentOperationContract::class)]
#[CoversClass(AgentProvisioningService::class)]
#[CoversClass(AgentCredentialRotationService::class)]
#[CoversClass(AgentCredentialLifecycleService::class)]
#[CoversClass(AgentCredentialDeliveryService::class)]
#[CoversClass(AgentDeliveryMaintenanceService::class)]
#[CoversClass(AgentDeliveryRecoveryService::class)]
#[CoversClass(ListDueAgentDeliveriesHandler::class)]
final class InMemoryAgentRestorationWithoutLookupTest extends AgentRestorationConformance
{
    protected function newFixture(): InMemoryDeliveryConformanceFixture
    {
        return new InMemoryDeliveryConformanceFixture(false);
    }
}
