<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\AgentCredentialOperation\QueryHandler;

use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Exception\AgentOperationReadFailed;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Maintenance\AgentMaintenanceAuthorization;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Maintenance\AgentMaintenanceResult;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\QueryHandler\{
    CountAgentDeliveryKeyReferencesHandler,
    ListAgentDeliveryMaintenanceHandler
};
use Fight\AccessControl\Domain\AccessControl\Agent\Command\ProvisionAgent;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentCredentialDisposition;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentCredentialOperation;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentCredentialOperationReadRepository;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentDeliveryDisposition;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationFailure;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationId;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationKey;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationScope;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Exception\AgentOperationRejectedException;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Maintenance\AgentDeliveryKeyVersion;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Maintenance\AgentMaintenanceWork;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Query\AgentDeliveryWorkView;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Query\AgentOperationView;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Query\CountAgentDeliveryKeyReferences;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Query\ListAgentDeliveryMaintenance;
use Fight\Common\Domain\Messaging\Query\QueryMessage;
use Fight\Test\AccessControl\Application\AccessControl\AgentCredentialOperation\Maintenance\MaintenanceEnvironment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(ListAgentDeliveryMaintenanceHandler::class)]
#[CoversClass(CountAgentDeliveryKeyReferencesHandler::class)]
final class AgentMaintenanceQueryTest extends TestCase
{
    public function test_keyset_pages_include_obsolete_reservations_without_starving_later_work_or_mutating(): void
    {
        $env = new MaintenanceEnvironment();
        $provisioning = $env->delivery->provisioning;
        for ($index = 0; $index < 51; ++$index) {
            $provisioning->bus()->commandBus->execute(new ProvisionAgent(
                new AgentOperationKey($provisioning->key->getScope(), AgentOperationId::generate()),
                $provisioning->request
            ));
        }

        $before = [
            $provisioning->transaction->transactions,
            $provisioning->auditEvidenceRepository->all(),
            $provisioning->operationRepository->operations
        ];
        $reads = $provisioning->operationRepository->reads;
        $handler = $this->handler($env);
        self::assertSame(ListAgentDeliveryMaintenance::class, $handler::queryRegistration());
        $first = $handler->handle(QueryMessage::create($this->query($env)));
        self::assertCount(50, $first);
        $cursor = $first[49]->getIssuance()?->getDeliveryId();
        $second = $handler->handle(QueryMessage::create(new ListAgentDeliveryMaintenance(
            $provisioning->key->getScope(),
            $provisioning->request->getDestination(),
            after: $cursor
        )));
        self::assertCount(2, $second);
        $ids = array_map(
            static fn (AgentOperationView $view): ?string => $view->getIssuance()?->getDeliveryId()->toString(),
            [...$first, ...$second]
        );
        self::assertCount(52, array_unique($ids));
        $sorted = $ids;
        sort($sorted, SORT_STRING);
        self::assertSame($sorted, $ids);
        self::assertSame($before, [
            $provisioning->transaction->transactions,
            $provisioning->auditEvidenceRepository->all(),
            $provisioning->operationRepository->operations
        ]);
        self::assertSame(0, $env->rewraps);
        self::assertSame($reads, $provisioning->operationRepository->reads);
        self::assertSame(0, $env->delivery->sink->cleanups);
        ob_start();
        var_dump($first);
        $debug = ob_get_clean();
        self::assertIsString($debug);
        foreach (['original-test-secret', 'ciphertext', 'test-key-v1', 'claim_id'] as $unsafe) {
            self::assertStringNotContainsString($unsafe, serialize($first).$debug);
        }
    }

    public function test_accounting_includes_other_scopes_unsupported_states_and_more_than_one_page(): void
    {
        $env = new MaintenanceEnvironment();
        $provisioning = $env->delivery->provisioning;
        $otherScope = new AgentOperationScope('other', 'user', 'other-maintainer');
        $provisioning->operationAuthorization->scopes[$otherScope->toString()] = true;
        $provisioning->operationAuthorization->readDelegations[
            $provisioning->operationAuthorization->actor.':'.$otherScope->toString()
        ] = true;
        for ($index = 0; $index < 51; ++$index) {
            $provisioning->bus()->commandBus->execute(new ProvisionAgent(
                new AgentOperationKey($otherScope, AgentOperationId::generate()),
                $provisioning->request
            ));
        }

        $lastKey = array_key_last($provisioning->operationRepository->operations);
        self::assertNotNull($lastKey);
        $last = $provisioning->operationRepository->operations[$lastKey];
        $provisioning->operationRepository->operations[$lastKey] = new AgentCredentialOperation(
            99,
            $last->getCanonicalRequest(),
            $last->getIssuance(),
            $last->getMaterial()
        );
        $handler = $this->counter($env);
        self::assertSame(CountAgentDeliveryKeyReferences::class, $handler::queryRegistration());
        $message = QueryMessage::create(new CountAgentDeliveryKeyReferences(
            new AgentDeliveryKeyVersion('test-key-v1')
        ));
        $transactions = $provisioning->transaction->transactions;
        self::assertSame(52, $handler->handle($message));
        self::assertSame($transactions, $provisioning->transaction->transactions);
        $unused = new CountAgentDeliveryKeyReferences(new AgentDeliveryKeyVersion('not-used'));
        self::assertSame(0, $handler->handle(QueryMessage::create($unused)));
        self::assertSame(AgentMaintenanceResult::REWRAPPED, $env->rewrapOriginal());
        self::assertSame(51, $handler->handle($message));
        self::assertSame(1, $provisioning->operationRepository->maintenanceWrites);
    }

    public function test_cleanup_selection_excludes_ineligible_and_acknowledged_work_without_changing_state(): void
    {
        $env = new MaintenanceEnvironment();
        $message = QueryMessage::create(new ListAgentDeliveryMaintenance(
            $env->delivery->issuance->getKey()->getScope(),
            $env->delivery->issuance->getDestination(),
            AgentMaintenanceWork::CLEANUP
        ));
        self::assertSame([], $this->handler($env)->handle($message));
        $env->delivery->revoke();
        $env->delivery->clock->advance(172800);
        self::assertCount(1, $this->handler($env)->handle($message));
        self::assertFalse($env->delivery->operation()->isSinkCleaned());
        self::assertSame(AgentMaintenanceResult::CLEANED, $env->cleanup());
        self::assertSame([], $this->handler($env)->handle($message));
        self::assertSame([], $this->handler($env)->handle(QueryMessage::create($this->query($env))));
    }

    /** @return iterable<string, array{string}> */
    public static function deniedReads(): iterable
    {
        yield 'initial scope denial' => ['initial'];
        yield 'authority revoked after selection' => ['after'];
        yield 'target denial' => ['target'];
        yield 'storage failure' => ['storage'];
    }

    #[DataProvider('deniedReads')]
    public function test_read_failures_disclose_no_partial_page_or_provider_diagnostics(string $case): void
    {
        $env = new MaintenanceEnvironment();
        if ($case === 'initial') {
            $env->permitted = false;
        } elseif ($case === 'target') {
            $env->targetPermitted = false;
        } else {
            $operationReadRepository = $env->delivery->provisioning->operationRepository;
            $operationReadRepository->afterMaintenanceRead = static function () use ($env, $case): void {
                if ($case === 'storage') {
                    throw new RuntimeException('original-test-secret /unsafe/provider/key');
                }

                $env->permitted = false;
            };
        }

        try {
            $this->handler($env)->handle(QueryMessage::create($this->query($env)));
            self::fail('Unauthorized/failed read must throw.');
        } catch (AgentOperationReadFailed $failure) {
            self::assertSame('storage', $case);
            self::assertNull($failure->getPrevious());
            self::assertStringNotContainsString('original-test-secret', serialize($failure));
        } catch (AgentOperationRejectedException $failure) {
            self::assertNotSame('storage', $case);
            self::assertSame(AgentOperationFailure::UNAUTHORIZED, $failure->getReason());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function malformedPages(): iterable
    {
        foreach (['too many', 'unconfirmed', 'wrong scope', 'unknown version', 'duplicate', 'at cursor'] as $case) {
            yield $case => [$case];
        }
    }

    #[DataProvider('malformedPages')]
    public function test_inconsistent_adapter_projection_fails_closed(string $case): void
    {
        $env = new MaintenanceEnvironment();
        $view = AgentOperationView::fromOperation($env->delivery->operation());
        $query = $this->query($env);
        $views = [$view];
        if ($case === 'too many') {
            $views = array_fill(0, 51, $view);
        } elseif ($case === 'unconfirmed') {
            $views = [AgentOperationView::indeterminate($view->getKey())];
        } elseif ($case === 'wrong scope') {
            $query = new ListAgentDeliveryMaintenance(
                new AgentOperationScope('other', 'user', 'other'),
                $env->delivery->issuance->getDestination()
            );
            // A deliberately permissive authorizer below isolates the adapter-scope validation.
        } elseif ($case === 'unknown version') {
            $views = [AgentOperationView::confirmed(
                99,
                $env->delivery->issuance,
                AgentDeliveryDisposition::PENDING,
                AgentCredentialDisposition::CURRENT
            )];
        } elseif ($case === 'duplicate') {
            $views = [$view, $view];
        } else {
            $query = new ListAgentDeliveryMaintenance(
                $query->getScope(),
                $query->getDestination(),
                after: $env->delivery->issuance->getDeliveryId()
            );
        }

        $operationReadRepository = $this->createStub(AgentCredentialOperationReadRepository::class);
        $operationReadRepository->method('getOperationContract')
            ->willReturn($env->delivery->provisioning->operationRepository->getOperationContract());
        $items = array_map(fn (AgentOperationView $view): AgentDeliveryWorkView => new AgentDeliveryWorkView(
            $view,
            true,
            null,
            $env->delivery->clock->now()->modify('+1 day'),
            false,
            null
        ), $views);
        $operationReadRepository->method('listMaintenance')->willReturn($items);
        $maintenanceAuthorization = $env;
        if ($case === 'wrong scope') {
            $maintenanceAuthorization = $this->createStub(AgentMaintenanceAuthorization::class);
        }

        $handler = new ListAgentDeliveryMaintenanceHandler(
            $operationReadRepository,
            $maintenanceAuthorization,
            $env->delivery->clock
        );
        $this->expectException(
            $case === 'unknown version' ? AgentOperationRejectedException::class : AgentOperationReadFailed::class
        );
        $handler->handle(QueryMessage::create($query));
    }

    /** @return iterable<string, array{string}> */
    public static function accountingFailures(): iterable
    {
        foreach (['initial denial', 'revoked after count', 'storage', 'negative count'] as $case) {
            yield $case => [$case];
        }
    }

    #[DataProvider('accountingFailures')]
    public function test_accounting_failure_never_becomes_zero_or_retirement_permission(string $case): void
    {
        $env = new MaintenanceEnvironment();
        $operationReadRepository = $env->delivery->provisioning->operationRepository;
        if ($case === 'initial denial') {
            $env->accountingPermitted = false;
        } elseif ($case === 'revoked after count') {
            $operationReadRepository->afterMaintenanceRead = static function () use ($env): void {
                $env->accountingPermitted = false;
            };
        } elseif ($case === 'negative count') {
            $operationReadRepository = $this->createStub(AgentCredentialOperationReadRepository::class);
            $operationReadRepository->method('getOperationContract')
                ->willReturn($env->delivery->provisioning->operationRepository->getOperationContract());
            $operationReadRepository->method('countDeliveryKeyReferences')->willReturn(-1);
        } else {
            $operationReadRepository->afterMaintenanceRead = static function (): void {
                throw new RuntimeException('unsafe key path');
            };
        }

        $handler = new CountAgentDeliveryKeyReferencesHandler(
            $operationReadRepository,
            $env,
            $env->delivery->clock
        );
        try {
            $query = new CountAgentDeliveryKeyReferences(new AgentDeliveryKeyVersion('test-key-v1'));
            $handler->handle(QueryMessage::create($query));
            self::fail('Failed count must not return zero.');
        } catch (AgentOperationReadFailed $failure) {
            self::assertContains($case, ['storage', 'negative count']);
            self::assertNull($failure->getPrevious());
        } catch (AgentOperationRejectedException $failure) {
            self::assertContains($case, ['initial denial', 'revoked after count']);
            self::assertSame(AgentOperationFailure::UNAUTHORIZED, $failure->getReason());
        }
    }

    private function query(MaintenanceEnvironment $env): ListAgentDeliveryMaintenance
    {
        return new ListAgentDeliveryMaintenance(
            $env->delivery->issuance->getKey()->getScope(),
            $env->delivery->issuance->getDestination()
        );
    }

    /** @phpstan-impure */
    private function handler(MaintenanceEnvironment $env): ListAgentDeliveryMaintenanceHandler
    {
        return new ListAgentDeliveryMaintenanceHandler(
            $env->delivery->provisioning->operationRepository,
            $env,
            $env->delivery->clock
        );
    }

    private function counter(MaintenanceEnvironment $env): CountAgentDeliveryKeyReferencesHandler
    {
        return new CountAgentDeliveryKeyReferencesHandler(
            $env->delivery->provisioning->operationRepository,
            $env,
            $env->delivery->clock
        );
    }
}
