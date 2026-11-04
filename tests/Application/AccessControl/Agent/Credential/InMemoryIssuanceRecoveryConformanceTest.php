<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Credential;

use Fight\AccessControl\Application\AccessControl\Agent\Authentication\CurrentAgentPrincipalProvider;
use Fight\AccessControl\Application\AccessControl\Agent\Credential\AgentCredentialLifecycleService;
use Fight\AccessControl\Application\AccessControl\Agent\Credential\AgentCredentialRotationService;
use Fight\AccessControl\Application\AccessControl\Agent\Credential\AgentProvisioningService;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery\AgentCredentialDeliveryService;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery\AgentDeliveryRecoveryService;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Maintenance\AgentDeliveryMaintenanceService;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\QueryHandler\GetAgentOperationHandler;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\QueryHandler\ListDueAgentDeliveriesHandler;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentCredentialOperation;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Credential\Conformance\InMemoryIssuanceRecoveryFixture;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Credential\Conformance\IssuanceRecoveryFixture;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(AgentProvisioningService::class)]
#[CoversClass(AgentCredentialRotationService::class)]
#[CoversClass(AgentCredentialLifecycleService::class)]
#[CoversClass(CurrentAgentPrincipalProvider::class)]
#[CoversClass(GetAgentOperationHandler::class)]
#[CoversClass(ListDueAgentDeliveriesHandler::class)]
#[CoversClass(AgentCredentialDeliveryService::class)]
#[CoversClass(AgentDeliveryRecoveryService::class)]
#[CoversClass(AgentDeliveryMaintenanceService::class)]
#[CoversClass(AgentCredentialOperation::class)]
final class InMemoryIssuanceRecoveryConformanceTest extends IssuanceRecoveryConformance
{
    protected function newFixture(): IssuanceRecoveryFixture
    {
        return new InMemoryIssuanceRecoveryFixture();
    }
}
