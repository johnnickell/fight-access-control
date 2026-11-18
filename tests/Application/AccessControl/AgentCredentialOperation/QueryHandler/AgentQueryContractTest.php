<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\AgentCredentialOperation\QueryHandler;

use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Exception\AgentOperationReadFailed;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\QueryHandler\{
    CountAgentDeliveryKeyReferencesHandler,
    GetAgentOperationHandler,
    ListAgentDeliveryMaintenanceHandler,
    ListDueAgentDeliveriesHandler
};
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentCredentialDisposition;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentCredentialOperationReadRepository;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentDeliveryDisposition;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentDeliveryId;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentIssuance;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationFailure;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationId;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationKey;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Exception\AgentOperationRejectedException;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Maintenance\AgentDeliveryKeyVersion;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Maintenance\AgentMaintenanceWork;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Query\AgentDeliveryWorkView;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Query\AgentOperationView;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Query\CountAgentDeliveryKeyReferences;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Query\GetAgentOperation;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Query\ListAgentDeliveryMaintenance;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Query\ListDueAgentDeliveries;
use Fight\Common\Adapter\Http\Error\SafeHttpErrorPolicy;
use Fight\Common\Adapter\Messaging\Query\Routing\InMemoryQueryRouter;
use Fight\Common\Adapter\Messaging\Query\RoutingQueryBus;
use Fight\Common\Application\Error\Exception\ServerError;
use Fight\Common\Application\Error\Exception\PermissionDenied;
use Fight\Common\Application\Error\Exception\ServiceUnavailable;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Credential\OperationFailureAssertions;
use Fight\Common\Domain\Messaging\Query\QueryMessage;
use Fight\Test\AccessControl\Application\AccessControl\AgentCredentialOperation\Maintenance\MaintenanceEnvironment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Throwable;

#[CoversClass(AgentOperationReadFailed::class)]
#[CoversClass(AgentDeliveryWorkView::class)]
#[CoversClass(GetAgentOperationHandler::class)]
#[CoversClass(ListDueAgentDeliveriesHandler::class)]
#[CoversClass(ListAgentDeliveryMaintenanceHandler::class)]
#[CoversClass(CountAgentDeliveryKeyReferencesHandler::class)]
final class AgentQueryContractTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function reads(): iterable
    {
        foreach (['status', 'due', 'maintenance', 'count'] as $read) {
            yield $read => [$read];
        }
    }

    /** @param 'status'|'due'|'maintenance'|'count' $read */
    #[DataProvider('reads')]
    public function test_unknown_provider_faults_are_safe_internal_failures_not_business_refusal(string $read): void
    {
        $env = new MaintenanceEnvironment();
        $repository = $this->createStub(AgentCredentialOperationReadRepository::class);
        $repository->method('getOperationContract')->willReturn(
            $env->delivery->provisioning->operationRepository->getOperationContract()
        );
        $method = match ($read) {
            'status' => 'getStatusByKey',
            'due' => 'listDueDeliveries',
            'maintenance' => 'listMaintenance',
            'count' => 'countDeliveryKeyReferences'
        };
        $repository->method($method)->willThrowException(new RuntimeException('PRIVATE diagnostic sentinel'));
        $key = $env->delivery->issuance->getKey();
        $destination = $env->delivery->issuance->getDestination();
        $clock = $env->delivery->clock;
        $handler = match ($read) {
            'status' => new GetAgentOperationHandler($repository, $env->delivery->provisioning->operationAuthorization),
            'due' => new ListDueAgentDeliveriesHandler($repository, $env->delivery->deliveryAuthorization, $clock),
            'maintenance' => new ListAgentDeliveryMaintenanceHandler($repository, $env, $clock),
            'count' => new CountAgentDeliveryKeyReferencesHandler($repository, $env, $clock)
        };
        $query = match ($read) {
            'status' => new GetAgentOperation($key, $destination),
            'due' => new ListDueAgentDeliveries($key->getScope(), $destination),
            'maintenance' => new ListAgentDeliveryMaintenance($key->getScope(), $destination),
            'count' => new CountAgentDeliveryKeyReferences(new AgentDeliveryKeyVersion('test-key-v1'))
        };
        try {
            $router = new InMemoryQueryRouter();
            $router->registerHandler($handler::queryRegistration(), $handler);
            new RoutingQueryBus($router)->dispatch(QueryMessage::create($query));
            self::fail('A failed read must not return success.');
        } catch (Throwable $throwable) {
            self::assertInstanceOf(ServerError::class, $throwable);
            self::assertNull($throwable->getPrevious());
            self::assertStringNotContainsString('PRIVATE', serialize($throwable));
            [$status, $envelope, $headers] = new SafeHttpErrorPolicy()->present($throwable);
            self::assertSame(500, $status);
            self::assertSame([], $headers);
            self::assertSame(['status' => 'error', 'message' => 'Internal server error.'], $envelope->toArray());
        }
    }

    /** @return iterable<string, array{string, AgentOperationFailure}> */
    public static function knownFailures(): iterable
    {
        foreach (['status', 'due', 'maintenance', 'count'] as $read) {
            foreach (AgentOperationFailure::cases() as $reason) {
                yield $read.':'.$reason->value => [$read, $reason];
            }
        }
    }

    #[DataProvider('knownFailures')]
    public function test_provider_classifications_preserve_only_intent_valid_at_the_read_boundary(
        string $read,
        AgentOperationFailure $reason
    ): void {
        $env = new MaintenanceEnvironment();
        $repository = $this->createStub(AgentCredentialOperationReadRepository::class);
        $repository->method('getOperationContract')->willReturn(
            $env->delivery->provisioning->operationRepository->getOperationContract()
        );
        $method = match ($read) {
            'status' => 'getStatusByKey',
            'due' => 'listDueDeliveries',
            'maintenance' => 'listMaintenance',
            default => 'countDeliveryKeyReferences'
        };
        $repository->method($method)->willThrowException(new AgentOperationRejectedException($reason));
        $key = $env->delivery->issuance->getKey();
        $destination = $env->delivery->issuance->getDestination();
        $handler = match ($read) {
            'status' => new GetAgentOperationHandler($repository, $env->delivery->provisioning->operationAuthorization),
            'due' => new ListDueAgentDeliveriesHandler(
                $repository,
                $env->delivery->deliveryAuthorization, $env->delivery->clock
            ),
            'maintenance' => new ListAgentDeliveryMaintenanceHandler($repository, $env, $env->delivery->clock),
            default => new CountAgentDeliveryKeyReferencesHandler($repository, $env, $env->delivery->clock)
        };
        $query = match ($read) {
            'status' => new GetAgentOperation($key, $destination),
            'due' => new ListDueAgentDeliveries($key->getScope(), $destination),
            'maintenance' => new ListAgentDeliveryMaintenance($key->getScope(), $destination),
            default => new CountAgentDeliveryKeyReferences(new AgentDeliveryKeyVersion('test-key-v1'))
        };
        $router = new InMemoryQueryRouter();
        $router->registerHandler($handler::queryRegistration(), $handler);
        try {
            new RoutingQueryBus($router)->fetch($query);
            self::fail('A classified read failure cannot return success.');
        } catch (AgentOperationRejectedException | PermissionDenied | ServiceUnavailable $failure) {
            self::assertContains($reason, [AgentOperationFailure::UNAUTHORIZED, AgentOperationFailure::UNAVAILABLE]);
            OperationFailureAssertions::assertMatches($reason, $failure);
            self::assertNull($failure->getPrevious());
        } catch (AgentOperationReadFailed $failure) {
            // Invalid decoded rows and write-only business failures are not ordinary Query refusals.
            self::assertNotContains($reason, [AgentOperationFailure::UNAUTHORIZED, AgentOperationFailure::UNAVAILABLE]);
            self::assertNull($failure->getPrevious());
        }
    }

    #[DataProvider('workReads')]
    public function test_ineligible_work_is_rejected_not_returned(string $read): void
    {
        $env = new MaintenanceEnvironment();
        $repository = $this->createStub(AgentCredentialOperationReadRepository::class);
        $repository->method('getOperationContract')->willReturn(
            $env->delivery->provisioning->operationRepository->getOperationContract()
        );
        $view = AgentOperationView::confirmed(
            2,
            $env->delivery->issuance,
            AgentDeliveryDisposition::DELIVERED,
            AgentCredentialDisposition::CURRENT
        );
        $item = new AgentDeliveryWorkView(
            $view,
            false,
            $env->delivery->clock->now(),
            $env->delivery->clock->now()->modify('+1 day'),
            false,
            1
        );
        $key = $view->getKey();
        $destination = $env->delivery->issuance->getDestination();
        if ($read === 'due') {
            $repository->method('listDueDeliveries')->willReturn([$item]);
            $handler = new ListDueAgentDeliveriesHandler(
                $repository,
                $env->delivery->deliveryAuthorization,
                $env->delivery->clock
            );
            $query = new ListDueAgentDeliveries($key->getScope(), $destination);
        } else {
            $repository->method('listMaintenance')->willReturn([$item]);
            $handler = new ListAgentDeliveryMaintenanceHandler($repository, $env, $env->delivery->clock);
            $query = new ListAgentDeliveryMaintenance($key->getScope(), $destination, AgentMaintenanceWork::CLEANUP);
        }

        $this->expectException(ServerError::class);
        $handler->handle(QueryMessage::create($query));
    }

    /** @return iterable<string, array{string}> */
    public static function workReads(): iterable
    {
        yield 'due' => ['due'];
        yield 'cleanup' => ['cleanup'];
    }

    /** @return iterable<string, array{string}> */
    public static function invalidDue(): iterable
    {
        foreach (
            ['map', 'element', 'missing time', 'future', 'before issuance', 'after retention', 'retention',
            'material', 'cleaned', 'reservation', 'missing reservation', 'retired', 'duplicate', 'order',
            'duplicate key', 'duplicate delivery'] as $case
        ) {
            yield $case => [$case];
        }
    }

    #[DataProvider('invalidDue')]
    public function test_due_selection_evidence_is_validated_before_any_batch_is_returned(string $case): void
    {
        $env = new MaintenanceEnvironment();
        $now = $env->delivery->clock->now();
        $view = AgentOperationView::fromOperation($env->delivery->operation());
        $due = match ($case) {
            'missing time' => null,
            'future' => $now->modify('+1 second'),
            'before issuance' => $now->modify('-1 second'),
            'after retention' => $now->modify('+2 days'),
            default => $now
        };
        if ($case === 'retired') {
            $view = AgentOperationView::confirmed(
                2,
                $env->delivery->issuance,
                AgentDeliveryDisposition::RETIRED,
                AgentCredentialDisposition::REVOKED
            );
        }

        $item = new AgentDeliveryWorkView(
            $view,
            $case !== 'material',
            $due,
            $case === 'retention' ? $now : $now->modify('+1 day'),
            $case === 'cleaned',
            match ($case) {
            'reservation' => 2, 'missing reservation' => null, default => 1
            }
        );
        $items = [$item];
        if ($case === 'map') {
            $items = [1 => $item];
        } elseif ($case === 'element') {
            $items = [new stdClass()];
        } elseif ($case === 'duplicate') {
            $items = [$item, $item];
        } elseif (in_array($case, ['order', 'duplicate key', 'duplicate delivery'], true)) {
            $payload = $env->delivery->issuance->toArray();
            if ($case !== 'duplicate key') {
                $payload['operation_id'] = AgentOperationId::generate()->toString();
            }

            if ($case !== 'duplicate delivery') {
                $payload['delivery_id'] = AgentDeliveryId::generate()->toString();
            }

            $other = AgentOperationView::confirmed(
                2,
                AgentIssuance::fromArray($payload),
                AgentDeliveryDisposition::PENDING,
                AgentCredentialDisposition::CURRENT
            );
            $otherItem = new AgentDeliveryWorkView(
                $other,
                true,
                $now,
                $now->modify('+1 day'),
                false,
                1
            );
            $items = [$item, $otherItem];
            if ($case === 'order') {
                usort($items, static fn (AgentDeliveryWorkView $left, AgentDeliveryWorkView $right): int => strcmp(
                    $right->getOperation()->getIssuance()->getDeliveryId()->toString(),
                    $left->getOperation()->getIssuance()->getDeliveryId()->toString()
                ));
            }
        }

        $repository = $this->createStub(AgentCredentialOperationReadRepository::class);
        $repository->method('getOperationContract')->willReturn(
            $env->delivery->provisioning->operationRepository->getOperationContract()
        );
        $repository->method('listDueDeliveries')->willReturn($items);
        $handler = new ListDueAgentDeliveriesHandler(
            $repository,
            $env->delivery->deliveryAuthorization,
            $env->delivery->clock
        );
        $this->expectException(AgentOperationReadFailed::class);
        $handler->handle(QueryMessage::create(new ListDueAgentDeliveries(
            $view->getKey()->getScope(),
            $env->delivery->issuance->getDestination()
        )));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidMaintenance(): iterable
    {
        foreach (
            ['map', 'element', 'destination', 'binding', 'retention', 'no material', 'cleanup material',
            'cleanup pending', 'cleanup early', 'cleanup cleaned', 'cleanup current delivered'] as $case
        ) {
            yield $case => [$case];
        }
    }

    #[DataProvider('invalidMaintenance')]
    public function test_maintenance_checks_exact_work_policy_and_binding(string $case): void
    {
        $env = new MaintenanceEnvironment();
        $now = $env->delivery->clock->now();
        $issuance = $env->delivery->issuance;
        $payload = $issuance->toArray();
        if ($case === 'destination') {
            $payload['destination_id'] = AgentDeliveryId::generate()->toString();
        } elseif ($case === 'binding') {
            $payload['destination_revision'] = 2;
        }

        $cleanup = str_starts_with($case, 'cleanup');
        $disposition = $cleanup ? AgentDeliveryDisposition::RETIRED : AgentDeliveryDisposition::PENDING;
        if ($case === 'cleanup pending') {
            $disposition = AgentDeliveryDisposition::PENDING;
        } elseif ($case === 'cleanup current delivered') {
            $disposition = AgentDeliveryDisposition::DELIVERED;
        }

        $view = AgentOperationView::confirmed(
            2,
            AgentIssuance::fromArray($payload),
            $disposition,
            AgentCredentialDisposition::CURRENT
        );
        $item = new AgentDeliveryWorkView(
            $view,
            $case === 'cleanup material' || (!$cleanup && $case !== 'no material'),
            null,
            $case === 'retention' ? $now : $now->modify('+1 day'),
            $case === 'cleanup cleaned',
            null
        );
        if ($cleanup && $case !== 'cleanup early') {
            $env->delivery->clock->advance(172800);
        }

        $items = match ($case) {
            'map' => [1 => $item], 'element' => [null], default => [$item]
        };
        $repository = $this->createStub(AgentCredentialOperationReadRepository::class);
        $repository->method('getOperationContract')->willReturn(
            $env->delivery->provisioning->operationRepository->getOperationContract()
        );
        $repository->method('listMaintenance')->willReturn($items);
        $handler = new ListAgentDeliveryMaintenanceHandler($repository, $env, $env->delivery->clock);
        $this->expectException(AgentOperationReadFailed::class);
        $handler->handle(QueryMessage::create(new ListAgentDeliveryMaintenance(
            $issuance->getKey()->getScope(),
            $issuance->getDestination(),
            $cleanup ? AgentMaintenanceWork::CLEANUP : AgentMaintenanceWork::MATERIAL
        )));
    }

    public function test_work_projection_preserves_safe_selection_facts_without_material_or_authority(): void
    {
        $env = new MaintenanceEnvironment();
        $operation = $env->delivery->operation();
        $item = AgentDeliveryWorkView::fromOperation($operation, 1);
        self::assertEquals(AgentOperationView::fromOperation($operation), $item->getOperation());
        self::assertSame($operation->getDeliveryDueAt(), $item->getDueAt());
        self::assertTrue($item->hasMaterial());
        self::assertFalse($item->isSinkCleaned());
        self::assertSame(1, $item->getCurrentDestinationWriteVersion());
        self::assertEquals($env->delivery->clock->now()->modify('+1 day'), $item->getRetentionEnd());
        foreach (['original-test-secret', 'ciphertext', 'test-key-v1', 'claim_id'] as $unsafe) {
            self::assertStringNotContainsString($unsafe, serialize($item));
        }
    }

    public function test_repository_wrong_key_is_internal_not_caller_denial(): void
    {
        $env = new MaintenanceEnvironment();
        $key = $env->delivery->issuance->getKey();
        $repository = $this->createStub(AgentCredentialOperationReadRepository::class);
        $repository->method('getStatusByKey')->willReturn(AgentOperationView::indeterminate(
            new AgentOperationKey($key->getScope(), AgentOperationId::generate())
        ));
        $handler = new GetAgentOperationHandler($repository, $env->delivery->provisioning->operationAuthorization);
        $this->expectException(ServerError::class);
        $handler->handle(QueryMessage::create(new GetAgentOperation($key, $env->delivery->issuance->getDestination())));
    }
}
