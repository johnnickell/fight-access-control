<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery;

use Fight\AccessControl\Application\AccessControl\Agent\CommandHandler\ProvisionAgentHandler;
use Fight\AccessControl\Application\AccessControl\Agent\CommandHandler\RotateAgentCredentialHandler;
use Fight\AccessControl\Application\AccessControl\Agent\Credential\AgentCredentialLifecycleService;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery\AgentCredentialDeliveryService;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery\AgentDeliveryRecoveryService;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Maintenance\AgentDeliveryMaintenanceService;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\QueryHandler\GetAgentOperationHandler;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\QueryHandler\ListDueAgentDeliveriesHandler;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentCredentialOperation;
use Fight\Test\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery\Conformance\{
    DeliveryConformanceFixture,
    InMemoryDeliveryConformanceFixture
};
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(AgentCredentialDeliveryService::class)]
#[CoversClass(AgentCredentialLifecycleService::class)]
#[CoversClass(RotateAgentCredentialHandler::class)]
#[CoversClass(AgentDeliveryMaintenanceService::class)]
#[CoversClass(AgentDeliveryRecoveryService::class)]
#[CoversClass(ProvisionAgentHandler::class)]
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
