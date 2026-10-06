<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Security;

use Fight\AccessControl\Application\AccessControl\Agent\QueryHandler\GetAgentOperationHandler;
use Fight\AccessControl\Application\AccessControl\Agent\QueryHandler\ListDueAgentDeliveriesHandler;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentCredentialDeliveryService;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentCredentialLifecycleService;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentCredentialRotationService;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentDeliveryMaintenanceService;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentDeliveryRecoveryService;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentDeliveryResult;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentProvisioningService;
use Fight\AccessControl\Application\AccessControl\Agent\Service\AgentCredentialReceiptLookup;
use Fight\AccessControl\Domain\AccessControl\Agent\Delivery\AgentDeliveryPolicy;
use Fight\AccessControl\Domain\AccessControl\Agent\Delivery\AgentDeliverySchedule;
use Fight\AccessControl\Domain\AccessControl\Agent\Maintenance\AgentMaintenancePolicy;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentCredentialDestination;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentIssuance;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationId;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationKey;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationLimits;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentProvisioningRequest;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentRotationRequest;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\AgentOperationView;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\GetAgentOperation;
use Fight\Common\Domain\Messaging\Query\QueryMessage;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\Conformance\DeliveryConformanceFixture;
use PHPUnit\Framework\TestCase;

/** Shared public-operation composition; consumer bindings supply adapters, not lifecycle decisions */
abstract class DeliveryConformance extends TestCase
{
    abstract protected function newFixture(): DeliveryConformanceFixture;

    protected function hasReceiptLookup(DeliveryConformanceFixture $fixture): bool
    {
        return $fixture->ports()->sink instanceof AgentCredentialReceiptLookup;
    }

    protected function delivery(
        DeliveryConformanceFixture $fixture,
        ?AgentDeliveryPolicy $policy = null
    ): AgentCredentialDeliveryService {
        $ports = $fixture->ports();

        return new AgentCredentialDeliveryService(
            $ports->agents,
            $ports->operations,
            $ports->authorization,
            $ports->deliveryAuthorization,
            $ports->decipher,
            $ports->sink,
            $ports->clock,
            $ports->transaction,
            $policy ?? new AgentDeliveryPolicy()
        );
    }

    protected function deliver(
        DeliveryConformanceFixture $fixture,
        AgentIssuance $issuance,
        ?AgentDeliveryPolicy $policy = null
    ): AgentDeliveryResult {
        return $this->delivery($fixture, $policy)->deliver(
            $issuance->getKey(),
            $issuance->getDestination(),
            $issuance->getDeliveryId()
        );
    }

    /** @return array<string, AgentDeliveryResult> */
    protected function recover(
        DeliveryConformanceFixture $fixture,
        AgentIssuance $issuance,
        ?AgentDeliverySchedule $schedule = null
    ): array {
        $ports = $fixture->ports();

        return new AgentDeliveryRecoveryService(
            new ListDueAgentDeliveriesHandler($ports->operations, $ports->deliveryAuthorization, $ports->clock),
            $this->delivery($fixture),
            $ports->clock,
            $schedule ?? new AgentDeliverySchedule()
        )->recover($issuance->getKey()->getScope(), $issuance->getDestination());
    }

    protected function maintenance(
        DeliveryConformanceFixture $fixture,
        ?AgentMaintenancePolicy $policy = null
    ): AgentDeliveryMaintenanceService {
        $ports = $fixture->ports();

        return new AgentDeliveryMaintenanceService(
            $ports->operations,
            $fixture->maintenanceAuthorization(),
            $fixture->rewrapper(),
            $fixture->cleanupSink(),
            $ports->clock,
            $ports->transaction,
            $policy ?? new AgentMaintenancePolicy()
        );
    }

    protected function rotate(
        DeliveryConformanceFixture $fixture,
        AgentIssuance $old,
        ?AgentCredentialDestination $destination = null
    ): AgentIssuance {
        $ports = $fixture->ports();
        $result = new AgentCredentialRotationService(
            $ports->agents,
            $ports->operations,
            $ports->audit,
            $ports->authorization,
            $ports->generator,
            $ports->cipher,
            $ports->deliveryCipher,
            $ports->clock,
            $ports->transaction,
            $ports->events
        )->rotate(
            new AgentOperationKey($old->getKey()->getScope(), AgentOperationId::generate()),
            new AgentRotationRequest(
                $old->getAgentId(),
                $old->getCredentialId(),
                $old->getCredentialRevision(),
                $destination ?? $old->getDestination()
            )
        );
        self::assertTrue($result->isConfirmed());
        $issuance = $result->getIssuance();
        self::assertNotNull($issuance);

        return $issuance;
    }

    protected function provision(
        DeliveryConformanceFixture $fixture,
        AgentOperationKey $key,
        AgentCredentialDestination $destination,
        ?AgentOperationLimits $limits = null
    ): AgentIssuance {
        $ports = $fixture->ports();
        $result = new AgentProvisioningService(
            $ports->agents,
            $ports->operations,
            $ports->audit,
            $ports->authorization,
            $ports->generator,
            $ports->cipher,
            $ports->deliveryCipher,
            $ports->clock,
            $ports->transaction,
            $ports->events,
            $limits ?? new AgentOperationLimits()
        )->provision($key, new AgentProvisioningRequest('Conformance Agent', $destination));
        self::assertTrue($result->isConfirmed());
        $issuance = $result->getIssuance();
        self::assertNotNull($issuance);

        return $issuance;
    }

    protected function revoke(DeliveryConformanceFixture $fixture, AgentIssuance $issuance): void
    {
        $ports = $fixture->ports();
        new AgentCredentialLifecycleService(
            $ports->agents,
            $ports->audit,
            $ports->clock,
            $ports->transaction,
            $ports->events
        )->revoke($issuance->getKey()->getScope()->getCallerId(), $issuance->getAgentId());
    }

    protected function readOperation(DeliveryConformanceFixture $fixture, AgentIssuance $issuance): AgentOperationView
    {
        $ports = $fixture->ports();
        $before = $fixture->counts();
        $view = new GetAgentOperationHandler($ports->operations, $ports->authorization)->handle(
            QueryMessage::create(new GetAgentOperation($issuance->getKey(), $issuance->getDestination()))
        );
        self::assertSame($before, $fixture->counts(), 'Status must not commit, materialize or publish.');
        self::assertEquals($issuance, $view->getIssuance());
        $this->assertSafe($fixture, [$view->toArray()]);

        return $view;
    }

    /** @param list<mixed> $values */
    protected function assertSafe(DeliveryConformanceFixture $fixture, array $values): void
    {
        foreach ([...$values, ...$fixture->safeEvidence()] as $value) {
            $surfaces = serialize($value).print_r($value, true).json_encode($value, JSON_THROW_ON_ERROR);
            foreach ($fixture->forbiddenValues() as $secret) {
                self::assertStringNotContainsString($secret, $surfaces);
            }
        }
    }
}
