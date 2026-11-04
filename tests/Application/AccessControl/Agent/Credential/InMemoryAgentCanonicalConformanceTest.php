<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Credential;

use Fight\Test\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery\Conformance\{
    InMemoryDeliveryConformanceFixture
};
use Fight\AccessControl\Application\AccessControl\Agent\Credential\AgentCredentialRotationService;
use Fight\AccessControl\Application\AccessControl\Agent\Credential\AgentProvisioningService;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\QueryHandler\GetAgentOperationHandler;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentCredentialOperation;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationCanonicalization;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationContract;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentProvisioningRequest;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentRotationRequest;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Query\AgentOperationView;
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
