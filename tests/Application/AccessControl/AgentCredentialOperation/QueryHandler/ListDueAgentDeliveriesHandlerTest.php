<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\AgentCredentialOperation\QueryHandler;

use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery\AgentDeliveryResult;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\QueryHandler\ListDueAgentDeliveriesHandler;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentCredentialDestination;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentCredentialDisposition;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentCredentialOperationReadRepository;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentDeliveryDisposition;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentDestinationId;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationFailure;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationId;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationKey;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationScope;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentProvisioningRequest;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Exception\AgentOperationRejectedException;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Query\AgentOperationView;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Query\ListDueAgentDeliveries;
use Fight\Common\Domain\Messaging\Query\QueryMessage;
use Fight\Test\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery\DeliveryEnvironment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(ListDueAgentDeliveriesHandler::class)]
final class ListDueAgentDeliveriesHandlerTest extends TestCase
{
    public function test_equal_due_times_select_only_current_reservation_with_safe_read_only_views(): void
    {
        $env = new DeliveryEnvironment();
        for ($index = 0; $index < 3; ++$index) {
            $env->provisioning->service()->provision(
                new AgentOperationKey($env->issuance->getKey()->getScope(), AgentOperationId::generate()),
                $env->provisioning->request
            );
        }

        $before = [$env->provisioning->transaction->transactions, $env->provisioning->auditEvidenceRepository->all(),
            $env->provisioning->operationRepository->operations, $env->provisioning->generations];
        $latest = array_last($env->provisioning->operationRepository->operations);
        self::assertNotNull($latest);
        $query = new ListDueAgentDeliveries($env->issuance->getKey()->getScope(), $env->issuance->getDestination(), 1);
        $handler = $this->handler($env);
        self::assertSame(ListDueAgentDeliveries::class, $handler::queryRegistration());
        $views = $handler->handle(QueryMessage::create($query));
        self::assertEquals([AgentOperationView::fromOperation($latest)], $views);
        self::assertEquals($views, $this->handler($env)->handle(QueryMessage::create($query)));
        self::assertSame(
            $before,
            [$env->provisioning->transaction->transactions, $env->provisioning->auditEvidenceRepository->all(),
            $env->provisioning->operationRepository->operations, $env->provisioning->generations]
        );
        self::assertSame(0, $env->decipher->calls);
        self::assertSame(0, $env->sink->calls);
        self::assertSame(0, $env->provisioning->operationRepository->deliveryWrites);
        self::assertSame(array_fill(0, 6, 'delivery-worker'), $env->deliveryAuthorization->discoveryWorkers);
        foreach ($views as $view) {
            self::assertSame('maintainer-42', $view->getKey()->getScope()->getCallerId());
            self::assertEquals($view, AgentOperationView::fromArray($view->toArray()));
        }

        ob_start();
        var_dump($views);
        $debug = ob_get_clean();
        self::assertIsString($debug);
        foreach (['original-test-secret', 'test-key-v1', 'ciphertext', 'claim_id', 'receipt'] as $unsafe) {
            self::assertStringNotContainsString($unsafe, serialize($views).$debug);
        }
    }

    public function test_discovery_excludes_other_bindings_and_waits_for_current_claim_expiry(): void
    {
        $env = new DeliveryEnvironment();
        $env->transaction->uncertainAt = 2;
        $env->deliver();
        $env->transaction->uncertainAt = null;

        $scope = $env->issuance->getKey()->getScope();
        $destination = $env->issuance->getDestination();
        $otherDestination = new AgentCredentialDestination(AgentDestinationId::generate(), 1);
        $env->provisioning->operationAuthorization->destinations[$otherDestination->getId()->toString()] = 1;
        $env->provisioning->service()->provision(
            new AgentOperationKey($scope, AgentOperationId::generate()),
            new AgentProvisioningRequest('Other destination', $otherDestination)
        );
        $message = QueryMessage::create(new ListDueAgentDeliveries($scope, $destination));
        self::assertSame([], $this->handler($env)->handle($message));
        $env->clock->advance(60);
        $due = $this->handler($env)->handle($message);
        self::assertSame([$env->issuance->getKey()], array_map(
            static fn(AgentOperationView $view): AgentOperationKey => $view->getKey(),
            $due
        ));
        self::assertSame(0, $env->decipher->calls);
        self::assertSame(1, $env->operation()->getStateRevision());
    }

    public function test_slot_reservation_exclusion_spans_scopes_bindings_and_completed_work(): void
    {
        $env = new DeliveryEnvironment();
        $scope = $env->issuance->getKey()->getScope();
        $destination = $env->issuance->getDestination();
        $otherScope = new AgentOperationScope('other-consumer', 'user', 'other-owner');
        $env->provisioning->operationAuthorization->scopes[$otherScope->toString()] = true;
        $other = $env->provisioning->service()->provision(
            new AgentOperationKey($otherScope, AgentOperationId::generate()),
            $env->provisioning->request
        )->getIssuance();
        self::assertNotNull($other);
        $originalQuery = QueryMessage::create(new ListDueAgentDeliveries($scope, $destination));
        self::assertSame([], $this->handler($env)->handle($originalQuery));
        $otherQuery = QueryMessage::create(new ListDueAgentDeliveries($otherScope, $destination));
        $otherViews = $this->handler($env)->handle($otherQuery);
        self::assertCount(1, $otherViews);
        self::assertSame($other, $otherViews[0]->getIssuance());

        $rebound = new AgentCredentialDestination($destination->getId(), 2);
        $env->provisioning->operationAuthorization->destinations[$rebound->getId()->toString()] = 2;
        $latest = $env->provisioning->service()->provision(
            new AgentOperationKey($scope, AgentOperationId::generate()),
            new AgentProvisioningRequest('Rebound destination', $rebound)
        )->getIssuance();
        self::assertNotNull($latest);
        self::assertSame(3, $latest->getDestinationWriteVersion());
        $reboundQuery = QueryMessage::create(new ListDueAgentDeliveries($scope, $rebound));
        self::assertSame($latest, $this->handler($env)->handle($reboundQuery)[0]->getIssuance());
        self::assertSame([], $this->handler($env)->handle(QueryMessage::create(
            new ListDueAgentDeliveries($otherScope, $rebound)
        )));
        self::assertSame(0, $env->provisioning->operationRepository->deliveryWrites);
        self::assertSame(0, $env->decipher->calls);

        self::assertSame(
            AgentDeliveryResult::DELIVERED,
            $env->service()->deliver($latest->getKey(), $rebound, $latest->getDeliveryId())
        );
        self::assertSame([], $this->handler($env)->handle($reboundQuery));
        // Even querying the original binding at the repository boundary cannot select a superseded write.
        self::assertSame([], $env->provisioning->operationRepository->listDueDeliveries(
            $scope,
            $destination,
            $env->clock->now(),
            50
        ));
        self::assertSame([], $env->provisioning->operationRepository->listDueDeliveries(
            $otherScope,
            $destination,
            $env->clock->now(),
            50
        ));
        self::assertSame(0, $env->operation()->getStateRevision());
        self::assertSame(1, $env->sink->calls);
    }

    public function test_rolled_back_reservation_does_not_hide_current_work(): void
    {
        $env = new DeliveryEnvironment();
        $env->provisioning->operationRepository->afterAdd = static function (): void {
            throw new RuntimeException('Issuance rollback after reservation.');
        };
        try {
            $env->provisioning->service()->provision(
                new AgentOperationKey($env->issuance->getKey()->getScope(), AgentOperationId::generate()),
                $env->provisioning->request
            );
            self::fail('Issuance must roll back.');
        } catch (AgentOperationRejectedException $agentOperationRejectedException) {
            self::assertSame(AgentOperationFailure::UNAVAILABLE, $agentOperationRejectedException->getReason());
        }

        self::assertEquals(
            [AgentOperationView::fromOperation($env->operation())],
            $this->handler($env)->handle(QueryMessage::create(
                new ListDueAgentDeliveries($env->issuance->getKey()->getScope(), $env->issuance->getDestination())
            ))
        );
        self::assertCount(1, $env->provisioning->operationRepository->operations);
        self::assertSame(0, $env->decipher->calls);
    }

    /** @return iterable<string, array{string}> */
    public static function deniedDiscovery(): iterable
    {
        $cases = [
            'scope', 'caller', 'destination', 'binding', 'permission', 'delegation', 'expiry', 'target', 'worker'
        ];
        foreach ($cases as $case) {
            yield $case => [$case];
        }
    }

    #[DataProvider('deniedDiscovery')]
    public function test_current_worker_scope_delegation_and_target_checks_conceal_work(string $case): void
    {
        $env = new DeliveryEnvironment();
        $scope = $env->issuance->getKey()->getScope();
        $destination = $env->issuance->getDestination();
        if ($case === 'scope' || $case === 'caller') {
            $scope = new AgentOperationScope(
                $case === 'scope' ? 'other' : $scope->getNamespace(),
                $scope->getCallerType(),
                $case === 'caller' ? 'other-caller' : $scope->getCallerId()
            );
        } elseif ($case === 'destination' || $case === 'binding') {
            $destination = new AgentCredentialDestination(
                $case === 'destination' ? AgentDestinationId::generate() : $destination->getId(),
                2
            );
        } elseif ($case === 'permission') {
            $env->deliveryAuthorization->permitted = false;
        } elseif ($case === 'delegation') {
            $env->deliveryAuthorization->delegated = false;
        } elseif ($case === 'expiry') {
            $env->deliveryAuthorization->expiresAt = $env->clock->now();
        } elseif ($case === 'target') {
            $env->deliveryAuthorization->targetAllowed = false;
        } else {
            $env->deliveryAuthorization->worker = '';
        }

        try {
            $this->handler($env)->handle(QueryMessage::create(new ListDueAgentDeliveries($scope, $destination)));
            self::fail('Unauthorized discovery must not return work.');
        } catch (AgentOperationRejectedException $agentOperationRejectedException) {
            self::assertSame(AgentOperationFailure::UNAUTHORIZED, $agentOperationRejectedException->getReason());
            self::assertNull($agentOperationRejectedException->getPrevious());
        }

        self::assertSame($case === 'target' ? 1 : 0, $env->provisioning->operationRepository->dueReads);
        self::assertSame(0, $env->transaction->commits);
        self::assertSame(0, $env->decipher->calls);
    }

    public function test_revocation_during_selection_conceals_even_an_empty_result(): void
    {
        foreach ([false, true] as $empty) {
            $env = new DeliveryEnvironment();
            if ($empty) {
                $env->revoke();
            }

            $env->provisioning->operationRepository->afterDueRead = static function () use ($env): void {
                $env->deliveryAuthorization->delegated = false;
            };
            try {
                $this->handler($env)->handle(QueryMessage::create(new ListDueAgentDeliveries(
                    $env->issuance->getKey()->getScope(),
                    $env->issuance->getDestination()
                )));
                self::fail('A revoked discovery allow must not disclose an empty or populated batch.');
            } catch (AgentOperationRejectedException $exception) {
                self::assertSame(AgentOperationFailure::UNAUTHORIZED, $exception->getReason());
            }
        }
    }

    /** @return iterable<string, array{string}> */
    public static function invalidReadResults(): iterable
    {
        foreach (['storage', 'oversized', 'unknown', 'scope', 'destination', 'version'] as $case) {
            yield $case => [$case];
        }
    }

    #[DataProvider('invalidReadResults')]
    public function test_unavailable_or_inconsistent_storage_is_not_empty_or_partial_work(string $case): void
    {
        $env = new DeliveryEnvironment();
        $operationReadRepository = $this->createStub(AgentCredentialOperationReadRepository::class);
        $operationReadRepository->method('getOperationContract')->willReturn(
            $env->provisioning->operationRepository->getOperationContract()
        );
        $view = AgentOperationView::fromOperation($env->operation());
        $queryScope = $env->issuance->getKey()->getScope();
        $queryDestination = $env->issuance->getDestination();
        if ($case === 'unknown') {
            $view = AgentOperationView::indeterminate($env->issuance->getKey());
        } elseif ($case === 'version') {
            $view = AgentOperationView::confirmed(
                99,
                $env->issuance,
                AgentDeliveryDisposition::PENDING,
                AgentCredentialDisposition::CURRENT
            );
        } elseif ($case === 'scope') {
            $queryScope = new AgentOperationScope('other', 'user', 'owner');
            $env->provisioning->operationAuthorization->scopes[$queryScope->toString()] = true;
        } elseif ($case === 'destination') {
            $queryDestination = new AgentCredentialDestination(AgentDestinationId::generate(), 1);
            $env->provisioning->operationAuthorization->destinations[$queryDestination->getId()->toString()] = 1;
        }

        if ($case === 'storage') {
            $operationReadRepository->method('listDueDeliveries')->willThrowException(
                new RuntimeException('secret /provider/key')
            );
        } else {
            $operationReadRepository->method('listDueDeliveries')->willReturn(
                $case === 'oversized' ? [$view, $view] : [$view]
            );
        }

        try {
            $this->handler($env, $operationReadRepository)->handle(QueryMessage::create(new ListDueAgentDeliveries(
                $queryScope,
                $queryDestination,
                1
            )));
            self::fail('Invalid storage must fail closed.');
        } catch (AgentOperationRejectedException $agentOperationRejectedException) {
            self::assertSame(
                $case === 'destination' ? AgentOperationFailure::UNAUTHORIZED : AgentOperationFailure::UNAVAILABLE,
                $agentOperationRejectedException->getReason()
            );
            self::assertNull($agentOperationRejectedException->getPrevious());
            self::assertStringNotContainsString('/provider/key', serialize($agentOperationRejectedException));
        }
    }

    private function handler(
        DeliveryEnvironment $env,
        ?AgentCredentialOperationReadRepository $operationReadRepository = null
    ): ListDueAgentDeliveriesHandler {
        return new ListDueAgentDeliveriesHandler(
            $operationReadRepository ?? $env->provisioning->operationRepository,
            $env->deliveryAuthorization,
            $env->clock
        );
    }
}
