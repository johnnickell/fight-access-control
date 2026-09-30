<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Security;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentProvisioningResult;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentProvisioningService;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentPublicationWarning;
use Fight\AccessControl\Application\AccessControl\Agent\Service\AgentDeliveryCipher;
use Fight\AccessControl\Application\AccessControl\Agent\Service\HmacSharedSecretCipher;
use Fight\AccessControl\Application\AccessControl\Agent\Service\HmacSharedSecretGenerator;
use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentRepository;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentState;
use Fight\AccessControl\Domain\AccessControl\Agent\Event\AgentProvisioned;
use Fight\AccessControl\Domain\AccessControl\Agent\Event\AgentProvisioningFailed;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentCredentialException;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentNameException;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentOperationCollisionException;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentOperationRejectedException;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentCredentialDestination;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentCredentialOperation;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentDestinationId;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentIssuance;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationFailure;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationId;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationKey;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationLimits;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationScope;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentProvisioningRequest;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Application\Repository\TransactionalUnitOfWork;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Repository\InMemoryAgentRepository;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\BoundAgentDeliveryCipher;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\ProvisioningEnvironment;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\UncertainAgentUnitOfWork;
use Fight\Test\AccessControl\Application\AccessControl\Audit\Repository\InMemoryAuditEvidenceRepository;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use TypeError;

#[CoversClass(AgentProvisioningService::class)]
#[CoversClass(AgentProvisioningResult::class)]
#[CoversClass(Agent::class)]
#[CoversClass(AgentNameException::class)]
#[CoversClass(AgentOperationRejectedException::class)]
#[CoversClass(AgentOperationCollisionException::class)]
#[CoversClass(AgentCredentialOperation::class)]
#[CoversClass(AgentProvisioningFailed::class)]
#[CoversClass(AgentProvisioned::class)]
#[CoversClass(AuditEvidence::class)]
final class AgentProvisioningServiceTest extends TestCase
{
    public function test_it_commits_one_complete_issuance_before_publication_and_returns_no_secret(): void
    {
        $environment = new ProvisioningEnvironment();
        $environment->events = new InMemoryEventDispatcher(function () use ($environment): void {
            self::assertTrue($environment->transaction->transactionCompleted);
            self::assertFalse($environment->transaction->transactionActive);
            self::assertFalse($environment->authorization->locked);
            self::assertCount(1, $environment->agents->all());
            self::assertCount(1, $environment->operations->operations);
            self::assertCount(1, $environment->audit->all());
        });
        $result = $environment->service()->provision($environment->key, $environment->request);
        self::assertTrue($result->isConfirmed());
        self::assertNull($result->getWarning());
        $issuance = $result->getIssuance();
        self::assertInstanceOf(AgentIssuance::class, $issuance);
        $agent = $environment->agents->all()[0];
        self::assertSame('Production deployment', $agent->getName()->toString());
        self::assertSame(AgentState::ACTIVE, $agent->getState());
        self::assertTrue($agent->hasRecoverableCredentialOperation());
        self::assertSame($agent->getId(), $issuance->getAgentId());
        self::assertSame($agent->getCredentialId(), $issuance->getCredentialId());
        self::assertSame(0, $issuance->getCredentialRevision());
        self::assertSame(1, $issuance->getDestinationWriteVersion());
        self::assertSame('auth-envelope:original-test-secret', $agent->getEncryptedHmacSharedSecretEnvelope());
        $operation = $environment->operations->operations[$environment->key->toString()];
        self::assertSame('original-test-secret', new BoundAgentDeliveryCipher()->inspect(
            $operation->getMaterial(),
            $issuance
        ));
        self::assertSame('test-key-v1', $operation->getMaterial()->getKeyVersion());
        self::assertSame($environment->key, $issuance->getKey());
        self::assertSame($environment->request->getDestination(), $issuance->getDestination());
        self::assertSame('maintainer-42', $environment->audit->all()[0]->actorId());
        self::assertSame('agent.provisioned', $environment->audit->all()[0]->action());
        self::assertSame($agent->getId(), $environment->audit->all()[0]->subjectId());
        self::assertSame([], $environment->audit->all()[0]->context());
        $event = $environment->events->events()[0];
        self::assertInstanceOf(AgentProvisioned::class, $event);
        self::assertSame($agent->getId(), $event->getAgentId());
        self::assertSame($agent->getCredentialId(), $event->getCredentialId());
        self::assertSame(0, $event->getCredentialRevision());
        self::assertEquals($issuance->getIssuedAt(), $event->getProvisionedAt());
        self::assertStringNotContainsString('original-test-secret', serialize($result));
        self::assertStringNotContainsString('auth-envelope', serialize($event));
        self::assertArrayNotHasKey('delivered', $issuance->toArray());
        self::assertArrayNotHasKey('activation', $issuance->toArray());
        self::assertArrayNotHasKey('launch', $issuance->toArray());
    }

    public function test_lost_response_and_service_restart_resolve_without_generation_audit_or_another_fact(): void
    {
        $environment = new ProvisioningEnvironment();
        $environment->service()->provision($environment->key, $environment->request);
        $stored = $environment->operations->operations[$environment->key->toString()];
        $retried = $environment->service()->provision($environment->key, new AgentProvisioningRequest(
            'Production deployment',
            $environment->request->getDestination()
        ));
        self::assertSame($stored->getIssuance(), $retried->getIssuance());
        self::assertSame(1, $environment->generations);
        self::assertCount(1, $environment->agents->all());
        self::assertCount(1, $environment->audit->all());
        self::assertCount(1, $environment->events->events());
        self::assertSame(2, $environment->authorization->calls);
    }

    /** @return iterable<string, array{bool}> */
    public static function commitOutcomes(): iterable
    {
        yield 'committed but lost acknowledgement' => [true];
        yield 'rolled back but lost acknowledgement' => [false];
    }

    #[DataProvider('commitOutcomes')]
    public function test_uncertain_commit_never_guesses_and_restart_resolves_the_original_key(bool $committed): void
    {
        $environment = new ProvisioningEnvironment();
        $result = $environment->service(new UncertainAgentUnitOfWork($environment->transaction, $committed))
            ->provision($environment->key, $environment->request);
        self::assertFalse($result->isConfirmed());
        self::assertNull($result->getIssuance());
        self::assertNull($result->getWarning());
        self::assertCount((int) $committed, $environment->operations->operations);
        self::assertCount((int) $committed, $environment->agents->all());
        self::assertCount((int) $committed, $environment->audit->all());
        self::assertInstanceOf(AgentProvisioningFailed::class, $environment->events->events()[0]);
        $original = $environment->operations->operations[$environment->key->toString()] ?? null;
        $resolved = $environment->service()->provision($environment->key, $environment->request);
        self::assertTrue($resolved->isConfirmed());
        self::assertCount(1, $environment->operations->operations);
        self::assertCount(1, $environment->agents->all());
        self::assertCount(1, $environment->audit->all());
        self::assertSame($committed ? 1 : 2, $environment->generations);
        if ($original !== null) {
            self::assertSame($original->getIssuance(), $resolved->getIssuance());
        }
    }

    /** @return iterable<string, array{bool}> */
    public static function publicationFailures(): iterable
    {
        yield 'success publisher fails' => [false];
        yield 'both publishers fail' => [true];
    }

    #[DataProvider('publicationFailures')]
    public function test_publication_failure_preserves_committed_work_and_returns_only_a_typed_warning(bool $both): void
    {
        $environment = new ProvisioningEnvironment();
        $environment->events = new InMemoryEventDispatcher(static function (object $event) use ($both): void {
            if ($both || $event instanceof AgentProvisioned) {
                throw new RuntimeException('Unsafe provider data original-test-secret and key/path.');
            }
        });
        $result = $environment->service()->provision($environment->key, $environment->request);
        self::assertTrue($result->isConfirmed());
        self::assertSame(AgentPublicationWarning::PUBLICATION_FAILED, $result->getWarning());
        self::assertStringNotContainsString('Unsafe', serialize($result));
        self::assertStringNotContainsString('original-test-secret', serialize($environment->events->events()));
        self::assertCount(1, $environment->operations->operations);
        self::assertCount(1, $environment->audit->all());
        $retry = $environment->service()->provision($environment->key, $environment->request);
        self::assertSame($result->getIssuance(), $retry->getIssuance());
        self::assertNull($retry->getWarning());
        self::assertSame(1, $environment->generations);
    }

    /** @return iterable<string, array{string}> */
    public static function failures(): iterable
    {
        foreach (['generator', 'authentication cipher', 'delivery cipher', 'agent', 'operation', 'audit'] as $stage) {
            yield $stage => [$stage];
        }
    }

    #[DataProvider('failures')]
    public function test_precommit_faults_roll_back_every_record_and_never_expose_provider_details(string $stage): void
    {
        $environment = new ProvisioningEnvironment();
        $failure = new RuntimeException('Unsafe provider error: original-test-secret, encrypted secret, key/path.');
        $generator = null;
        $cipher = null;
        $delivery = null;
        $agents = null;
        $audit = null;
        if ($stage === 'generator') {
            $generator = $this->createMock(HmacSharedSecretGenerator::class);
            $generator->expects(self::once())->method('generate')->willThrowException($failure);
        } elseif ($stage === 'authentication cipher') {
            $cipher = $this->createMock(HmacSharedSecretCipher::class);
            $cipher->expects(self::once())->method('encrypt')->willThrowException($failure);
        } elseif ($stage === 'delivery cipher') {
            $delivery = $this->createMock(AgentDeliveryCipher::class);
            $delivery->expects(self::once())->method('encrypt')->willThrowException($failure);
        } elseif ($stage === 'agent') {
            $agents = $this->createMock(AgentRepository::class);
            $agents->method('getOperationContract')->willReturn($environment->agents->getOperationContract());
            $agents->expects(self::once())->method('add')->willReturnCallback(function (Agent $agent) use (
                $environment,
                $failure
            ): void {
                $environment->agents->add($agent);
                throw $failure;
            });
        } elseif ($stage === 'operation') {
            $environment->operations->afterAdd = static function () use ($failure): void {
                throw $failure;
            };
        } else {
            $audit = new InMemoryAuditEvidenceRepository($environment->transaction, failAfterSave: true);
        }

        $service = $environment->service(
            generator: $generator,
            cipher: $cipher,
            deliveryCipher: $delivery,
            audit: $audit,
            agents: $agents
        );
        $exception = $this->assertRejected(
            fn(): AgentProvisioningResult => $service->provision($environment->key, $environment->request),
            AgentOperationFailure::UNAVAILABLE
        );
        self::assertNull($exception->getPrevious());
        self::assertStringNotContainsString('original-test-secret', (string) $exception);
        self::assertStringNotContainsString('key/path', (string) $exception);
        self::assertSame([], $environment->agents->all());
        self::assertSame([], $environment->operations->operations);
        self::assertSame([], $environment->operations->versions);
        self::assertSame([], ($audit ?? $environment->audit)->all());
        $this->assertSafeFailure($environment);
    }

    public function test_invalid_name_and_failed_failure_publication_leave_no_partial_state(): void
    {
        $environment = new ProvisioningEnvironment();
        $environment->events = new InMemoryEventDispatcher(static function (): void {
            throw new RuntimeException('Failure publisher is down.');
        });
        $this->assertRejected(
            fn(): AgentProvisioningResult => $environment->service()->provision(
                $environment->key,
                new AgentProvisioningRequest('   ', $environment->request->getDestination())
            ),
            AgentOperationFailure::INVALID_REQUEST
        );
        self::assertSame(0, $environment->generations);
        self::assertSame([], $environment->operations->versions);
        self::assertSame([], $environment->events->events());
    }

    /** @return iterable<string, array{string, bool}> */
    public static function authorityFailures(): iterable
    {
        $cases = ['namespace', 'caller', 'type', 'revoked', 'expired delegation', 'destination', 'audit identity'];
        foreach ($cases as $case) {
            yield $case.' new' => [$case, false];
            yield $case.' retry' => [$case, true];
        }
    }

    #[DataProvider('authorityFailures')]
    public function test_current_authority_denies_before_lookup(string $case, bool $retry): void
    {
        $environment = new ProvisioningEnvironment();
        if ($retry) {
            $environment->service()->provision($environment->key, $environment->request);
        }

        $key = $environment->key;
        $scope = $key->getScope();
        if (in_array($case, ['namespace', 'caller', 'type'], true)) {
            $key = new AgentOperationKey(new AgentOperationScope(
                $case === 'namespace' ? 'other' : $scope->getNamespace(),
                $case === 'type' ? 'agent' : $scope->getCallerType(),
                $case === 'caller' ? 'other' : $scope->getCallerId()
            ), $key->getId());
        } elseif ($case === 'revoked') {
            $environment->authorization->scopes[$scope->toString()] = false;
        } elseif ($case === 'expired delegation') {
            $environment->authorization->delegationExpired = true;
        } elseif ($case === 'destination') {
            $environment->authorization->destinations = [];
        } else {
            $environment->authorization->actor = 'unsafe/actor';
        }

        $this->assertRejected(
            fn(): AgentProvisioningResult => $environment->service()->provision($key, $environment->request),
            $case === 'audit identity' ? AgentOperationFailure::UNAVAILABLE : AgentOperationFailure::UNAUTHORIZED
        );
        self::assertSame((int) $retry, $environment->operations->reads);
        self::assertSame((int) $retry, $environment->generations);
        self::assertCount((int) $retry, $environment->agents->all());
        self::assertCount((int) $retry, $environment->audit->all());
    }

    public function test_authorization_writer_waits_for_issuance_and_next_retry_observes_revocation(): void
    {
        $environment = new ProvisioningEnvironment();
        $environment->authorization->actor = 'delegated-worker-7';
        $environment->authorization->afterAuthorization = function () use ($environment): void {
            $environment->authorization->changeAuthority(function () use ($environment): void {
                $environment->authorization->scopes = [];
            });
            self::assertNotEmpty($environment->authorization->scopes);
        };
        $result = $environment->service()->provision($environment->key, $environment->request);
        self::assertTrue($result->isConfirmed());
        self::assertSame($environment->key, $result->getIssuance()->getKey());
        self::assertSame('delegated-worker-7', $environment->audit->all()[0]->actorId());
        self::assertSame([], $environment->authorization->scopes);
        $this->assertRejected(
            fn(): AgentProvisioningResult => $environment->service()->provision(
                $environment->key,
                $environment->request
            ),
            AgentOperationFailure::UNAUTHORIZED
        );
    }

    public function test_authorization_participant_failure_rolls_back_its_own_writes(): void
    {
        $environment = new ProvisioningEnvironment();
        $participantWrites = [];
        $environment->authorization->afterAuthorization = function () use ($environment, &$participantWrites): void {
            $participantWrites[] = 'transactional-consumer-evidence';
            $environment->transaction->onRollback(static function () use (&$participantWrites): void {
                $participantWrites = [];
            });
            throw new RuntimeException('Unsafe authority capability details.');
        };
        $this->assertRejected(
            fn(): AgentProvisioningResult => $environment->service()->provision(
                $environment->key,
                $environment->request
            ),
            AgentOperationFailure::UNAVAILABLE
        );
        self::assertSame([], $participantWrites);
        self::assertSame([], $environment->agents->all());
        self::assertSame([], $environment->operations->operations);
        self::assertSame([], $environment->operations->versions);
        self::assertFalse($environment->authorization->locked);
        self::assertSame(0, $environment->operations->reads);
    }

    public function test_cross_scope_ids_and_reassignment_preserve_delivery_identity_and_slot_order(): void
    {
        $environment = new ProvisioningEnvironment();
        $first = $environment->service()->provision($environment->key, $environment->request)->getIssuance();
        $scope = new AgentOperationScope('consumer-b', 'agent', 'worker-1');
        $environment->authorization->scopes[$scope->toString()] = true;
        $destination = new AgentCredentialDestination($environment->request->getDestination()->getId(), 2);
        $environment->authorization->destinations[$destination->getId()->toString()] = 2;
        $second = $environment->service()->provision(
            new AgentOperationKey($scope, $environment->key->getId()),
            new AgentProvisioningRequest('Other Agent', $destination)
        )->getIssuance();
        self::assertFalse($first->getDeliveryId()->equals($second->getDeliveryId()));
        self::assertFalse($first->getAgentId()->equals($second->getAgentId()));
        self::assertSame(2, $second->getDestinationWriteVersion());
        self::assertSame(2, $second->getDestination()->getRevision());
        self::assertCount(2, $environment->operations->operations);
    }

    /** @return iterable<string, array{string}> */
    public static function conflicts(): iterable
    {
        foreach (['name', 'destination', 'revision', 'kind', 'version'] as $case) {
            yield $case => [$case];
        }
    }

    #[DataProvider('conflicts')]
    public function test_changed_binding_or_unknown_version_rejects_without_generation(string $case): void
    {
        $environment = new ProvisioningEnvironment();
        $environment->service()->provision($environment->key, $environment->request);
        $request = $environment->request;
        $destination = $request->getDestination();
        $stored = $environment->operations->operations[$environment->key->toString()];
        if ($case === 'name') {
            $request = new AgentProvisioningRequest('Other name', $destination);
        } elseif ($case === 'destination' || $case === 'revision') {
            $destination = new AgentCredentialDestination(
                $case === 'destination' ? AgentDestinationId::generate() : $destination->getId(),
                2
            );
            $environment->authorization->destinations[$destination->getId()->toString()] = 2;
            $request = new AgentProvisioningRequest($request->getName(), $destination);
        } else {
            $canonical = $stored->getCanonicalRequest();
            if ($case === 'kind') {
                $canonical = str_replace('provision', 'rotate', $canonical);
            }

            $environment->operations->operations[$environment->key->toString()] = new AgentCredentialOperation(
                $case === 'version' ? 99 : $stored->getCanonicalVersion(),
                $canonical,
                $stored->getIssuance(),
                $stored->getMaterial()
            );
        }

        $this->assertRejected(
            fn(): AgentProvisioningResult => $environment->service()->provision($environment->key, $request),
            $case === 'version' ? AgentOperationFailure::UNSUPPORTED_VERSION : AgentOperationFailure::CONFLICT
        );
        self::assertSame(1, $environment->generations);
        self::assertCount(1, $environment->agents->all());
        self::assertCount(1, $environment->audit->all());
    }

    public function test_capacity_rejects_new_work_but_preserves_resolution_under_lowered_limits_and_tombstones(): void
    {
        $environment = new ProvisioningEnvironment();
        $longRequest = new AgentProvisioningRequest(
            str_repeat(' ', 200).'Agent',
            $environment->request->getDestination()
        );
        $first = $environment->service()->provision($environment->key, $longRequest);
        $service = $environment->service(limits: new AgentOperationLimits(128, 1, 1));
        self::assertSame($first->getIssuance(), $service->provision($environment->key, $longRequest)->getIssuance());
        $this->assertRejected(
            fn(): AgentProvisioningResult => $service->provision(
                new AgentOperationKey($environment->key->getScope(), AgentOperationId::generate()),
                $environment->request
            ),
            AgentOperationFailure::CAPACITY
        );
        self::assertCount(1, $environment->agents->all());
        self::assertCount(1, $environment->audit->all());
        self::assertSame([1], array_values($environment->operations->versions));
        $key = $environment->key->toString();
        $environment->operations->operations[$key] = $environment->operations->operations[$key]->retireMaterial();
        self::assertSame($first->getIssuance(), $service->provision($environment->key, $longRequest)->getIssuance());
        self::assertNull($environment->operations->operations[$key]->getMaterial());
        self::assertCount(1, array_filter(
            $environment->events->events(),
            static fn(object $event): bool => $event instanceof AgentProvisioned
        ));
    }

    /** @return iterable<string, array{bool}> */
    public static function collisionRequests(): iterable
    {
        yield 'identical concurrent requests' => [false];
        yield 'conflicting concurrent requests' => [true];
    }

    #[DataProvider('collisionRequests')]
    public function test_unique_loser_rolls_back_then_resolves_or_conflicts_with_winner(bool $conflict): void
    {
        $environment = new ProvisioningEnvironment();
        $winner = new ProvisioningEnvironment();
        $winner->authorization->scopes[$environment->key->getScope()->toString()] = true;
        $winner->authorization->destinations[$environment->request->getDestination()->getId()->toString()] = 1;
        $winnerRequest = new AgentProvisioningRequest(
            $conflict ? 'Other winner' : 'Production deployment',
            $environment->request->getDestination()
        );
        $winner->service()->provision($environment->key, $winnerRequest);
        $winningOperation = $winner->operations->operations[$environment->key->toString()];
        $environment->operations->beforeAdd = function () use ($environment, $winner, $winningOperation): void {
            $environment->transaction->onCompletion(function () use ($environment, $winner, $winningOperation): void {
                // The independent winner becomes visible only after the loser's transaction has rolled back.
                self::assertSame([], $environment->agents->all());
                self::assertSame([], $environment->operations->versions);
                $environment->operations->operations[$environment->key->toString()] = $winningOperation;
                $environment->operations->versions = $winner->operations->versions;
                $environment->agents->add($winner->agents->all()[0]);
                $environment->audit->add($winner->audit->all()[0]);
            });
            throw new AgentOperationCollisionException();
        };
        if ($conflict) {
            $this->assertRejected(
                fn(): AgentProvisioningResult => $environment->service()->provision(
                    $environment->key,
                    $environment->request
                ),
                AgentOperationFailure::CONFLICT
            );
        } else {
            $result = $environment->service()->provision($environment->key, $environment->request);
            self::assertSame($winningOperation->getIssuance(), $result->getIssuance());
            self::assertSame([], $environment->events->events());
        }

        self::assertSame(2, $environment->transaction->transactions);
        self::assertSame(2, $environment->authorization->calls);
        self::assertCount(1, $environment->agents->all());
        self::assertCount(1, $environment->audit->all());
        self::assertCount(1, $environment->operations->operations);
        self::assertSame([1], array_values($environment->operations->versions));
    }

    public function test_missing_collision_winner_is_bounded_retryable_contention_not_automatic_reissuance(): void
    {
        $environment = new ProvisioningEnvironment();
        $environment->operations->beforeAdd = static function (): void {
            throw new AgentOperationCollisionException();
        };
        $exception = $this->assertRejected(
            fn(): AgentProvisioningResult => $environment->service()->provision(
                $environment->key,
                $environment->request
            ),
            AgentOperationFailure::CONTENTION
        );
        self::assertTrue($exception->isRetryable());
        self::assertSame(2, $environment->transaction->transactions);
        self::assertSame(1, $environment->generations);
        self::assertSame([], $environment->agents->all());
        self::assertSame([], $environment->operations->operations);
        self::assertSame([], $environment->operations->versions);
    }

    public function test_closed_transaction_capability_fails_before_lookup_or_generation(): void
    {
        $environment = new ProvisioningEnvironment();
        $closed = $this->createMock(TransactionalUnitOfWork::class);
        $closed->method('isClosed')->willReturn(true);
        $closed->expects(self::never())->method('commitTransactional');
        $this->assertRejected(
            fn(): AgentProvisioningResult => $environment->service($closed)->provision(
                $environment->key,
                $environment->request
            ),
            AgentOperationFailure::UNAVAILABLE
        );
        self::assertSame(0, $environment->operations->reads);
        self::assertSame(0, $environment->generations);
    }

    public function test_legacy_raw_return_signature_is_rejected_without_any_effect(): void
    {
        $environment = new ProvisioningEnvironment();
        $service = $environment->service();
        try {
            // Exercise the removed signature as an actual untyped external caller would.
            new ReflectionMethod($service, 'provision')->invoke($service, 'maintainer-42', 'Agent');
            self::fail('Legacy signature must reject.');
        } catch (TypeError) {
            self::assertSame(0, $environment->generations);
            self::assertSame([], $environment->agents->all());
        }
    }

    public function test_recoverable_agent_rejects_unfenced_lifecycle_even_after_permission_changes(): void
    {
        $environment = new ProvisioningEnvironment();
        $environment->service()->provision($environment->key, $environment->request);
        $original = $environment->agents->all()[0];
        $at = new DateTimeImmutable('2026-09-27T13:00:00+00:00');
        $permission = PermissionId::generate();
        $assigned = $original->grantPermission($permission, $at);
        $removed = $assigned->revokePermission($permission, $at);
        $replaced = $original->replacePermissions([$permission], 1, $at);
        foreach ([$original, $assigned, $removed, $replaced] as $agent) {
            self::assertTrue($agent->hasRecoverableCredentialOperation());
            try {
                $agent->rotateCredential($agent->getCredentialId(), AgentCredentialId::generate(), 'envelope', $at);
                self::fail('Legacy rotation must reject recoverable credentials.');
            } catch (AgentCredentialException) {
                self::assertSame(0, $agent->getCredentialRevision());
            }

            $revoked = $agent->revoke($at);
            self::assertSame(AgentState::REVOKED, $revoked->getState());
            self::assertTrue($revoked->hasRecoverableCredentialOperation());
            self::assertSame(AgentState::ACTIVE, $agent->getState());
            $unsupported = new InMemoryAgentRepository();
            $unsupported->add($agent);
            try {
                $unsupported->replace($agent, $revoked);
                self::fail('Unfenced persistence must reject recoverable revocation.');
            } catch (AgentOperationRejectedException $failure) {
                self::assertSame(AgentOperationFailure::UNAVAILABLE, $failure->getReason());
                self::assertSame($agent, $unsupported->getById($agent->getId()));
            }
        }
    }

    public function test_events_round_trip_and_reject_missing_data_and_audit_retains_user_compatibility(): void
    {
        $event = new AgentProvisioned(
            AgentId::generate(),
            AgentCredentialId::generate(),
            0,
            new DateTimeImmutable('2026-09-27T12:00:00+00:00')
        );
        $failed = new AgentProvisioningFailed('credential-operation', 'Agent operation failed.');
        self::assertSame($event->toArray(), AgentProvisioned::fromArray($event->toArray())->toArray());
        self::assertSame($failed->toArray(), AgentProvisioningFailed::fromArray($failed->toArray())->toArray());
        foreach ([AgentProvisioned::class, AgentProvisioningFailed::class] as $class) {
            try {
                $class::fromArray([]);
                self::fail('Missing event fields must reject.');
            } catch (DomainException) {
                self::addToAssertionCount(1);
            }
        }

        $user = UserId::generate();
        self::assertSame($user, AuditEvidence::record('maintainer', 'user.invited', $user)->subjectId());
    }

    private function assertSafeFailure(ProvisioningEnvironment $environment): void
    {
        self::assertCount(1, $environment->events->events());
        $failure = $environment->events->events()[0];
        self::assertInstanceOf(AgentProvisioningFailed::class, $failure);
        self::assertSame('credential-operation', $failure->getActorId());
        self::assertSame('Agent operation failed.', $failure->getErrorMessage());
        self::assertStringNotContainsString('original-test-secret', serialize($failure));
    }

    private function assertRejected(callable $operation, AgentOperationFailure $reason): AgentOperationRejectedException
    {
        try {
            $operation();
            self::fail('Expected safe rejection.');
        } catch (AgentOperationRejectedException $agentOperationRejectedException) {
            self::assertSame($reason, $agentOperationRejectedException->getReason());

            return $agentOperationRejectedException;
        }
    }
}
