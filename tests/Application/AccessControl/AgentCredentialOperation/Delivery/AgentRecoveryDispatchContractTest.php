<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery;

use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery\AgentDeliveryRecoveryService;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery\AgentDeliveryResult;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Exception\AgentOperationReadFailed;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\QueryHandler\ListDueAgentDeliveriesHandler;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationFailure;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Delivery\AgentDeliverySchedule;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Exception\AgentOperationRejectedException;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Query\AgentOperationView;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Query\ListDueAgentDeliveries;
use Fight\Common\Adapter\Http\Error\SafeHttpErrorPolicy;
use Fight\Common\Adapter\Messaging\Query\Routing\InMemoryQueryRouter;
use Fight\Common\Adapter\Messaging\Query\RoutingQueryBus;
use Fight\Common\Application\Messaging\Query\QueryHandler;
use Fight\Common\Domain\Messaging\Query\QueryMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;

#[CoversClass(AgentDeliveryRecoveryService::class)]
final class AgentRecoveryDispatchContractTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function malformedResults(): iterable
    {
        $cases = ['null', 'scalar', 'map', 'element', 'indeterminate', 'scope', 'destination', 'duplicate', 'limit'];
        foreach ($cases as $case) {
            yield $case => [$case];
        }
    }

    #[DataProvider('malformedResults')]
    public function test_mixed_dispatch_results_fail_as_a_whole_before_any_delivery(string $case): void
    {
        $env = new DeliveryEnvironment();
        $view = AgentOperationView::fromOperation($env->operation());
        $other = new DeliveryEnvironment();
        $result = match ($case) {
            'null' => null,
            'scalar' => 1,
            'map' => ['item' => $view],
            'element' => [$view, 'invalid'],
            'indeterminate' => [$view, AgentOperationView::indeterminate($env->issuance->getKey())],
            'scope' => [$view, AgentOperationView::fromOperation($other->operation())],
            'destination' => [$view, $this->foreignDestination($env)],
            'duplicate' => [$view, $view],
            default => [$view, $view, $view]
        };
        $handler = $this->createMock(QueryHandler::class);
        $handler->expects(self::once())->method('handle')->willReturnCallback(
            static function (QueryMessage $message) use ($env, $result): mixed {
                $query = $message->payload();
                self::assertInstanceOf(ListDueAgentDeliveries::class, $query);
                self::assertSame($env->issuance->getKey()->getScope(), $query->getScope());
                self::assertSame($env->issuance->getDestination(), $query->getDestination());
                self::assertSame(2, $query->getLimit());

                return $result;
            }
        );
        $router = new InMemoryQueryRouter();
        $router->registerHandler(ListDueAgentDeliveriesHandler::queryRegistration(), $handler);
        try {
            new AgentDeliveryRecoveryService(
                new RoutingQueryBus($router),
                $env->service(),
                $env->clock,
                new AgentDeliverySchedule(2)
            )->recover($env->issuance->getKey()->getScope(), $env->issuance->getDestination());
            self::fail('A malformed dispatch result cannot look like empty or partial work.');
        } catch (AgentOperationReadFailed $agentOperationReadFailed) {
            self::assertSame(500, new SafeHttpErrorPolicy()->status($agentOperationReadFailed));
            self::assertNull($agentOperationReadFailed->getPrevious());
        }

        self::assertSame(0, $env->operation()->getStateRevision());
        self::assertSame(0, $env->decipher->calls);
        self::assertSame(0, $env->sink->calls);
        self::assertSame(0, $env->transaction->commits);
    }

    public function test_real_router_configuration_is_required_and_missing_route_is_not_empty_work(): void
    {
        $env = new DeliveryEnvironment();
        $this->expectException(AgentOperationReadFailed::class);
        new AgentDeliveryRecoveryService(
            new RoutingQueryBus(new InMemoryQueryRouter()),
            $env->service(),
            $env->clock
        )->recover($env->issuance->getKey()->getScope(), $env->issuance->getDestination());
    }

    /** @return iterable<string, array{AgentOperationFailure}> */
    public static function readFailures(): iterable
    {
        foreach (AgentOperationFailure::cases() as $reason) {
            yield $reason->value => [$reason];
        }
    }

    #[DataProvider('readFailures')]
    public function test_dispatched_read_failures_are_not_success_or_write_refusals(AgentOperationFailure $reason): void
    {
        $env = new DeliveryEnvironment();
        $handler = $this->createMock(QueryHandler::class);
        $handler->expects(self::once())->method('handle')->willThrowException(
            new AgentOperationRejectedException($reason)
        );
        $router = new InMemoryQueryRouter();
        $router->registerHandler(ListDueAgentDeliveries::class, $handler);
        try {
            new AgentDeliveryRecoveryService(
                new RoutingQueryBus($router),
                $env->service(),
                $env->clock
            )->recover($env->issuance->getKey()->getScope(), $env->issuance->getDestination());
            self::fail('A failed selection cannot return an empty map.');
        } catch (Throwable $throwable) {
            if (
                in_array($reason, [
                AgentOperationFailure::UNAUTHORIZED,
                AgentOperationFailure::UNAVAILABLE,
                AgentOperationFailure::UNSUPPORTED_VERSION
                ], true)
            ) {
                self::assertInstanceOf(AgentOperationRejectedException::class, $throwable);
                self::assertSame($reason, $throwable->getReason());
            } else {
                self::assertInstanceOf(AgentOperationReadFailed::class, $throwable);
            }

            self::assertNull($throwable->getPrevious());
        }

        self::assertSame(0, $env->transaction->commits);
    }

    public function test_real_registered_selection_keeps_delivery_results_separate_from_dispatch_acceptance(): void
    {
        $env = new DeliveryEnvironment();
        $router = new InMemoryQueryRouter();
        $router->registerHandler(
            ListDueAgentDeliveriesHandler::queryRegistration(),
            new ListDueAgentDeliveriesHandler(
                $env->provisioning->operationRepository,
                $env->deliveryAuthorization,
                $env->clock
            )
        );
        $service = new AgentDeliveryRecoveryService(new RoutingQueryBus($router), $env->service(), $env->clock);
        self::assertSame(
            [$env->issuance->getDeliveryId()->toString() => AgentDeliveryResult::DELIVERED],
            $service->recover($env->issuance->getKey()->getScope(), $env->issuance->getDestination())
        );
        self::assertSame([], $service->recover($env->issuance->getKey()->getScope(), $env->issuance->getDestination()));
        self::assertSame(2, $env->provisioning->operationRepository->dueReads);
        self::assertSame(3, $env->transaction->commits);
        self::assertSame(1, $env->provisioning->generations);
        self::assertNull($env->operation()->getMaterial());
    }

    private function foreignDestination(DeliveryEnvironment $env): AgentOperationView
    {
        $payload = AgentOperationView::fromOperation($env->operation())->toArray();
        self::assertNotNull($payload['issuance']);
        ++$payload['issuance']['destination_revision'];

        return AgentOperationView::fromArray($payload);
    }
}
