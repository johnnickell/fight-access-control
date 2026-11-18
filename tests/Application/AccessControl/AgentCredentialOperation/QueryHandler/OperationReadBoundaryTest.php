<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\AgentCredentialOperation\QueryHandler;

use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\QueryHandler\GetAgentOperationHandler;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\QueryHandler\ListDueAgentDeliveriesHandler;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\QueryHandler\{
    ListAgentDeliveryMaintenanceHandler
};
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\QueryHandler\{
    CountAgentDeliveryKeyReferencesHandler
};
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentCredentialOperationReadRepository;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Maintenance\AgentDeliveryKeyVersion;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Query\AgentDeliveryWorkView;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Query\AgentOperationView;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Query\CountAgentDeliveryKeyReferences;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Query\GetAgentOperation;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Query\ListAgentDeliveryMaintenance;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Query\ListDueAgentDeliveries;
use Fight\Common\Adapter\Messaging\Query\Routing\InMemoryQueryRouter;
use Fight\Common\Adapter\Messaging\Query\RoutingQueryBus;
use Fight\Common\Domain\Messaging\Query\QueryMessage;
use Fight\Test\AccessControl\Application\AccessControl\AgentCredentialOperation\Maintenance\MaintenanceEnvironment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GetAgentOperationHandler::class)]
#[CoversClass(ListDueAgentDeliveriesHandler::class)]
#[CoversClass(ListAgentDeliveryMaintenanceHandler::class)]
#[CoversClass(CountAgentDeliveryKeyReferencesHandler::class)]
final class OperationReadBoundaryTest extends TestCase
{
    public function test_all_read_handlers_compose_with_only_secret_free_repository_obligations(): void
    {
        $env = new MaintenanceEnvironment();
        $delivery = $env->delivery;
        $provisioning = $delivery->provisioning;
        $view = AgentOperationView::fromOperation($delivery->operation());
        $item = AgentDeliveryWorkView::fromOperation($delivery->operation(), 1);
        $scope = $delivery->issuance->getKey()->getScope();
        $destination = $delivery->issuance->getDestination();
        $version = new AgentDeliveryKeyVersion('test-key-v1');
        $before = [
            $provisioning->operationRepository->operations,
            $provisioning->operationRepository->versions,
            $provisioning->transaction->transactions,
            $provisioning->auditEvidenceRepository->all(),
            $provisioning->eventDispatcher->events()
        ];
        // This implementation has no material-bearing lookup, reservation, claim or persistence methods.
        $operationReadRepository = $this->createMock(AgentCredentialOperationReadRepository::class);
        $operationReadRepository->expects(self::exactly(3))->method('getOperationContract')
            ->willReturn($provisioning->operationRepository->getOperationContract());
        $operationReadRepository->expects(self::once())->method('getStatusByKey')
            ->with($delivery->issuance->getKey())->willReturn($view);
        $operationReadRepository->expects(self::once())->method('listDueDeliveries')
            ->with($scope, $destination, $delivery->clock->now(), 50)->willReturn([$item]);
        $operationReadRepository->expects(self::once())->method('listMaintenance')->willReturn([$item]);
        $operationReadRepository->expects(self::once())->method('countDeliveryKeyReferences')
            ->with($version)->willReturn(1);

        $status = new GetAgentOperationHandler(
            operationReadRepository: $operationReadRepository,
            operationAuthorization: $provisioning->operationAuthorization
        );
        $due = new ListDueAgentDeliveriesHandler(
            operationReadRepository: $operationReadRepository,
            deliveryAuthorization: $delivery->deliveryAuthorization,
            clock: $delivery->clock
        );
        $maintenance = new ListAgentDeliveryMaintenanceHandler(
            operationReadRepository: $operationReadRepository,
            maintenanceAuthorization: $env,
            clock: $delivery->clock
        );
        $accounting = new CountAgentDeliveryKeyReferencesHandler(
            operationReadRepository: $operationReadRepository,
            maintenanceAuthorization: $env,
            clock: $delivery->clock
        );
        $router = new InMemoryQueryRouter();
        foreach ([$status, $due, $maintenance, $accounting] as $handler) {
            $router->registerHandler($handler::queryRegistration(), $handler);
        }

        $bus = new RoutingQueryBus($router);
        self::assertSame($view, $bus->dispatch(QueryMessage::create(new GetAgentOperation(
            $delivery->issuance->getKey(),
            $destination
        ))));
        self::assertEquals([$view], $bus->dispatch(QueryMessage::create(new ListDueAgentDeliveries(
            $scope,
            $destination
        ))));
        self::assertEquals([$view], $bus->dispatch(QueryMessage::create(new ListAgentDeliveryMaintenance(
            $scope,
            $destination
        ))));
        self::assertSame(1, $bus->dispatch(QueryMessage::create(new CountAgentDeliveryKeyReferences($version))));
        self::assertSame($before, [
            $provisioning->operationRepository->operations,
            $provisioning->operationRepository->versions,
            $provisioning->transaction->transactions,
            $provisioning->auditEvidenceRepository->all(),
            $provisioning->eventDispatcher->events()
        ]);
    }
}
