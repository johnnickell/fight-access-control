<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Security;

use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentCredentialDeliveryService;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentCredentialInvocation;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentCredentialRotationService;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentDeliveryRecoveryService;
use Fight\AccessControl\Domain\AccessControl\Agent\Delivery\AgentDeliveryReceipt;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\Conformance\DeliveryConformanceFixture;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\Conformance\InMemoryDeliveryConformanceFixture;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(AgentCredentialDeliveryService::class)]
#[CoversClass(AgentCredentialInvocation::class)]
#[CoversClass(AgentCredentialRotationService::class)]
#[CoversClass(AgentDeliveryRecoveryService::class)]
#[CoversClass(AgentDeliveryReceipt::class)]
final class InMemoryProtectedSinkConformanceTest extends ProtectedSinkConformance
{
    protected function newFixture(): DeliveryConformanceFixture
    {
        return new InMemoryDeliveryConformanceFixture();
    }
}
