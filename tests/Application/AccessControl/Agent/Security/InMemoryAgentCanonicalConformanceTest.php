<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Security;

use Fight\AccessControl\Application\AccessControl\Agent\QueryHandler\GetAgentOperationHandler;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentCredentialRotationService;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentProvisioningService;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentCredentialOperation;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationCanonicalization;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationContract;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentProvisioningRequest;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentRotationRequest;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\AgentOperationView;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\Conformance\InMemoryDeliveryConformanceFixture;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(AgentOperationCanonicalization::class)]
#[CoversClass(AgentOperationContract::class)]
#[CoversClass(AgentCredentialOperation::class)]
#[CoversClass(AgentProvisioningRequest::class)]
#[CoversClass(AgentRotationRequest::class)]
#[CoversClass(AgentOperationView::class)]
#[CoversClass(AgentProvisioningService::class)]
#[CoversClass(AgentCredentialRotationService::class)]
#[CoversClass(GetAgentOperationHandler::class)]
final class InMemoryAgentCanonicalConformanceTest extends AgentCanonicalConformance
{
    protected function newFixture(): InMemoryDeliveryConformanceFixture
    {
        return new InMemoryDeliveryConformanceFixture();
    }
}
