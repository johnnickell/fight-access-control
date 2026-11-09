<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery;

use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\AccessControl\Application\AccessControl\Agent\CommandHandler\ProvisionAgentHandler;
use Fight\AccessControl\Application\AccessControl\Agent\CommandHandler\RotateAgentCredentialHandler;
use Fight\AccessControl\Application\AccessControl\Agent\Credential\AgentCredentialLifecycleService;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery\AgentCredentialDeliveryService;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery\AgentCredentialReceiptLookup;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery\AgentDeliveryRecoveryService;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery\AgentDeliveryResult;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Maintenance\AgentDeliveryMaintenanceService;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\QueryHandler\GetAgentOperationHandler;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\QueryHandler\ListDueAgentDeliveriesHandler;
use Fight\AccessControl\Domain\AccessControl\Agent\Command\ProvisionAgent;
use Fight\AccessControl\Domain\AccessControl\Agent\Command\RotateAgentCredential;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentCredentialDestination;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentIssuance;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationId;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationKey;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationLimits;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentProvisioningRequest;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentRotationRequest;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Delivery\AgentDeliveryPolicy;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Delivery\AgentDeliverySchedule;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Maintenance\AgentMaintenancePolicy;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Query\AgentOperationView;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Query\GetAgentOperation;
use Fight\Common\Adapter\Messaging\Query\Routing\InMemoryQueryRouter;
use Fight\Common\Adapter\Messaging\Query\RoutingQueryBus;
use Fight\Common\Domain\Messaging\Query\QueryMessage;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Credential\IssuanceBusFixture;
use Fight\Test\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery\Conformance\{
    DeliveryConformanceFixture
};
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
            $ports->agentRepository,
            $ports->operationRepository,
            $ports->operationAuthorization,
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

        $queryRouter = new InMemoryQueryRouter();
        $queryRouter->registerHandler(
            ListDueAgentDeliveriesHandler::queryRegistration(),
            new ListDueAgentDeliveriesHandler(
                $ports->operationReadRepository,
                $ports->deliveryAuthorization,
                $ports->clock
            )
        );

        return new AgentDeliveryRecoveryService(
            new RoutingQueryBus($queryRouter),
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
            $ports->operationRepository,
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
        $handler = new RotateAgentCredentialHandler(
            $ports->agentRepository,
            $ports->operationRepository,
            $ports->auditEvidenceRepository,
            $ports->operationAuthorization,
            $ports->generator,
            $ports->cipher,
            $ports->deliveryCipher,
            $ports->clock,
            $ports->transaction,
            $ports->eventDispatcher
        );
        $result = new IssuanceBusFixture($handler, $ports->operationReadRepository, $ports->operationAuthorization)
            ->dispatchAndRead(new RotateAgentCredential(
                new AgentOperationKey($old->getKey()->getScope(), AgentOperationId::generate()),
                new AgentRotationRequest(
                    $old->getAgentId(),
                    $old->getCredentialId(),
                    $old->getCredentialRevision(),
                    $destination ?? $old->getDestination()
                )
            ));
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
        $handler = new ProvisionAgentHandler(
            $ports->agentRepository,
            $ports->operationRepository,
            $ports->auditEvidenceRepository,
            $ports->operationAuthorization,
            $ports->generator,
            $ports->cipher,
            $ports->deliveryCipher,
            $ports->clock,
            $ports->transaction,
            $ports->eventDispatcher,
            $limits ?? new AgentOperationLimits()
        );
        $result = new IssuanceBusFixture($handler, $ports->operationReadRepository, $ports->operationAuthorization)
            ->dispatchAndRead(
                new ProvisionAgent($key, new AgentProvisioningRequest('Conformance Agent', $destination))
            );
        self::assertTrue($result->isConfirmed());
        $issuance = $result->getIssuance();
        self::assertNotNull($issuance);

        return $issuance;
    }

    protected function revoke(DeliveryConformanceFixture $fixture, AgentIssuance $issuance): void
    {
        $ports = $fixture->ports();
        new AgentCredentialLifecycleService(
            $ports->agentRepository,
            $ports->auditEvidenceRepository,
            $ports->clock,
            $ports->transaction,
            $ports->eventDispatcher
        )->revoke(UserId::generate(), $issuance->getAgentId());
    }

    protected function readOperation(
        DeliveryConformanceFixture $fixture,
        AgentIssuance $issuance
    ): AgentOperationView {
        $ports = $fixture->ports();
        $before = $fixture->counts();
        $view = new GetAgentOperationHandler($ports->operationReadRepository, $ports->operationAuthorization)->handle(
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
