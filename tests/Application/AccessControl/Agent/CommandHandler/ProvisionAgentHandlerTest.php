<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\CommandHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\Agent\CommandHandler\ProvisionAgentHandler;
use Fight\AccessControl\Application\AccessControl\Agent\Credential\HmacSharedSecretCipher;
use Fight\AccessControl\Application\AccessControl\Agent\Credential\HmacSharedSecretGenerator;
use Fight\AccessControl\Application\AccessControl\Agent\Delivery\AgentDeliveryCipher;
use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentRepository;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentState;
use Fight\AccessControl\Domain\AccessControl\Agent\Command\ProvisionAgent;
use Fight\AccessControl\Domain\AccessControl\Agent\Event\AgentProvisioned;
use Fight\AccessControl\Domain\AccessControl\Agent\Event\AgentProvisioningFailed;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentNameException;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentOperationCollisionException;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentOperationRejectedException;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentOperationUncertainException;
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
use Fight\AccessControl\Domain\AccessControl\Agent\Query\AgentOperationView;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Application\Repository\TransactionalUnitOfWork;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Credential\ProvisioningEnvironment;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Credential\UncertainAgentUnitOfWork;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Delivery\BoundAgentDeliveryCipher;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Repository\InMemoryAgentRepository;
use Fight\Test\AccessControl\Application\AccessControl\Audit\Repository\InMemoryAuditEvidenceRepository;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(ProvisionAgentHandler::class)]
#[CoversClass(AgentOperationUncertainException::class)]
#[CoversClass(Agent::class)]
#[CoversClass(AgentNameException::class)]
#[CoversClass(AgentOperationRejectedException::class)]
#[CoversClass(AgentOperationCollisionException::class)]
#[CoversClass(AgentCredentialOperation::class)]
#[CoversClass(AgentProvisioningFailed::class)]
#[CoversClass(AgentProvisioned::class)]
#[CoversClass(AuditEvidence::class)]
final class ProvisionAgentHandlerTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function normalizedNames(): iterable
    {
        yield 'mixed edges' => ["\0\t\u{00A0}Deploy / é\u{3000}\r\n", 'Deploy / é'];
        yield 'internal whitespace and case' => [" De\tploy\u{00A0}A  B ", "De\tploy\u{00A0}A  B"];
        yield 'internal nul' => [" A\0B ", "A\0B"];
        yield '120 code points' => ["\u{3000}".str_repeat('é', 120)."\u{00A0}", str_repeat('é', 120)];
        yield 'zero width and bom are names' => [" \u{200B}\u{FEFF} ", "\u{200B}\u{FEFF}"];
        yield 'decomposed remains decomposed' => [" e\u{0301} ", "e\u{0301}"];
        yield 'composed remains composed' => [' é ', 'é'];
    }

    /** @return iterable<string, array{string}> */
    public static function invalidNames(): iterable
    {
        yield 'empty' => [''];
        yield 'ascii empty' => [" \t\n\0"];
        yield 'unicode empty' => ["\u{00A0}\u{3000}"];
        yield '121 code points' => [str_repeat('é', 121)];
        yield 'invalid byte' => ["\xFF"];
        yield 'invalid suffix' => ["Valid\xC3"];
    }

    #[DataProvider('normalizedNames')]
    public function test_provisioning_stores_the_fixed_name_and_equivalent_retry_resolves_original_issuance(
        string $input,
        string $expected
    ): void {
        $env = new ProvisioningEnvironment();
        $request = new AgentProvisioningRequest($input, $env->request->getDestination());
        $result = $env->bus()->dispatchAndRead(new ProvisionAgent($env->key, $request));
        self::assertTrue($result->isConfirmed());
        self::assertSame($expected, $env->agentRepository->all()[0]->getName()->toString());
        $stored = $env->agentOperationRepository->operations[$env->key->toString()];
        self::assertSame(2, $stored->getCanonicalVersion());
        $retry = new AgentProvisioningRequest($expected, $request->getDestination());
        self::assertSame(
            $result->getIssuance(),
            $env->bus()->dispatchAndRead(new ProvisionAgent($env->key, $retry))->getIssuance()
        );
        self::assertSame($stored, $env->agentOperationRepository->operations[$env->key->toString()]);
        self::assertSame(1, $env->generations);
        self::assertCount(1, $env->auditEvidenceRepository->all());
        self::assertCount(1, $env->eventDispatcher->events());
    }

    #[DataProvider('invalidNames')]
    public function test_invalid_names_preserve_safe_operation_failure_without_any_issuance(string $input): void
    {
        $env = new ProvisioningEnvironment();
        $failure = $this->assertRejected(
            fn(): AgentOperationView => $env->bus()->dispatchAndRead(new ProvisionAgent(
                $env->key,
                new AgentProvisioningRequest($input, $env->request->getDestination())
            )),
            AgentOperationFailure::INVALID_REQUEST
        );
        self::assertNull($failure->getPrevious());
        self::assertSame(0, $env->generations);
        self::assertSame([], $env->agentRepository->all());
        self::assertSame([], $env->agentOperationRepository->operations);
        self::assertSame([], $env->auditEvidenceRepository->all());
    }

    public function test_it_commits_one_complete_issuance_before_publication_and_returns_no_secret(): void
    {
        $environment = new ProvisioningEnvironment();
        $environment->eventDispatcher = new InMemoryEventDispatcher(function () use ($environment): void {
            self::assertTrue($environment->transaction->transactionCompleted);
            self::assertFalse($environment->transaction->transactionActive);
            self::assertFalse($environment->operationAuthorization->locked);
            self::assertCount(1, $environment->agentRepository->all());
            self::assertCount(1, $environment->agentOperationRepository->operations);
            self::assertCount(1, $environment->auditEvidenceRepository->all());
        });
        $result = $environment->bus()->dispatchAndRead(new ProvisionAgent($environment->key, $environment->request));
        self::assertTrue($result->isConfirmed());
        self::assertArrayNotHasKey('warning', $result->toArray());
        $issuance = $result->getIssuance();
        self::assertInstanceOf(AgentIssuance::class, $issuance);
        $agent = $environment->agentRepository->all()[0];
        self::assertSame('Production deployment', $agent->getName()->toString());
        self::assertSame(AgentState::ACTIVE, $agent->getState());
        self::assertSame($agent->getId(), $issuance->getAgentId());
        self::assertSame($agent->getCredentialId(), $issuance->getCredentialId());
        self::assertSame(0, $issuance->getCredentialRevision());
        self::assertSame(1, $issuance->getDestinationWriteVersion());
        self::assertSame('auth-envelope:original-test-secret', $agent->getEncryptedHmacSharedSecretEnvelope());
        $operation = $environment->agentOperationRepository->operations[$environment->key->toString()];
        self::assertSame('original-test-secret', new BoundAgentDeliveryCipher()->inspect(
            $operation->getMaterial(),
            $issuance
        ));
        self::assertSame('test-key-v1', $operation->getMaterial()->getKeyVersion());
        self::assertSame($environment->key, $issuance->getKey());
        self::assertSame($environment->request->getDestination(), $issuance->getDestination());
        self::assertSame('maintainer-42', $environment->auditEvidenceRepository->all()[0]->actorId());
        self::assertSame('agent.provisioned', $environment->auditEvidenceRepository->all()[0]->action());
        self::assertSame($agent->getId(), $environment->auditEvidenceRepository->all()[0]->subjectId());
        self::assertSame([], $environment->auditEvidenceRepository->all()[0]->context());
        $event = $environment->eventDispatcher->events()[0];
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

    public function test_lost_response_and_restart_resolve_without_generation_audit_or_another_fact(): void
    {
        $environment = new ProvisioningEnvironment();
        $environment->bus()->commandBus->execute(new ProvisionAgent($environment->key, $environment->request));
        $stored = $environment->agentOperationRepository->operations[$environment->key->toString()];
        $request = new AgentProvisioningRequest('Production deployment', $environment->request->getDestination());
        $retried = $environment->bus()->dispatchAndRead(new ProvisionAgent($environment->key, $request));
        self::assertSame($stored->getIssuance(), $retried->getIssuance());
        self::assertSame(1, $environment->generations);
        self::assertCount(1, $environment->agentRepository->all());
        self::assertCount(1, $environment->auditEvidenceRepository->all());
        self::assertCount(1, $environment->eventDispatcher->events());
        self::assertSame(2, $environment->operationAuthorization->calls);
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
        try {
            $environment->bus(new UncertainAgentUnitOfWork($environment->transaction, $committed))
                ->commandBus->execute(new ProvisionAgent($environment->key, $environment->request));
            self::fail('Lost acknowledgement must surface execution uncertainty.');
        } catch (AgentOperationUncertainException $agentOperationUncertainException) {
            self::assertNull($agentOperationUncertainException->getPrevious());
            self::assertStringContainsString('indeterminate', $agentOperationUncertainException->getMessage());
        }

        self::assertCount((int) $committed, $environment->agentOperationRepository->operations);
        self::assertCount((int) $committed, $environment->agentRepository->all());
        self::assertCount((int) $committed, $environment->auditEvidenceRepository->all());
        self::assertInstanceOf(AgentProvisioningFailed::class, $environment->eventDispatcher->events()[0]);
        $original = $environment->agentOperationRepository->operations[$environment->key->toString()] ?? null;
        $resolved = $environment->bus()->dispatchAndRead(new ProvisionAgent($environment->key, $environment->request));
        self::assertTrue($resolved->isConfirmed());
        self::assertCount(1, $environment->agentOperationRepository->operations);
        self::assertCount(1, $environment->agentRepository->all());
        self::assertCount(1, $environment->auditEvidenceRepository->all());
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
    public function test_publication_failure_preserves_committed_work_without_reconstructed_warnings(bool $both): void
    {
        $environment = new ProvisioningEnvironment();
        $environment->eventDispatcher = new InMemoryEventDispatcher(static function (object $event) use ($both): void {
            if ($both || $event instanceof AgentProvisioned) {
                throw new RuntimeException('Unsafe provider data original-test-secret and key/path.');
            }
        });
        $result = $environment->bus()->dispatchAndRead(new ProvisionAgent($environment->key, $environment->request));
        self::assertTrue($result->isConfirmed());
        self::assertArrayNotHasKey('warning', $result->toArray());
        self::assertStringNotContainsString('Unsafe', serialize($result));
        self::assertStringNotContainsString('original-test-secret', serialize($environment->eventDispatcher->events()));
        self::assertCount(1, $environment->agentOperationRepository->operations);
        self::assertCount(1, $environment->auditEvidenceRepository->all());
        $retry = $environment->bus()->dispatchAndRead(new ProvisionAgent($environment->key, $environment->request));
        self::assertSame($result->getIssuance(), $retry->getIssuance());
        self::assertArrayNotHasKey('warning', $retry->toArray());
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
        $agentRepository = null;
        $auditEvidenceRepository = null;
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
            $agentRepository = $this->createMock(AgentRepository::class);
            $agentRepository->method('getOperationContract')->willReturn(
                $environment->agentRepository->getOperationContract()
            );
            $agentRepository->expects(self::once())->method('add')->willReturnCallback(function (Agent $agent) use (
                $environment,
                $failure
            ): void {
                $environment->agentRepository->add($agent);
                throw $failure;
            });
        } elseif ($stage === 'operation') {
            $environment->agentOperationRepository->afterAdd = static function () use ($failure): void {
                throw $failure;
            };
        } else {
            $auditEvidenceRepository = new InMemoryAuditEvidenceRepository(
                $environment->transaction,
                failAfterSave: true
            );
        }

        $service = $environment->bus(
            generator: $generator,
            cipher: $cipher,
            deliveryCipher: $delivery,
            auditEvidenceRepository: $auditEvidenceRepository,
            agentRepository: $agentRepository
        );
        $exception = $this->assertRejected(
            fn(): AgentOperationView => $service->dispatchAndRead(
                new ProvisionAgent($environment->key, $environment->request)
            ),
            AgentOperationFailure::UNAVAILABLE
        );
        self::assertNull($exception->getPrevious());
        self::assertStringNotContainsString('original-test-secret', (string) $exception);
        self::assertStringNotContainsString('key/path', (string) $exception);
        self::assertSame([], $environment->agentRepository->all());
        self::assertSame([], $environment->agentOperationRepository->operations);
        self::assertSame([], $environment->agentOperationRepository->versions);
        self::assertSame([], ($auditEvidenceRepository ?? $environment->auditEvidenceRepository)->all());
        $this->assertSafeFailure($environment);
    }

    public function test_invalid_name_and_failed_failure_publication_leave_no_partial_state(): void
    {
        $environment = new ProvisioningEnvironment();
        $environment->eventDispatcher = new InMemoryEventDispatcher(static function (): void {
            throw new RuntimeException('Failure publisher is down.');
        });
        $this->assertRejected(
            fn(): AgentOperationView => $environment->bus()->dispatchAndRead(new ProvisionAgent(
                $environment->key,
                new AgentProvisioningRequest('   ', $environment->request->getDestination())
            )),
            AgentOperationFailure::INVALID_REQUEST
        );
        self::assertSame(0, $environment->generations);
        self::assertSame([], $environment->agentOperationRepository->versions);
        self::assertSame([], $environment->eventDispatcher->events());
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
            $environment->bus()->commandBus->execute(new ProvisionAgent($environment->key, $environment->request));
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
            $environment->operationAuthorization->scopes[$scope->toString()] = false;
        } elseif ($case === 'expired delegation') {
            $environment->operationAuthorization->delegationExpired = true;
        } elseif ($case === 'destination') {
            $environment->operationAuthorization->destinations = [];
        } else {
            $environment->operationAuthorization->actor = 'unsafe/actor';
            $environment->operationAuthorization->readDelegations['unsafe/actor:'.$scope->toString()] = true;
        }

        $this->assertRejected(
            fn(): AgentOperationView => $environment->bus()->dispatchAndRead(
                new ProvisionAgent($key, $environment->request)
            ),
            $case === 'audit identity' ? AgentOperationFailure::UNAVAILABLE : AgentOperationFailure::UNAUTHORIZED
        );
        self::assertSame((int) $retry, $environment->agentOperationRepository->reads);
        self::assertSame((int) $retry, $environment->generations);
        self::assertCount((int) $retry, $environment->agentRepository->all());
        self::assertCount((int) $retry, $environment->auditEvidenceRepository->all());
    }

    public function test_authorization_writer_waits_for_issuance_and_next_retry_observes_revocation(): void
    {
        $environment = new ProvisioningEnvironment();
        $environment->operationAuthorization->actor = 'delegated-worker-7';
        $environment->operationAuthorization->readDelegations[
            'delegated-worker-7:'.$environment->key->getScope()->toString()
        ] = true;
        $environment->operationAuthorization->afterAuthorization = function () use ($environment): void {
            $environment->operationAuthorization->changeAuthority(function () use ($environment): void {
                $environment->operationAuthorization->scopes = [];
            });
            self::assertNotEmpty($environment->operationAuthorization->scopes);
        };
        $environment->bus()->commandBus->execute(new ProvisionAgent($environment->key, $environment->request));
        self::assertSame(
            $environment->key,
            $environment->agentOperationRepository->operations[$environment->key->toString()]->getIssuance()->getKey()
        );
        self::assertSame('delegated-worker-7', $environment->auditEvidenceRepository->all()[0]->actorId());
        self::assertSame([], $environment->operationAuthorization->scopes);
        $this->assertRejected(
            fn(): AgentOperationView => $environment->bus()->dispatchAndRead(new ProvisionAgent(
                $environment->key,
                $environment->request
            )),
            AgentOperationFailure::UNAUTHORIZED
        );
    }

    public function test_authorization_participant_failure_rolls_back_its_own_writes(): void
    {
        $environment = new ProvisioningEnvironment();
        $participantWrites = [];
        $environment->operationAuthorization->afterAuthorization = function () use (
            $environment,
            &$participantWrites
        ): void {
            $participantWrites[] = 'transactional-consumer-evidence';
            $environment->transaction->onRollback(static function () use (&$participantWrites): void {
                $participantWrites = [];
            });
            throw new RuntimeException('Unsafe authority capability details.');
        };
        $this->assertRejected(
            fn(): AgentOperationView => $environment->bus()->dispatchAndRead(new ProvisionAgent(
                $environment->key,
                $environment->request
            )),
            AgentOperationFailure::UNAVAILABLE
        );
        self::assertSame([], $participantWrites);
        self::assertSame([], $environment->agentRepository->all());
        self::assertSame([], $environment->agentOperationRepository->operations);
        self::assertSame([], $environment->agentOperationRepository->versions);
        self::assertFalse($environment->operationAuthorization->locked);
        self::assertSame(0, $environment->agentOperationRepository->reads);
    }

    public function test_cross_scope_ids_and_reassignment_preserve_delivery_identity_and_slot_order(): void
    {
        $environment = new ProvisioningEnvironment();
        $first = $environment->bus()->dispatchAndRead(
            new ProvisionAgent($environment->key, $environment->request)
        )->getIssuance();
        $scope = new AgentOperationScope('consumer-b', 'agent', 'worker-1');
        $environment->operationAuthorization->scopes[$scope->toString()] = true;
        $environment->operationAuthorization->actor = $scope->getCallerId();
        $destination = new AgentCredentialDestination($environment->request->getDestination()->getId(), 2);
        $environment->operationAuthorization->destinations[$destination->getId()->toString()] = 2;
        $second = $environment->bus()->dispatchAndRead(new ProvisionAgent(
            new AgentOperationKey($scope, $environment->key->getId()),
            new AgentProvisioningRequest('Other Agent', $destination)
        ))->getIssuance();
        self::assertFalse($first->getDeliveryId()->equals($second->getDeliveryId()));
        self::assertFalse($first->getAgentId()->equals($second->getAgentId()));
        self::assertSame(2, $second->getDestinationWriteVersion());
        self::assertSame(2, $second->getDestination()->getRevision());
        self::assertCount(2, $environment->agentOperationRepository->operations);
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
        $environment->bus()->commandBus->execute(new ProvisionAgent($environment->key, $environment->request));
        $request = $environment->request;
        $destination = $request->getDestination();
        $stored = $environment->agentOperationRepository->operations[$environment->key->toString()];
        if ($case === 'name') {
            $request = new AgentProvisioningRequest('Other name', $destination);
        } elseif ($case === 'destination' || $case === 'revision') {
            $destination = new AgentCredentialDestination(
                $case === 'destination' ? AgentDestinationId::generate() : $destination->getId(),
                2
            );
            $environment->operationAuthorization->destinations[$destination->getId()->toString()] = 2;
            $request = new AgentProvisioningRequest($request->getName(), $destination);
        } else {
            $canonical = $stored->getCanonicalRequest();
            if ($case === 'kind') {
                $canonical = str_replace('provision', 'rotate', $canonical);
            }

            $environment->agentOperationRepository->operations[
                $environment->key->toString()
            ] = new AgentCredentialOperation(
                $case === 'version' ? 99 : $stored->getCanonicalVersion(),
                $canonical,
                $stored->getIssuance(),
                $stored->getMaterial()
            );
        }

        $this->assertRejected(
            fn(): AgentOperationView => $environment->bus()->dispatchAndRead(
                new ProvisionAgent($environment->key, $request)
            ),
            $case === 'version' ? AgentOperationFailure::UNSUPPORTED_VERSION : AgentOperationFailure::CONFLICT
        );
        self::assertSame(1, $environment->generations);
        self::assertCount(1, $environment->agentRepository->all());
        self::assertCount(1, $environment->auditEvidenceRepository->all());
    }

    public function test_capacity_rejects_new_work_but_preserves_resolution_under_lowered_limits_and_tombstones(): void
    {
        $environment = new ProvisioningEnvironment();
        $longRequest = new AgentProvisioningRequest(
            str_repeat(' ', 200).'Agent',
            $environment->request->getDestination()
        );
        $first = $environment->bus()->dispatchAndRead(new ProvisionAgent($environment->key, $longRequest));
        $service = $environment->bus(limits: new AgentOperationLimits(128, 1, 1));
        self::assertSame(
            $first->getIssuance(),
            $service->dispatchAndRead(new ProvisionAgent($environment->key, $longRequest))->getIssuance()
        );
        $this->assertRejected(
            fn(): AgentOperationView => $service->dispatchAndRead(new ProvisionAgent(
                new AgentOperationKey($environment->key->getScope(), AgentOperationId::generate()),
                $environment->request
            )),
            AgentOperationFailure::CAPACITY
        );
        self::assertCount(1, $environment->agentRepository->all());
        self::assertCount(1, $environment->auditEvidenceRepository->all());
        self::assertSame([1], array_values($environment->agentOperationRepository->versions));
        $key = $environment->key->toString();
        $environment->agentOperationRepository->operations[
            $key
        ] = $environment->agentOperationRepository->operations[$key]->retireMaterial();
        self::assertSame(
            $first->getIssuance(),
            $service->dispatchAndRead(new ProvisionAgent($environment->key, $longRequest))->getIssuance()
        );
        self::assertNull($environment->agentOperationRepository->operations[$key]->getMaterial());
        self::assertCount(1, array_filter(
            $environment->eventDispatcher->events(),
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
        $winner->operationAuthorization->scopes[$environment->key->getScope()->toString()] = true;
        $winner->operationAuthorization->destinations[$environment->request->getDestination()->getId()->toString()] = 1;
        $winnerRequest = new AgentProvisioningRequest(
            $conflict ? 'Other winner' : 'Production deployment',
            $environment->request->getDestination()
        );
        $winner->bus()->commandBus->execute(new ProvisionAgent($environment->key, $winnerRequest));
        $winningOperation = $winner->agentOperationRepository->operations[$environment->key->toString()];
        $environment->agentOperationRepository->beforeAdd = function () use (
            $environment,
            $winner,
            $winningOperation
        ): void {
            $environment->transaction->onCompletion(function () use ($environment, $winner, $winningOperation): void {
                // The independent winner becomes visible only after the loser's transaction has rolled back.
                self::assertSame([], $environment->agentRepository->all());
                self::assertSame([], $environment->agentOperationRepository->versions);
                $environment->agentOperationRepository->operations[$environment->key->toString()] = $winningOperation;
                $environment->agentOperationRepository->versions = $winner->agentOperationRepository->versions;
                $environment->agentRepository->add($winner->agentRepository->all()[0]);
                $environment->auditEvidenceRepository->add($winner->auditEvidenceRepository->all()[0]);
            });
            throw new AgentOperationCollisionException();
        };
        if ($conflict) {
            $this->assertRejected(
                fn(): AgentOperationView => $environment->bus()->dispatchAndRead(new ProvisionAgent(
                    $environment->key,
                    $environment->request
                )),
                AgentOperationFailure::CONFLICT
            );
        } else {
            $result = $environment->bus()->dispatchAndRead(
                new ProvisionAgent($environment->key, $environment->request)
            );
            self::assertSame($winningOperation->getIssuance(), $result->getIssuance());
            self::assertSame([], $environment->eventDispatcher->events());
        }

        self::assertSame(2, $environment->transaction->transactions);
        self::assertSame(2, $environment->operationAuthorization->calls);
        self::assertCount(1, $environment->agentRepository->all());
        self::assertCount(1, $environment->auditEvidenceRepository->all());
        self::assertCount(1, $environment->agentOperationRepository->operations);
        self::assertSame([1], array_values($environment->agentOperationRepository->versions));
    }

    public function test_missing_collision_winner_is_bounded_retryable_contention_not_automatic_reissuance(): void
    {
        $environment = new ProvisioningEnvironment();
        $environment->agentOperationRepository->beforeAdd = static function (): void {
            throw new AgentOperationCollisionException();
        };
        $exception = $this->assertRejected(
            fn(): AgentOperationView => $environment->bus()->dispatchAndRead(new ProvisionAgent(
                $environment->key,
                $environment->request
            )),
            AgentOperationFailure::CONTENTION
        );
        self::assertTrue($exception->isRetryable());
        self::assertSame(2, $environment->transaction->transactions);
        self::assertSame(1, $environment->generations);
        self::assertSame([], $environment->agentRepository->all());
        self::assertSame([], $environment->agentOperationRepository->operations);
        self::assertSame([], $environment->agentOperationRepository->versions);
    }

    public function test_closed_transaction_capability_fails_before_lookup_or_generation(): void
    {
        $environment = new ProvisioningEnvironment();
        $closed = $this->createMock(TransactionalUnitOfWork::class);
        $closed->method('isClosed')->willReturn(true);
        $closed->expects(self::never())->method('commitTransactional');
        $this->assertRejected(
            fn(): AgentOperationView => $environment->bus($closed)->dispatchAndRead(new ProvisionAgent(
                $environment->key,
                $environment->request
            )),
            AgentOperationFailure::UNAVAILABLE
        );
        self::assertSame(0, $environment->agentOperationRepository->reads);
        self::assertSame(0, $environment->generations);
    }

    public function test_recoverable_agent_rejects_unfenced_lifecycle_even_after_permission_changes(): void
    {
        $environment = new ProvisioningEnvironment();
        $environment->bus()->commandBus->execute(new ProvisionAgent($environment->key, $environment->request));
        $original = $environment->agentRepository->all()[0];
        $at = new DateTimeImmutable('2026-09-27T13:00:00+00:00');
        $permission = PermissionId::generate();
        $assigned = $original->grantPermission($permission, $at);
        $removed = $assigned->revokePermission($permission, $at);
        $replaced = $original->replacePermissions([$permission], 1, $at);
        foreach ([$original, $assigned, $removed, $replaced] as $agent) {
            $revoked = $agent->revoke($at);
            self::assertSame(AgentState::REVOKED, $revoked->getState());
            self::assertSame(AgentState::ACTIVE, $agent->getState());
            $unsupportedAgentRepository = new InMemoryAgentRepository();
            $unsupportedAgentRepository->add($agent);
            try {
                $unsupportedAgentRepository->replace($agent, $revoked);
                self::fail('Unfenced persistence must reject recoverable revocation.');
            } catch (AgentOperationRejectedException $failure) {
                self::assertSame(AgentOperationFailure::UNAVAILABLE, $failure->getReason());
                self::assertSame($agent, $unsupportedAgentRepository->getById($agent->getId()));
            }
        }
    }

    public function test_events_round_trip_and_reject_missing_data_and_audit_supports_user_and_agent_subjects(): void
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
        self::assertCount(1, $environment->eventDispatcher->events());
        $failure = $environment->eventDispatcher->events()[0];
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
