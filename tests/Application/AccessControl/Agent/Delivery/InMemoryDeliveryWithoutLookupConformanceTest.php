<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Delivery;

use Fight\AccessControl\Application\AccessControl\Agent\Credential\AgentCredentialLifecycleService;
use Fight\AccessControl\Application\AccessControl\Agent\Credential\AgentCredentialRotationService;
use Fight\AccessControl\Application\AccessControl\Agent\Credential\AgentProvisioningService;
use Fight\AccessControl\Application\AccessControl\Agent\Delivery\AgentCredentialDeliveryService;
use Fight\AccessControl\Application\AccessControl\Agent\Delivery\AgentDeliveryRecoveryService;
use Fight\AccessControl\Application\AccessControl\Agent\Maintenance\AgentDeliveryMaintenanceService;
use Fight\AccessControl\Application\AccessControl\Agent\QueryHandler\GetAgentOperationHandler;
use Fight\AccessControl\Application\AccessControl\Agent\QueryHandler\ListDueAgentDeliveriesHandler;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentCredentialOperation;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Delivery\Conformance\DeliveryConformanceFixture;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Delivery\Conformance\InMemoryDeliveryConformanceFixture;
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
final class InMemoryDeliveryWithoutLookupConformanceTest extends DeliveryLifecycleConformance
{
    protected function newFixture(): DeliveryConformanceFixture
    {
        return new InMemoryDeliveryConformanceFixture(receiptLookup: false);
    }
}
