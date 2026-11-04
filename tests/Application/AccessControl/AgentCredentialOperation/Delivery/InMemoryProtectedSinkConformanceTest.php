<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery;

use Fight\Test\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery\Conformance\{
    DeliveryConformanceFixture
};
use Fight\Test\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery\Conformance\{
    InMemoryDeliveryConformanceFixture
};
use Fight\AccessControl\Application\AccessControl\Agent\CommandHandler\RotateAgentCredentialHandler;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery\AgentCredentialDeliveryService;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery\AgentCredentialInvocation;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery\AgentDeliveryRecoveryService;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Delivery\AgentDeliveryReceipt;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(AgentCredentialDeliveryService::class)]
#[CoversClass(AgentCredentialInvocation::class)]
#[CoversClass(RotateAgentCredentialHandler::class)]
#[CoversClass(AgentDeliveryRecoveryService::class)]
#[CoversClass(AgentDeliveryReceipt::class)]
final class InMemoryProtectedSinkConformanceTest extends ProtectedSinkConformance
{
    protected function newFixture(): DeliveryConformanceFixture
    {
        return new InMemoryDeliveryConformanceFixture();
    }
}
