<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\QueryHandler;

use Closure;
use Fight\AccessControl\Application\AccessControl\Agent\QueryHandler\GetAgentOperationHandler;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentProvisioningResult;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentPublicationWarning;
use Fight\AccessControl\Application\AccessControl\Agent\Service\AgentOperationAuthorization;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentOperationRejectedException;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentCredentialDestination;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentCredentialDisposition;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentCredentialOperation;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentDeliveryDisposition;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentDestinationId;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationFailure;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationId;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationKey;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationLimits;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationRepository;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationScope;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\AgentOperationView;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\GetAgentOperation;
use Fight\Common\Domain\Messaging\Query\QueryMessage;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\ProvisioningEnvironment;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\UncertainAgentUnitOfWork;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SensitiveParameter;

#[CoversClass(GetAgentOperationHandler::class)]
#[CoversClass(AgentOperationView::class)]
#[CoversClass(GetAgentOperation::class)]
#[CoversClass(AgentCredentialOperation::class)]
final class GetAgentOperationHandlerTest extends TestCase
{
    public function test_lost_response_and_restart_read_original_issuance_without_repeating_any_effect(): void
    {
        $environment = new ProvisioningEnvironment();
        $environment->service()->provision($environment->key, $environment->request);
        $before = $this->effects($environment);
        $first = $this->read($environment);
        $second = $this->read($environment);
        self::assertSame(GetAgentOperation::class, GetAgentOperationHandler::queryRegistration());
        self::assertEquals($first, $second);
        self::assertTrue($second->isConfirmed());
        self::assertSame(
            $environment->operations->operations[$environment->key->toString()]->getIssuance(),
            $second->getIssuance()
        );
        self::assertSame(AgentDeliveryDisposition::PENDING, $second->getDeliveryDisposition());
        self::assertSame(AgentCredentialDisposition::CURRENT, $second->getCredentialDisposition());
        self::assertSame($before, $this->effects($environment));
        self::assertSame(2, $environment->operations->statusReads);
        self::assertSame(4, count($environment->authorization->readChecks));
        $this->assertSafe($second);
    }

    public function test_explicit_delegated_worker_retains_original_scope_and_target_authorization(): void
    {
        $environment = new ProvisioningEnvironment();
        $environment->service()->provision($environment->key, $environment->request);
        $original = $this->read($environment);
        $environment->authorization->actor = 'worker-7';
        $this->assertRejected(
            fn(): AgentOperationView => $this->read($environment),
            AgentOperationFailure::UNAUTHORIZED
        );
        $environment->authorization->readDelegations['worker-7:'.$environment->key->getScope()->toString()] = true;
        $workerView = $this->read($environment);
        self::assertEquals($original, $workerView);
        self::assertSame('maintainer-42', $workerView->getKey()->getScope()->getCallerId());
        $checks = $environment->authorization->readChecks;
        self::assertSame([
            'worker-7',
            $environment->key->getScope()->toString(),
            $original->getIssuance()->getAgentId()->toString()
        ], $checks[array_key_last($checks)]);
        $before = $this->effects($environment);
        $environment->authorization->delegationExpired = true;
        $this->assertRejected(
            fn(): AgentOperationView => $this->read($environment),
            AgentOperationFailure::UNAUTHORIZED
        );
        self::assertSame($before, $this->effects($environment));
    }

    /** @return iterable<string, array{string}> */
    public static function denials(): iterable
    {
        foreach (['namespace', 'caller', 'type', 'revoked', 'destination', 'binding', 'delegation'] as $case) {
            yield $case => [$case];
        }
    }

    #[DataProvider('denials')]
    public function test_denied_requests_conceal_existing_missing_and_terminal_state_before_lookup(string $case): void
    {
        foreach (['pending', 'terminal', 'missing'] as $state) {
            $environment = new ProvisioningEnvironment();
            $environment->service()->provision($environment->key, $environment->request);
            if ($state === 'missing') {
                $environment->operations->operations = [];
            } elseif ($state === 'terminal') {
                $this->persistDisposition(
                    $environment,
                    AgentDeliveryDisposition::TERMINAL,
                    AgentCredentialDisposition::REVOKED
                );
            }

            $key = $environment->key;
            $destination = $environment->request->getDestination();
            switch ($case) {
                case 'namespace':
                    $key = new AgentOperationKey(
                        new AgentOperationScope('other', 'user', 'maintainer-42'),
                        $key->getId()
                    );
                    break;
                case 'caller':
                    $key = new AgentOperationKey(new AgentOperationScope('consumer-a', 'user', 'other'), $key->getId());
                    break;
                case 'type':
                    $key = new AgentOperationKey(
                        new AgentOperationScope('consumer-a', 'agent', 'maintainer-42'),
                        $key->getId()
                    );
                    break;
                case 'revoked':
                    $environment->authorization->scopes[$key->getScope()->toString()] = false;
                    break;
                case 'destination':
                    $destination = new AgentCredentialDestination(AgentDestinationId::generate(), 1);
                    break;
                case 'binding':
                    $destination = new AgentCredentialDestination($destination->getId(), 2);
                    break;
                case 'delegation':
                    $environment->authorization->delegationExpired = true;
                    break;
            }

            $before = $this->effects($environment);
            $this->assertRejected(
                fn(): AgentOperationView => $this->read($environment, new GetAgentOperation($key, $destination)),
                AgentOperationFailure::UNAUTHORIZED
            );
            self::assertSame(0, $environment->operations->statusReads);
            self::assertSame($before, $this->effects($environment));
        }
    }

    public function test_target_denial_and_authority_changes_during_lookup_prevent_disclosure(): void
    {
        foreach (['target', 'scope', 'binding', 'delegation', 'absent-revocation'] as $case) {
            $environment = new ProvisioningEnvironment();
            $environment->service()->provision($environment->key, $environment->request);
            $issuance = $this->read($environment)->getIssuance();
            if ($case === 'target') {
                $environment->authorization->deniedTargets = [$issuance->getAgentId()->toString()];
                $this->persistDisposition(
                    $environment,
                    AgentDeliveryDisposition::RETIRED,
                    AgentCredentialDisposition::REVOKED
                );
            } else {
                if ($case === 'absent-revocation') {
                    $environment->operations->operations = [];
                }

                $environment->operations->afterStatusRead = static function () use ($environment, $case): void {
                    if ($case === 'binding') {
                        $environment->authorization->destinations = [];
                    } elseif ($case === 'delegation') {
                        $environment->authorization->delegationExpired = true;
                    } else {
                        $environment->authorization->scopes = [];
                    }
                };
            }

            $before = $this->effects($environment);
            $this->assertRejected(
                fn(): AgentOperationView => $this->read($environment),
                AgentOperationFailure::UNAUTHORIZED
            );
            self::assertSame($before, $this->effects($environment));
        }
    }

    public function test_persisted_disposition_fixtures_preserve_original_issuance_without_worker_claims(): void
    {
        $environment = new ProvisioningEnvironment();
        $environment->service()->provision($environment->key, $environment->request);
        $original = $this->read($environment)->getIssuance();
        foreach (AgentDeliveryDisposition::cases() as $delivery) {
            foreach (AgentCredentialDisposition::cases() as $credential) {
                // Controlled persisted fixtures, not proof of downstream delivery or lifecycle writers.
                $this->persistDisposition($environment, $delivery, $credential);
                $before = $this->effects($environment);
                $view = $this->read($environment);
                self::assertSame($original, $view->getIssuance());
                self::assertSame($delivery, $view->getDeliveryDisposition());
                self::assertSame($credential, $view->getCredentialDisposition());
                self::assertSame($before, $this->effects($environment));
                $this->assertSafe($view);
            }
        }
    }

    public function test_tombstones_and_unknown_versions_preserve_request_binding_without_canonicalization(): void
    {
        $environment = new ProvisioningEnvironment();
        $environment->service()->provision($environment->key, $environment->request);
        $stored = $environment->operations->operations[$environment->key->toString()];
        $environment->operations->operations[$environment->key->toString()] = $stored->retireMaterial();
        self::assertSame($stored->getIssuance(), $this->read($environment)->getIssuance());
        self::assertSame(AgentDeliveryDisposition::RETIRED, $this->read($environment)->getDeliveryDisposition());
        $unknown = new AgentCredentialOperation(
            99,
            'historical-not-current-canonical-json',
            $stored->getIssuance(),
            null
        );
        $environment->operations->operations[$environment->key->toString()] = $unknown;
        $before = $this->effects($environment);
        $this->assertRejected(
            fn(): AgentOperationView => $this->read($environment),
            AgentOperationFailure::UNSUPPORTED_VERSION
        );
        self::assertSame($unknown, $environment->operations->operations[$environment->key->toString()]);
        self::assertSame('historical-not-current-canonical-json', $unknown->getCanonicalRequest());
        self::assertSame($before, $this->effects($environment));
        $environment->authorization->deniedTargets = [$stored->getIssuance()->getAgentId()->toString()];
        $this->assertRejected(
            fn(): AgentOperationView => $this->read($environment),
            AgentOperationFailure::UNAUTHORIZED
        );
    }

    public function test_mismatched_repository_key_or_destination_never_exposes_other_operation(): void
    {
        foreach (['key', 'destination', 'binding'] as $case) {
            $environment = new ProvisioningEnvironment();
            $environment->service()->provision($environment->key, $environment->request);
            $query = new GetAgentOperation($environment->key, $environment->request->getDestination());
            if ($case === 'key') {
                $other = new ProvisioningEnvironment();
                $other->service()->provision($other->key, $other->request);
                $otherOperation = $other->operations->operations[$other->key->toString()];
                $environment->operations->operations[$environment->key->toString()] = $otherOperation;
            } else {
                $destination = new AgentCredentialDestination(AgentDestinationId::generate(), 1);
                if ($case === 'binding') {
                    $destination = new AgentCredentialDestination($query->getDestination()->getId(), 2);
                }

                $destinationId = $destination->getId()->toString();
                $environment->authorization->destinations[$destinationId] = $destination->getRevision();
                $query = new GetAgentOperation($environment->key, $destination);
            }

            $this->assertRejected(
                fn(): AgentOperationView => $this->read($environment, $query),
                AgentOperationFailure::UNAUTHORIZED
            );
        }
    }

    public function test_uncertain_commit_and_known_precommit_rejection_never_turn_absence_into_rollback(): void
    {
        foreach ([true, false] as $committed) {
            $environment = new ProvisioningEnvironment();
            $result = $environment->service(new UncertainAgentUnitOfWork($environment->transaction, $committed))
                ->provision($environment->key, $environment->request);
            self::assertFalse($result->isConfirmed());
            $before = $this->effects($environment);
            $view = $this->read($environment);
            self::assertSame($committed, $view->isConfirmed());
            self::assertSame($before, $this->effects($environment));
            if (!$committed) {
                self::assertSame('indeterminate', $view->toArray()['issuance_outcome']);
                self::assertNull($view->getIssuance());
                self::assertNull($view->getDeliveryDisposition());
            }

            $resolved = $environment->service()->provision($environment->key, $environment->request);
            self::assertEquals($resolved->getIssuance(), $this->read($environment)->getIssuance());
            self::assertCount(1, $environment->audit->all());
        }

        $environment = new ProvisioningEnvironment();
        $environment->operations->beforeAdd = static function (): never {
            throw new RuntimeException('private-storage-failure');
        };
        $this->assertRejected(
            fn(): AgentProvisioningResult => $environment->service()->provision(
                $environment->key,
                $environment->request
            ),
            AgentOperationFailure::UNAVAILABLE
        );
        $before = $this->effects($environment);
        self::assertFalse($this->read($environment)->isConfirmed());
        self::assertSame($before, $this->effects($environment));
        self::assertSame([], $environment->audit->all());
    }

    public function test_both_publishers_failing_cannot_hide_issuance_or_prove_delivery(): void
    {
        $environment = new ProvisioningEnvironment();
        $environment->events = new InMemoryEventDispatcher(static function (): never {
            throw new RuntimeException('private-publisher-failure');
        });
        $result = $environment->service()->provision($environment->key, $environment->request);
        self::assertSame(AgentPublicationWarning::PUBLICATION_FAILED, $result->getWarning());
        $before = $this->effects($environment);
        $view = $this->read($environment);
        self::assertSame($result->getIssuance(), $view->getIssuance());
        self::assertSame(AgentDeliveryDisposition::PENDING, $view->getDeliveryDisposition());
        self::assertSame($before, $this->effects($environment));
        self::assertSame([], $environment->events->events());
        $this->assertSafe($view);
    }

    public function test_new_work_capacity_and_override_validation_do_not_gate_existing_status(): void
    {
        $environment = new ProvisioningEnvironment();
        $service = $environment->service(limits: new AgentOperationLimits(128, 1, 1));
        $original = $service->provision($environment->key, $environment->request)->getIssuance();
        $this->assertRejected(
            fn(): AgentProvisioningResult => $service->provision(
                new AgentOperationKey($environment->key->getScope(), AgentOperationId::generate()),
                $environment->request
            ),
            AgentOperationFailure::CAPACITY
        );
        $before = $this->effects($environment);
        self::assertSame($original, $this->read($environment)->getIssuance());
        $this->assertRejected(
            fn(): AgentOperationLimits => new AgentOperationLimits(0, 0, 0),
            AgentOperationFailure::INVALID_REQUEST
        );
        self::assertSame($original, $this->read($environment)->getIssuance());
        self::assertSame($before, $this->effects($environment));
        self::assertSame($original, $service->provision($environment->key, $environment->request)->getIssuance());
    }

    public function test_storage_and_authorization_faults_are_sanitized_unavailable_not_absence(): void
    {
        foreach (['repository', 'authorization', 'typed-capacity', 'typed-unavailable', 'typed-version'] as $case) {
            $environment = new ProvisioningEnvironment();
            $repository = $this->createMock(AgentOperationRepository::class);
            $authorization = $this->createMock(AgentOperationAuthorization::class);
            $failure = new RuntimeException('private-provider-error /key/path raw-secret ciphertext');
            if ($case === 'typed-capacity') {
                $failure = new AgentOperationRejectedException(AgentOperationFailure::CAPACITY);
            } elseif ($case === 'typed-unavailable') {
                $failure = new AgentOperationRejectedException(AgentOperationFailure::UNAVAILABLE);
            } elseif ($case === 'typed-version') {
                // Repository failures cannot disclose versions before target authorization.
                $failure = new AgentOperationRejectedException(AgentOperationFailure::UNSUPPORTED_VERSION);
            }

            $authorization->expects(self::never())->method('authorize');
            $repository->expects(self::never())->method('add');
            $repository->expects(self::never())->method('reserveDestinationWrite');
            $repository->expects(self::never())->method('getByKey');
            if ($case === 'authorization') {
                $authorization->expects(self::once())->method('authorizeRead')->willThrowException($failure);
                $repository->expects(self::never())->method('getStatusByKey');
            } else {
                $authorization->expects(self::once())->method('authorizeRead');
                $repository->expects(self::once())->method('getStatusByKey')->willThrowException($failure);
            }

            $handler = new GetAgentOperationHandler($repository, $authorization);
            $message = QueryMessage::create(new GetAgentOperation(
                $environment->key,
                $environment->request->getDestination()
            ));
            $this->assertRejected(
                fn(): AgentOperationView => $handler->handle($message),
                AgentOperationFailure::UNAVAILABLE
            );
        }
    }

    private function read(
        #[SensitiveParameter] ProvisioningEnvironment $environment,
        ?GetAgentOperation $query = null
    ): AgentOperationView {
        $query ??= new GetAgentOperation($environment->key, $environment->request->getDestination());

        return new GetAgentOperationHandler($environment->operations, $environment->authorization)->handle(
            QueryMessage::create($query)
        );
    }

    private function persistDisposition(
        ProvisioningEnvironment $environment,
        AgentDeliveryDisposition $delivery,
        AgentCredentialDisposition $credential
    ): void {
        $operation = $environment->operations->operations[$environment->key->toString()];
        $environment->operations->operations[$environment->key->toString()] = new AgentCredentialOperation(
            $operation->getCanonicalVersion(),
            $operation->getCanonicalRequest(),
            $operation->getIssuance(),
            null,
            $delivery,
            $credential
        );
    }

    /** @return array<mixed> */
    private function effects(ProvisioningEnvironment $environment): array
    {
        return [
            $environment->operations->operations,
            $environment->operations->versions,
            $environment->audit->all(),
            $environment->events->events(),
            $environment->transaction->transactions,
            $environment->generations,
            $environment->agents->all()
        ];
    }

    private function assertRejected(#[SensitiveParameter] Closure $operation, AgentOperationFailure $reason): void
    {
        try {
            $operation();
            self::fail('Expected a safe rejection.');
        } catch (AgentOperationRejectedException $agentOperationRejectedException) {
            self::assertSame($reason, $agentOperationRejectedException->getReason());
            self::assertSame(
                'Agent operation rejected: '.$reason->value.'.',
                $agentOperationRejectedException->getMessage()
            );
            self::assertNull($agentOperationRejectedException->getPrevious());
            foreach (['private-provider', '/key/path', 'raw-secret', 'ciphertext'] as $unsafe) {
                self::assertStringNotContainsString($unsafe, (string) $agentOperationRejectedException);
                self::assertStringNotContainsString($unsafe, $agentOperationRejectedException->getMessage());
            }
        }
    }

    private function assertSafe(AgentOperationView $view): void
    {
        ob_start();
        var_dump($view);
        $debug = ob_get_clean();
        $output = $debug.serialize($view).json_encode($view->toArray(), JSON_THROW_ON_ERROR);
        $unsafeValues = ['original-test-secret', 'auth-envelope', 'ciphertext', 'key-v1', '/key/', 'canonicalRequest',
            'secret_digest', 'receipt', 'warning', 'activation', 'launch', 'read_handle'];
        foreach ($unsafeValues as $unsafe) {
            self::assertStringNotContainsString($unsafe, $output);
        }
    }
}
