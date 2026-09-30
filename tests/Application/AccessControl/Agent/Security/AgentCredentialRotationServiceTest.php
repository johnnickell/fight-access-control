<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Security;

use Closure;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentCredentialRotationResult;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentCredentialRotationService;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentDeliveryResult;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentPublicationWarning;
use Fight\AccessControl\Application\AccessControl\Agent\Service\AgentDeliveryCipher;
use Fight\AccessControl\Application\AccessControl\Agent\Service\HmacSharedSecretCipher;
use Fight\AccessControl\Application\AccessControl\Agent\Service\HmacSharedSecretGenerator;
use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentRepository;
use Fight\AccessControl\Domain\AccessControl\Agent\Event\AgentCredentialLifecycleFailed;
use Fight\AccessControl\Domain\AccessControl\Agent\Event\AgentCredentialRotated;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentCredentialException;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentOperationCollisionException;
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
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationScope;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentRotationRequest;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\Common\Application\Repository\TransactionalUnitOfWork;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\DeliveryEnvironment;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\ProvisioningEnvironment;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\RotationEnvironment;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\UncertainAgentUnitOfWork;
use Fight\Test\AccessControl\Application\AccessControl\Audit\Repository\InMemoryAuditEvidenceRepository;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SensitiveParameter;

#[CoversClass(AgentCredentialRotationService::class)]
#[CoversClass(AgentCredentialRotationResult::class)]
#[CoversClass(AgentRotationRequest::class)]
#[CoversClass(Agent::class)]
#[CoversClass(AgentCredentialOperation::class)]
#[CoversClass(AgentCredentialException::class)]
#[CoversClass(AgentOperationRejectedException::class)]
#[CoversClass(AgentCredentialRotated::class)]
#[CoversClass(AuditEvidence::class)]
final class AgentCredentialRotationServiceTest extends TestCase
{
    public function test_rotation_commits_before_publication_and_recovers_after_restart(): void
    {
        $rotation = new RotationEnvironment();
        $env = $rotation->provisioning;
        $predecessor = $env->agents->all()[0];
        $env->events = new InMemoryEventDispatcher(static function () use ($env, $predecessor): void {
            self::assertFalse($env->transaction->transactionActive);
            self::assertCount(2, $env->operations->operations);
            self::assertCount(2, $env->audit->all());
            self::assertNull($env->agents->getByCredentialId($predecessor->getCredentialId()));
            self::assertNull($env->operations->operations[$env->key->toString()]->getMaterial());
        });
        $result = $rotation->service()->rotate($rotation->key, $rotation->request);
        self::assertTrue($result->isConfirmed());
        self::assertNull($result->getWarning());
        $issuance = $result->getIssuance();
        self::assertNotNull($issuance);
        self::assertSame($predecessor->getId(), $issuance->getAgentId());
        self::assertSame(1, $issuance->getCredentialRevision());
        self::assertSame(2, $issuance->getDestinationWriteVersion());
        self::assertFalse($rotation->original->getDeliveryId()->equals($issuance->getDeliveryId()));
        $successor = $env->agents->all()[0];
        self::assertSame($issuance->getCredentialId(), $successor->getCredentialId());
        self::assertSame(
            'auth-envelope:successor-test-secret',
            $successor->getEncryptedHmacSharedSecretEnvelope()
        );
        self::assertSame($predecessor->getName(), $successor->getName());
        self::assertSame(
            $predecessor->getPermissionAssignmentRevision(),
            $successor->getPermissionAssignmentRevision()
        );
        self::assertSame('agent.credential_rotated', $env->audit->all()[1]->action());
        $original = $env->operations->operations[$env->key->toString()];
        self::assertSame(AgentCredentialDisposition::SUPERSEDED, $original->getStatus()->getCredentialDisposition());
        self::assertSame(AgentDeliveryDisposition::RETIRED, $original->getStatus()->getDeliveryDisposition());
        self::assertSame(1, $original->getStateRevision());
        $stored = $env->operations->operations[$rotation->key->toString()];
        self::assertSame(AgentDeliveryDisposition::PENDING, $stored->getStatus()->getDeliveryDisposition());
        self::assertNotNull($stored->getMaterial());
        self::assertCount(1, $env->events->events());
        self::assertInstanceOf(AgentCredentialRotated::class, $env->events->events()[0]);
        self::assertSame($issuance->getCredentialId(), $env->events->events()[0]->getCredentialId());
        $retried = $rotation->service()->rotate($rotation->key, $rotation->request);
        self::assertSame($issuance, $retried->getIssuance());
        self::assertSame(2, $env->generations);
        self::assertCount(2, $env->audit->all());
        self::assertCount(1, $env->events->events());
        self::assertSame($rotation->original, $env->service()->provision($env->key, $env->request)->getIssuance());
        $this->assertSafe([$result, $retried, $env->events->events(), $env->audit->all()]);
        $this->reject(
            fn(): AgentCredentialRotationResult => $rotation->service()->rotate(
                $this->newKey($rotation),
                $rotation->request
            ),
            AgentOperationFailure::CONFLICT
        );
        self::assertSame(2, $env->generations);
    }

    /** @return iterable<string, array{bool}> */
    public static function booleans(): iterable
    {
        yield 'false' => [false];
        yield 'true' => [true];
    }

    #[DataProvider('booleans')]
    public function test_uncertain_commit_requires_original_request_reconciliation(bool $persist): void
    {
        $rotation = new RotationEnvironment();
        $env = $rotation->provisioning;
        $result = $rotation->service(new UncertainAgentUnitOfWork($env->transaction, $persist))
            ->rotate($rotation->key, $rotation->request);
        self::assertFalse($result->isConfirmed());
        self::assertNull($result->getIssuance());
        self::assertNull($result->getWarning());
        self::assertCount($persist ? 2 : 1, $env->operations->operations);
        self::assertCount($persist ? 2 : 1, $env->audit->all());
        self::assertSame((int) $persist, $env->agents->all()[0]->getCredentialRevision());
        $original = $env->operations->operations[$env->key->toString()];
        self::assertSame($persist, $original->getMaterial() === null);
        $committed = $env->operations->operations[$rotation->key->toString()] ?? null;
        $resolved = $rotation->service()->rotate($rotation->key, $rotation->request);
        self::assertTrue($resolved->isConfirmed());
        self::assertCount(2, $env->operations->operations);
        self::assertCount(2, $env->audit->all());
        self::assertSame($persist ? 2 : 3, $env->generations);
        if ($committed !== null) {
            self::assertSame($committed->getIssuance(), $resolved->getIssuance());
        }

        self::assertSame(1, $env->agents->all()[0]->getCredentialRevision());
    }

    #[DataProvider('booleans')]
    public function test_both_publisher_failures_never_disguise_committed_issuance(bool $both): void
    {
        $rotation = new RotationEnvironment();
        $env = $rotation->provisioning;
        $env->events = new InMemoryEventDispatcher(static function (object $event) use ($both): void {
            if ($both || $event instanceof AgentCredentialRotated) {
                throw new RuntimeException('Unsafe successor-test-secret key/path');
            }
        });
        $result = $rotation->service()->rotate($rotation->key, $rotation->request);
        self::assertTrue($result->isConfirmed());
        self::assertSame(AgentPublicationWarning::PUBLICATION_FAILED, $result->getWarning());
        self::assertCount(2, $env->operations->operations);
        self::assertCount(2, $env->audit->all());
        self::assertNull($env->operations->operations[$env->key->toString()]->getMaterial());
        $retry = $rotation->service()->rotate($rotation->key, $rotation->request);
        self::assertSame($result->getIssuance(), $retry->getIssuance());
        self::assertNull($retry->getWarning());
        self::assertSame(2, $env->generations);
        $this->assertSafe([$result, $env->events->events()]);
    }

    /** @return iterable<string, array{string}> */
    public static function faultStages(): iterable
    {
        $cases = ['authority', 'generator', 'cipher', 'delivery', 'retirement', 'replacement', 'operation', 'audit'];
        foreach ($cases as $case) {
            yield $case => [$case];
        }
    }

    #[DataProvider('faultStages')]
    public function test_precommit_failures_roll_back_successor_retirement_reservation_and_audit(string $case): void
    {
        $rotation = new RotationEnvironment();
        $env = $rotation->provisioning;
        $before = $env->agents->all()[0];
        $original = $env->operations->operations[$env->key->toString()];
        $fault = new RuntimeException('Unsafe successor-test-secret key/path');
        $generator = null;
        $cipher = null;
        $delivery = null;
        $audit = null;
        $participant = [];
        if ($case === 'authority') {
            $env->authorization->afterAuthorization = static function () use ($env, &$participant, $fault): void {
                $participant[] = 'consumer-write';
                $env->transaction->onRollback(static function () use (&$participant): void {
                    $participant = [];
                });
                throw $fault;
            };
        } elseif ($case === 'generator') {
            $generator = $this->createMock(HmacSharedSecretGenerator::class);
            $generator->expects(self::once())->method('generate')->willThrowException($fault);
        } elseif ($case === 'cipher') {
            $cipher = $this->createMock(HmacSharedSecretCipher::class);
            $cipher->expects(self::once())->method('encrypt')->willThrowException($fault);
        } elseif ($case === 'delivery') {
            $delivery = $this->createMock(AgentDeliveryCipher::class);
            $delivery->expects(self::once())->method('encrypt')->willThrowException($fault);
        } elseif ($case === 'retirement') {
            $env->operations->afterRetirement = static function () use ($fault): void {
                throw $fault;
            };
        } elseif ($case === 'replacement') {
            $env->agents->afterReplace = static function () use ($fault): void {
                throw $fault;
            };
        } elseif ($case === 'operation') {
            $env->operations->afterAdd = static function () use ($fault): void {
                throw $fault;
            };
        } else {
            $audit = new InMemoryAuditEvidenceRepository($env->transaction, failAfterSave: true);
        }

        $service = $rotation->service(generator: $generator, cipher: $cipher, deliveryCipher: $delivery, audit: $audit);
        $prior = ini_set('zend.exception_ignore_args', '0');
        try {
            $failure = $this->reject(
                fn(): AgentCredentialRotationResult => $service->rotate($rotation->key, $rotation->request)
            );
            self::assertNull($failure->getPrevious());
            $this->assertSafe($failure);
        } finally {
            ini_set('zend.exception_ignore_args', $prior);
        }

        self::assertSame([], $participant);
        self::assertSame([$before], $env->agents->all());
        self::assertSame([$env->key->toString() => $original], $env->operations->operations);
        self::assertSame([1], array_values($env->operations->versions));
        self::assertCount(1, $env->audit->all());
        if ($audit !== null) {
            self::assertSame([], $audit->all());
        }

        self::assertInstanceOf(AgentCredentialLifecycleFailed::class, $env->events->events()[1]);
        self::assertFalse($env->authorization->locked);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function deniedCalls(): iterable
    {
        $cases = ['namespace', 'type', 'caller', 'actor', 'revoked', 'delegation', 'destination', 'target', 'audit'];
        foreach ($cases as $case) {
            foreach ([false, true] as $retry) {
                yield $case.($retry ? ' retry' : ' new') => [$case, $retry];
            }
        }
    }

    #[DataProvider('deniedCalls')]
    public function test_current_authority_precedes_lookup(string $case, bool $retry): void
    {
        $rotation = new RotationEnvironment();
        $env = $rotation->provisioning;
        if ($retry) {
            $rotation->service()->rotate($rotation->key, $rotation->request);
        }

        $reads = $env->operations->reads;
        $generations = $env->generations;
        $key = $rotation->key;
        $scope = $key->getScope();
        if (in_array($case, ['namespace', 'type', 'caller'], true)) {
            $key = new AgentOperationKey(new AgentOperationScope(
                $case === 'namespace' ? 'other' : $scope->getNamespace(),
                $case === 'type' ? 'agent' : $scope->getCallerType(),
                $case === 'caller' ? 'other' : $scope->getCallerId()
            ), $key->getId());
        } elseif ($case === 'actor') {
            $env->authorization->actor = 'imposter';
        } elseif ($case === 'revoked') {
            $env->authorization->scopes = [];
        } elseif ($case === 'delegation') {
            $env->authorization->delegationExpired = true;
        } elseif ($case === 'destination') {
            $env->authorization->destinations[$rotation->request->getDestination()->getId()->toString()] = 2;
        } elseif ($case === 'target') {
            $env->authorization->deniedTargets[] = $rotation->request->getAgentId()->toString();
        } else {
            $env->authorization->actor = 'unsafe/actor';
            $env->authorization->readDelegations['unsafe/actor:'.$scope->toString()] = true;
        }

        $this->reject(
            fn(): AgentCredentialRotationResult => $rotation->service()->rotate($key, $rotation->request),
            $case === 'audit' ? AgentOperationFailure::UNAVAILABLE : AgentOperationFailure::UNAUTHORIZED
        );
        self::assertSame($reads, $env->operations->reads);
        self::assertSame($generations, $env->generations);
        self::assertCount($retry ? 2 : 1, $env->audit->all());
    }

    /** @return iterable<string, array{string}> */
    public static function changedRequests(): iterable
    {
        foreach (['target', 'credential', 'revision', 'destination', 'binding', 'kind', 'version'] as $case) {
            yield $case => [$case];
        }
    }

    #[DataProvider('changedRequests')]
    public function test_changed_original_request_conflicts_and_unknown_versions_never_reissue(string $case): void
    {
        $rotation = new RotationEnvironment();
        $env = $rotation->provisioning;
        $rotation->service()->rotate($rotation->key, $rotation->request);
        $original = $rotation->request;
        $destination = $original->getDestination();
        if (in_array($case, ['destination', 'binding'], true)) {
            $destination = new AgentCredentialDestination(
                $case === 'destination' ? AgentDestinationId::generate() : $destination->getId(),
                2
            );
            $env->authorization->destinations[$destination->getId()->toString()] = 2;
        }

        $request = new AgentRotationRequest(
            $case === 'target' ? AgentId::generate() : $original->getAgentId(),
            $case === 'credential' ? AgentCredentialId::generate() : $original->getExpectedCredentialId(),
            $case === 'revision' ? 1 : 0,
            $destination
        );
        if ($case === 'version') {
            $stored = $env->operations->operations[$rotation->key->toString()];
            $env->operations->operations[$rotation->key->toString()] = new AgentCredentialOperation(
                99,
                $stored->getCanonicalRequest(),
                $stored->getIssuance(),
                null
            );
        }

        $this->reject(
            fn(): AgentCredentialRotationResult => $rotation->service()->rotate(
                $case === 'kind' ? $env->key : $rotation->key,
                $request
            ),
            $case === 'version' ? AgentOperationFailure::UNSUPPORTED_VERSION : AgentOperationFailure::CONFLICT
        );
        self::assertSame(2, $env->generations);
        self::assertCount(2, $env->audit->all());
    }

    public function test_capacity_preserves_same_key_tombstones_and_rolls_back_a_rejected_new_rotation(): void
    {
        $rotation = new RotationEnvironment();
        $env = $rotation->provisioning;
        // Another Agent occupies the only remaining slot even after predecessor material is cancelled.
        $env->service()->provision($this->newKey($rotation), $env->request);
        $service = $rotation->service(limits: new AgentOperationLimits(128, 1, 1));
        $failure = $this->reject(
            fn(): AgentCredentialRotationResult => $service->rotate($rotation->key, $rotation->request),
            AgentOperationFailure::CAPACITY
        );
        self::assertTrue($failure->isRetryable());
        self::assertSame(0, $env->agents->all()[0]->getCredentialRevision());
        self::assertNotNull($env->operations->operations[$env->key->toString()]->getMaterial());
        self::assertSame([2], array_values($env->operations->versions));
        self::assertCount(2, $env->audit->all());
        $result = $rotation->service(limits: new AgentOperationLimits(128, 2, 2))
            ->rotate($rotation->key, $rotation->request);
        $key = $rotation->key->toString();
        $env->operations->operations[$key] = $env->operations->operations[$key]->retireMaterial();
        $count = $env->generations;
        self::assertSame($result->getIssuance(), $service->rotate($rotation->key, $rotation->request)->getIssuance());
        self::assertSame($count, $env->generations);
        self::assertNull($env->operations->operations[$key]->getMaterial());
        self::assertSame(3, $result->getIssuance()->getDestinationWriteVersion());
    }

    public function test_authorized_delegate_and_target_writer_share_fences_and_retries_observe_revocation(): void
    {
        $rotation = new RotationEnvironment();
        $env = $rotation->provisioning;
        $env->authorization->actor = 'delegate';
        $env->authorization->readDelegations['delegate:'.$rotation->key->getScope()->toString()] = true;
        $env->authorization->afterAuthorization = static function () use ($env, $rotation): void {
            $env->authorization->changeAuthority(static function () use ($env, $rotation): void {
                $env->authorization->deniedTargets[] = $rotation->request->getAgentId()->toString();
            });
            self::assertSame([], $env->authorization->deniedTargets);
        };
        $result = $rotation->service()->rotate($rotation->key, $rotation->request);
        self::assertTrue($result->isConfirmed());
        self::assertSame($rotation->key, $result->getIssuance()->getKey());
        self::assertSame('delegate', $env->audit->all()[1]->actorId());
        $this->reject(
            fn(): AgentCredentialRotationResult => $rotation->service()->rotate($rotation->key, $rotation->request),
            AgentOperationFailure::UNAUTHORIZED
        );
    }

    /** @return iterable<string, array{string}> */
    public static function deliveryStages(): iterable
    {
        foreach (['pending', 'claimed', 'admitted', 'in flight', 'delivered'] as $stage) {
            yield $stage => [$stage];
        }
    }

    #[DataProvider('deliveryStages')]
    public function test_rotation_fences_real_delivery_and_late_staged_bytes_never_acknowledge(string $stage): void
    {
        $delivery = new DeliveryEnvironment();
        $rotation = new RotationEnvironment($delivery);
        $rotate = static function () use ($rotation): void {
            self::assertTrue($rotation->service()->rotate($rotation->key, $rotation->request)->isConfirmed());
        };
        if ($stage === 'pending') {
            $rotate();
        } elseif ($stage === 'claimed' || $stage === 'admitted') {
            $delivery->transaction->afterCommit = static function (int $commit) use ($stage, $rotate): void {
                if ($commit === ($stage === 'claimed' ? 1 : 2)) {
                    $rotate();
                }
            };
        } elseif ($stage === 'in flight') {
            $delivery->sink->beforeStage = $rotate;
        } else {
            self::assertSame(AgentDeliveryResult::DELIVERED, $delivery->deliver());
            $rotate();
        }

        self::assertSame(AgentDeliveryResult::REJECTED, $delivery->deliver());
        $old = $delivery->operation();
        self::assertSame(AgentCredentialDisposition::SUPERSEDED, $old->getStatus()->getCredentialDisposition());
        self::assertNull($old->getMaterial());
        if ($stage === 'delivered') {
            self::assertNotNull($old->getReceipt());
            self::assertSame(AgentDeliveryDisposition::DELIVERED, $old->getStatus()->getDeliveryDisposition());
        } else {
            self::assertNull($old->getReceipt());
            self::assertSame(AgentDeliveryDisposition::RETIRED, $old->getStatus()->getDeliveryDisposition());
        }

        $calls = in_array($stage, ['admitted', 'in flight', 'delivered'], true) ? 1 : 0;
        self::assertSame($calls, $delivery->sink->calls);
        self::assertSame($calls, $delivery->decipher->calls);
        if ($calls === 1) {
            self::assertSame('original-test-secret', $delivery->sink->stagedBytes($delivery->issuance));
        }

        self::assertSame(AgentDeliveryResult::REJECTED, $delivery->deliver());
        self::assertSame($calls, $delivery->sink->calls);
        self::assertSame(2, $rotation->provisioning->generations);
    }

    public function test_successor_delivery_wins_over_an_older_in_flight_invocation(): void
    {
        $delivery = new DeliveryEnvironment();
        $rotation = new RotationEnvironment($delivery);
        $delivery->sink->beforeStage = static function () use ($delivery, $rotation): void {
            $delivery->sink->beforeStage = null;
            $successor = $rotation->service()->rotate($rotation->key, $rotation->request)->getIssuance();
            self::assertNotNull($successor);
            self::assertSame(AgentDeliveryResult::DELIVERED, $delivery->service()->deliver(
                $rotation->key,
                $successor->getDestination(),
                $successor->getDeliveryId()
            ));
            self::assertSame('successor-test-secret', $delivery->sink->stagedBytes($successor));
            self::assertSame(
                $successor,
                $rotation->service()->rotate($rotation->key, $rotation->request)->getIssuance()
            );
        };
        self::assertSame(AgentDeliveryResult::REJECTED, $delivery->deliver());
        self::assertNull($delivery->sink->stagedBytes($delivery->issuance));
        self::assertNull($delivery->operation()->getReceipt());
        self::assertNull($delivery->operation()->getMaterial());
        $successor = $rotation->provisioning->operations->operations[$rotation->key->toString()];
        self::assertSame(AgentDeliveryDisposition::DELIVERED, $successor->getStatus()->getDeliveryDisposition());
        self::assertNotNull($successor->getReceipt());
        self::assertNull($successor->getMaterial());
        self::assertSame(2, $rotation->provisioning->generations);
    }

    public function test_closed_outer_or_missing_target_paths_never_issue(): void
    {
        $rotation = new RotationEnvironment();
        $env = $rotation->provisioning;
        $closed = $this->createMock(TransactionalUnitOfWork::class);
        $closed->method('isClosed')->willReturn(true);
        $closed->expects(self::never())->method('commitTransactional');
        $this->reject(
            fn(): AgentCredentialRotationResult => $rotation->service($closed)->rotate(
                $rotation->key,
                $rotation->request
            )
        );
        $env->transaction->commitTransactional(function () use ($rotation): void {
            $this->reject(
                fn(): AgentCredentialRotationResult => $rotation->service()->rotate($rotation->key, $rotation->request)
            );
        });
        $request = new AgentRotationRequest(
            AgentId::generate(),
            $rotation->request->getExpectedCredentialId(),
            0,
            $rotation->request->getDestination()
        );
        $this->reject(
            fn(): AgentCredentialRotationResult => $rotation->service()->rotate($rotation->key, $request),
            AgentOperationFailure::CONFLICT
        );
        self::assertSame(1, $env->generations);
        self::assertCount(1, $env->audit->all());
    }

    public function test_absent_collision_winner_is_bounded_contention_and_never_reissues(): void
    {
        $rotation = new RotationEnvironment();
        $env = $rotation->provisioning;
        $before = $env->agents->all()[0];
        $original = $env->operations->operations[$env->key->toString()];
        $env->operations->beforeAdd = static function (): void {
            throw new AgentOperationCollisionException();
        };
        $this->reject(
            fn(): AgentCredentialRotationResult => $rotation->service()->rotate($rotation->key, $rotation->request),
            AgentOperationFailure::CONTENTION
        );
        self::assertSame(3, $env->transaction->transactions);
        self::assertSame(2, $env->generations);
        self::assertSame([$before], $env->agents->all());
        self::assertSame([$env->key->toString() => $original], $env->operations->operations);
        self::assertSame([1], array_values($env->operations->versions));
    }

    /** @return iterable<string, array{string}> */
    public static function collisions(): iterable
    {
        foreach (['unique same', 'unique conflicting', 'cas same', 'cas different', 'loaded successor'] as $case) {
            yield $case => [$case];
        }
    }

    #[DataProvider('collisions')]
    public function test_concurrent_loser_rolls_back_then_resolves_only_the_authoritative_winner(string $case): void
    {
        $rotation = new RotationEnvironment();
        $env = $rotation->provisioning;
        $original = $env->agents->all()[0];
        $winner = new ProvisioningEnvironment();
        $winner->agents->add($original);
        $winner->operations->operations = $env->operations->operations;
        $winner->operations->versions = $env->operations->versions;
        $winner->authorization->scopes = $env->authorization->scopes;
        $winner->authorization->destinations = $env->authorization->destinations;

        $request = $rotation->request;
        if ($case === 'unique conflicting') {
            $destination = new AgentCredentialDestination(AgentDestinationId::generate(), 1);
            $winner->authorization->destinations[$destination->getId()->toString()] = 1;
            $request = new AgentRotationRequest($original->getId(), $original->getCredentialId(), 0, $destination);
        }

        $winningKey = $case === 'cas different' ? $this->newKey($rotation) : $rotation->key;
        $result = $rotation->service(environment: $winner)->rotate($winningKey, $request);
        $winningAgent = $winner->agents->all()[0];
        $publishWinner = static function () use ($env, $original, $winningAgent, $winner): void {
            $env->transaction->onCompletion(static function () use ($env, $original, $winningAgent, $winner): void {
                // Observe complete rollback before making an independent committed winner visible.
                self::assertSame([$original], $env->agents->all());
                self::assertCount(1, $env->operations->operations);
                self::assertNotNull($env->operations->operations[$env->key->toString()]->getMaterial());
                self::assertSame([1], array_values($env->operations->versions));
                self::assertTrue($env->agents->replace($original, $winningAgent));
                $env->operations->operations = $winner->operations->operations;
                $env->operations->versions = $winner->operations->versions;
                $env->audit->add($winner->audit->all()[0]);
            });
        };
        $agents = null;
        if (str_starts_with($case, 'unique')) {
            $env->operations->beforeAdd = static function () use ($publishWinner): void {
                $publishWinner();
                throw new AgentOperationCollisionException();
            };
        } else {
            $agents = $this->createMock(AgentRepository::class);
            $agents->method('getOperationContract')->willReturn($env->agents->getOperationContract());
            $agents->expects(self::once())->method('getById')->willReturnCallback(static function () use (
                $case,
                $publishWinner,
                $original,
                $winningAgent
            ): Agent {
                if ($case === 'loaded successor') {
                    $publishWinner();

                    return $winningAgent;
                }

                return $original;
            });
            if ($case !== 'loaded successor') {
                $agents->expects(self::once())->method('replace')->willReturnCallback(static function () use (
                    $publishWinner
                ): bool {
                    $publishWinner();

                    return false;
                });
            }
        }

        $service = $rotation->service(agents: $agents);
        if (in_array($case, ['unique conflicting', 'cas different'], true)) {
            $this->reject(
                fn(): AgentCredentialRotationResult => $service->rotate($rotation->key, $rotation->request),
                AgentOperationFailure::CONFLICT
            );
        } else {
            self::assertSame(
                $result->getIssuance(),
                $service->rotate($rotation->key, $rotation->request)->getIssuance()
            );
        }

        self::assertSame([$winningAgent], $env->agents->all());
        self::assertCount(2, $env->operations->operations);
        self::assertCount(2, $env->audit->all());
        self::assertSame(3, $env->transaction->transactions);
        self::assertCount(0, array_filter(
            $env->events->events(),
            static fn(object $event): bool => $event instanceof AgentCredentialRotated
        ));
        self::assertSame($case === 'loaded successor' ? 1 : 2, $env->generations);
    }

    public function test_rotation_after_revocation_rejects_but_original_resolution_survives_later_rotation(): void
    {
        $delivery = new DeliveryEnvironment();
        $rotation = new RotationEnvironment($delivery);
        $first = $rotation->service()->rotate($rotation->key, $rotation->request)->getIssuance();
        self::assertNotNull($first);
        $second = $rotation->service()->rotate($this->newKey($rotation), new AgentRotationRequest(
            $first->getAgentId(),
            $first->getCredentialId(),
            $first->getCredentialRevision(),
            $first->getDestination()
        ));
        self::assertSame(2, $second->getIssuance()->getCredentialRevision());
        $delivery->revoke();
        self::assertSame($first, $rotation->service()->rotate($rotation->key, $rotation->request)->getIssuance());
        $current = $rotation->provisioning->agents->all()[0];
        $request = new AgentRotationRequest(
            $current->getId(),
            $current->getCredentialId(),
            $current->getCredentialRevision(),
            $first->getDestination()
        );
        $this->reject(
            fn(): AgentCredentialRotationResult => $rotation->service()->rotate($this->newKey($rotation), $request),
            AgentOperationFailure::CONFLICT
        );
        self::assertSame(3, $rotation->provisioning->generations);
    }

    public function test_destination_reassignment_across_scopes_preserves_global_order_and_distinct_delivery(): void
    {
        $rotation = new RotationEnvironment();
        $env = $rotation->provisioning;
        $scope = new AgentOperationScope('consumer-b', 'agent', 'worker');
        $env->authorization->actor = 'worker';
        $env->authorization->scopes[$scope->toString()] = true;
        $destination = new AgentCredentialDestination($rotation->request->getDestination()->getId(), 2);
        $env->authorization->destinations[$destination->getId()->toString()] = 2;
        $result = $rotation->service()->rotate(
            new AgentOperationKey($scope, $env->key->getId()),
            new AgentRotationRequest(
                $rotation->request->getAgentId(),
                $rotation->request->getExpectedCredentialId(),
                0,
                $destination
            )
        );
        self::assertTrue($result->isConfirmed());
        self::assertSame(2, $result->getIssuance()->getDestinationWriteVersion());
        self::assertSame(2, $result->getIssuance()->getDestination()->getRevision());
        self::assertFalse($rotation->original->getDeliveryId()->equals($result->getIssuance()->getDeliveryId()));
        self::assertSame($scope, $result->getIssuance()->getKey()->getScope());
        self::assertNull($env->operations->operations[$env->key->toString()]->getMaterial());
    }

    private function newKey(RotationEnvironment $rotation): AgentOperationKey
    {
        return new AgentOperationKey($rotation->key->getScope(), AgentOperationId::generate());
    }

    private function reject(
        #[SensitiveParameter] Closure $operation,
        AgentOperationFailure $reason = AgentOperationFailure::UNAVAILABLE
    ): AgentOperationRejectedException {
        try {
            $operation();
            self::fail('Expected a safe rejection.');
        } catch (AgentOperationRejectedException $agentOperationRejectedException) {
            self::assertSame($reason, $agentOperationRejectedException->getReason());

            return $agentOperationRejectedException;
        }
    }

    private function assertSafe(mixed $value): void
    {
        $representation = '';
        if ($value instanceof AgentOperationRejectedException) {
            $representation = (string) $value;
            $trace = [];
            foreach ($value->getTrace() as $frame) {
                // Inspect the failing call chain, not PHPUnit's entire runner object graph.
                if (str_starts_with($frame['function'], 'test_')) {
                    break;
                }

                $trace[] = $frame;
            }

            $value = ['message' => $value->getMessage(), 'trace' => $trace];
        } else {
            $representation = serialize($value);
        }

        ob_start();
        var_dump($value);
        $debug = ob_get_clean();
        self::assertIsString($debug);
        foreach (['original-test-secret', 'successor-test-secret', 'auth-envelope:', 'key/path', 'Unsafe'] as $unsafe) {
            self::assertStringNotContainsString($unsafe, $representation);
            self::assertStringNotContainsString($unsafe, $debug);
        }
    }
}
