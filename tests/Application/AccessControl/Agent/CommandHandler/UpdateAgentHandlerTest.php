<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\CommandHandler;

use Closure;
use DateTimeImmutable;
use Fiber;
use Fight\AccessControl\Application\AccessControl\Agent\CommandHandler\UpdateAgentHandler;
use Fight\AccessControl\Application\AccessControl\Agent\Credential\AgentCredentialLifecycleService;
use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentName;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentRepository;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentState;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentUpdateInitiator;
use Fight\AccessControl\Domain\AccessControl\Agent\Command\UpdateAgent;
use Fight\AccessControl\Domain\AccessControl\Agent\Event\AgentNameChanged;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentNameException;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentOperationRejectedException;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentUpdateException;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationFailure;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Adapter\Messaging\Command\Sync\Routing\CommandRouter;
use Fight\Common\Adapter\Messaging\Command\Sync\RoutingCommandBus;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Fight\Common\Domain\Messaging\Event\Event;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Credential\ProvisioningEnvironment;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Credential\RotationEnvironment;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

#[CoversClass(UpdateAgentHandler::class)]
#[CoversClass(Agent::class)]
#[CoversClass(UpdateAgent::class)]
#[CoversClass(AgentNameChanged::class)]
final class UpdateAgentHandlerTest extends TestCase
{
    private const string NOW = '2026-09-27T12:00:00+00:00';

    /** @return iterable<string, array{string}> */
    public static function competingWriters(): iterable
    {
        foreach (['name', 'permission', 'rotation'] as $winner) {
            yield $winner => [$winner];
        }
    }

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

    #[DataProvider('normalizedNames')]
    public function test_unicode_rename_then_equivalent_noop_preserve_original_provisioning(
        string $input,
        string $expected
    ): void {
        $env = $this->environment();
        $before = $env->agentRepository->all()[0];
        $operation = $env->agentOperationRepository->operations[$env->key->toString()];
        $handler = $this->handler($env);
        $handler->handle($this->message($before->getId(), $input));

        $after = $env->agentRepository->getById($before->getId());
        self::assertSame($expected, $after->getName()->toString());
        $event = $env->eventDispatcher->events()[0];
        self::assertInstanceOf(AgentNameChanged::class, $event);
        self::assertSame($expected, $event->getName()->toString());
        $handler->handle($this->message($before->getId(), $expected));
        self::assertSame($after, $env->agentRepository->getById($before->getId()));
        self::assertSame(1, $env->agentRepository->nameWrites);
        self::assertCount(1, $env->eventDispatcher->events());
        self::assertSame($operation, $env->agentOperationRepository->operations[$env->key->toString()]);
        self::assertSame($before->getCredentialId(), $after->getCredentialId());
        self::assertSame($before->getPermissionIds(), $after->getPermissionIds());
        self::assertSame(
            $operation->getIssuance(),
            $env->service()->provision($env->key, $env->request)->getIssuance()
        );
        self::assertSame($after, $env->agentRepository->getById($before->getId()));
        self::assertSame(1, $env->generations);
        self::assertCount(1, $env->eventDispatcher->events());
    }

    public function test_handler_uses_name_only_intent_and_publishes_after_transaction_for_both_actor_types(): void
    {
        self::assertSame(UpdateAgent::class, UpdateAgentHandler::commandRegistration());
        foreach ([UserId::generate(), AgentId::generate()] as $actor) {
            $initiator = new AgentUpdateInitiator($actor);
            $command = new UpdateAgent($initiator, AgentId::generate(), '  Renamed  ');
            $uow = new InMemoryUnitOfWork();
            $agentRepository = $this->createMock(AgentRepository::class);
            $clock = new FixedClock(self::NOW);
            $agentRepository->expects(self::once())->method('rename')->with(
                $command->getAgentId(),
                self::callback(static fn(AgentName $name): bool => $name->toString() === 'Renamed'),
                self::isInstanceOf(Closure::class)
            )->willReturnCallback(static function (
                AgentId $id,
                AgentName $name,
                Closure $now
            ) use (
                $uow,
                $clock
            ): DateTimeImmutable {
                self::assertTrue($uow->transactionActive);
                self::assertSame(0, $clock->calls());

                return $now();
            });
            $eventDispatcher = new InMemoryEventDispatcher(static function (Event $event) use (
                $uow,
                $initiator,
                $command
            ): void {
                self::assertFalse($uow->transactionActive);
                self::assertTrue($uow->transactionCompleted);
                self::assertInstanceOf(AgentNameChanged::class, $event);
                self::assertSame($initiator, $event->getInitiator());
                self::assertSame($command->getAgentId(), $event->getAgentId());
                self::assertSame('Renamed', $event->getName()->toString());
            });
            new UpdateAgentHandler($agentRepository, $clock, $uow, $eventDispatcher)->handle(
                CommandMessage::create($command)
            );
            self::assertCount(1, $eventDispatcher->events());
            self::assertSame(1, $uow->transactions);
        }
    }

    public function test_invalid_names_dispatch_original_safe_command_failure_before_transaction_or_clock(): void
    {
        foreach (['', " \t\n\0", "\u{00A0}\u{3000}", str_repeat('é', 121), "\xFF", "Valid\xC3"] as $name) {
            $command = new UpdateAgent(new AgentUpdateInitiator(UserId::generate()), AgentId::generate(), $name);
            $agentRepository = $this->createMock(AgentRepository::class);
            $agentRepository->expects(self::never())->method('rename');
            $clock = $this->createMock(Clock::class);
            $clock->expects(self::never())->method('now');
            $uow = new InMemoryUnitOfWork();
            $eventDispatcher = new InMemoryEventDispatcher();
            $failure = $this->failure(fn() => new UpdateAgentHandler($agentRepository, $clock, $uow, $eventDispatcher)
                ->handle(CommandMessage::create($command)));
            self::assertInstanceOf(AgentNameException::class, $failure);
            self::assertSame(0, $uow->transactions);
            $event = $eventDispatcher->events()[0];
            self::assertInstanceOf(CommandFailedEvent::class, $event);
            self::assertSame($command, $event->getCommand());
            self::assertSame('Agent update failed.', $event->getErrorMessage());
        }
    }

    public function test_real_change_then_normalized_noop_preserve_authority_operation_and_timestamp(): void
    {
        $env = $this->environment();
        $before = $env->agentRepository->all()[0];
        $operation = $env->agentOperationRepository->operations[$env->key->toString()];
        $clock = new FixedClock('2026-09-27T12:00:01+00:00', '2026-09-27T13:00:00+00:00');
        $handler = $this->handler($env, $clock);
        $handler->handle($this->message($before->getId(), '  Renamed  '));

        $after = $env->agentRepository->getById($before->getId());
        self::assertSame('Renamed', $after->getName()->toString());
        self::assertSame(1, $env->agentRepository->nameWrites);
        self::assertSame($before->getCredentialId(), $after->getCredentialId());
        self::assertSame(
            $before->getEncryptedHmacSharedSecretEnvelope(),
            $after->getEncryptedHmacSharedSecretEnvelope()
        );
        self::assertSame($before->getPermissionIds(), $after->getPermissionIds());
        self::assertSame($operation, $env->agentOperationRepository->operations[$env->key->toString()]);
        $handler->handle($this->message($before->getId(), 'Renamed'));
        self::assertSame(2, $clock->calls());
        self::assertSame($after, $env->agentRepository->getById($before->getId()));
        self::assertSame(1, $env->agentRepository->nameWrites);
        self::assertCount(1, $env->eventDispatcher->events());
        self::assertInstanceOf(AgentNameChanged::class, $env->eventDispatcher->events()[0]);
        self::assertFalse($env->agentRepository->contract->locked);
    }

    public function test_missing_and_revoked_targets_reject_including_same_name_without_success_fact(): void
    {
        $env = $this->environment();
        $agent = $env->agentRepository->all()[0];
        self::assertInstanceOf(AgentUpdateException::class, $this->failure(fn() => $this->handler($env)
            ->handle($this->message(AgentId::generate(), 'Renamed'))));
        $this->revoke($env, $agent->getId());
        $revoked = $env->agentRepository->getById($agent->getId());
        foreach ([$agent->getName()->toString(), 'Renamed'] as $name) {
            self::assertInstanceOf(AgentUpdateException::class, $this->failure(fn() => $this->handler($env)
                ->handle($this->message($agent->getId(), $name))));
            self::assertSame($revoked, $env->agentRepository->getById($agent->getId()));
        }

        self::assertSame(0, $env->agentRepository->nameWrites);
        self::assertCount(0, array_filter(
            $env->eventDispatcher->events(),
            static fn($event): bool => $event instanceof AgentNameChanged
        ));
    }

    public function test_write_and_commit_failures_roll_back_and_rethrow_original_failure(): void
    {
        foreach (['write', 'commit'] as $stage) {
            $env = $this->environment();
            $before = $env->agentRepository->all()[0];
            $operation = $env->agentOperationRepository->operations[$env->key->toString()];
            $failure = new RuntimeException('Injected name persistence fault.');
            if ($stage === 'write') {
                $env->agentRepository->afterNameWrite = static fn(): never => throw $failure;
            } else {
                $env->transaction->failNextCommit = true;
            }

            $thrown = $this->failure(fn() => $this->handler($env)->handle($this->message($before->getId(), 'Renamed')));
            if ($stage === 'write') {
                self::assertSame($failure, $thrown);
            } else {
                self::assertSame('Injected transaction failure.', $thrown->getMessage());
            }

            self::assertSame($before, $env->agentRepository->getById($before->getId()));
            self::assertSame($operation, $env->agentOperationRepository->operations[$env->key->toString()]);
            self::assertInstanceOf(CommandFailedEvent::class, $env->eventDispatcher->events()[0]);
            $env->agentRepository->afterNameWrite = null;
            $this->handler($env)->handle($this->message($before->getId(), 'Renamed'));
            self::assertSame('Renamed', $env->agentRepository->getById($before->getId())->getName()->toString());
        }
    }

    public function test_publication_failure_keeps_commit_and_original_throwable_without_success(): void
    {
        foreach ([false, true] as $failurePublisherAlsoFails) {
            $env = $this->environment();
            $agent = $env->agentRepository->all()[0];
            $failure = new RuntimeException('Injected event publication failure.');
            $env->eventDispatcher = new InMemoryEventDispatcher(static function (Event $event) use (
                $failure,
                $failurePublisherAlsoFails
            ): void {
                if ($event instanceof AgentNameChanged) {
                    throw $failure;
                }

                if ($failurePublisherAlsoFails) {
                    throw new RuntimeException('Failure notification also failed.');
                }
            });
            self::assertSame($failure, $this->failure(fn() => $this->handler($env)
                ->handle($this->message($agent->getId(), 'Renamed'))));
            self::assertSame('Renamed', $env->agentRepository->getById($agent->getId())->getName()->toString());
            self::assertSame(1, $env->agentRepository->nameWrites);
            if (!$failurePublisherAlsoFails) {
                self::assertInstanceOf(CommandFailedEvent::class, $env->eventDispatcher->events()[0]);
            }

            $env->eventDispatcher = new InMemoryEventDispatcher();
            $this->handler($env)->handle($this->message($agent->getId(), 'Renamed'));
            self::assertSame([], $env->eventDispatcher->events());
            self::assertSame(1, $env->agentRepository->nameWrites);
        }
    }

    #[DataProvider('competingWriters')]
    public function test_paused_rename_observes_competing_rename_permission_and_rotation_winners(string $winner): void
    {
        $rotation = new RotationEnvironment();
        $env = $rotation->provisioning;
        $env->eventDispatcher = new InMemoryEventDispatcher();

        $before = $env->agentRepository->all()[0];
        $now = new DateTimeImmutable('2026-09-27T12:00:01+00:00');
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturnCallback(static function () use (&$now): DateTimeImmutable {
            return $now;
        });
        $env->agentRepository->beforeNameWrite = function () use ($env): void {
            $env->agentRepository->beforeNameWrite = null;
            $env->transaction->suspendBeforeFirstWrite();
        };
        $pending = new Fiber(fn() => $this->handler($env, $clock)
            ->handle($this->message($before->getId(), 'Last rename')));
        $pending->start();
        self::assertTrue($pending->isSuspended());
        $now = new DateTimeImmutable('2026-09-27T12:00:02+00:00');
        if ($winner === 'name') {
            $this->handler($env, $clock)->handle($this->message($before->getId(), 'First rename'));
        } elseif ($winner === 'permission') {
            $permission = Permission::define(
                PermissionId::generate(),
                PermissionName::fromString('PROFILE'),
                new DateTimeImmutable(self::NOW)
            );
            $env->transaction->authorizationReferenceState()->addPermission($permission);
            $env->transaction->commitTransactional(function () use ($env, $before, $permission, $now): void {
                self::assertTrue($env->agentRepository->replacePermissionAssignments(
                    $before,
                    $before->grantPermission($permission->getId(), $now)
                ));
            });
        } else {
            self::assertTrue($rotation->service(clock: $clock)->rotate($rotation->key, $rotation->request)
                ->isConfirmed());
        }

        $winnerState = $env->agentRepository->getById($before->getId());
        self::assertEquals($now, $winnerState->getUpdatedAt());
        $operations = $env->agentOperationRepository->operations;
        $now = new DateTimeImmutable('2026-09-27T12:00:03.123456+00:00');
        $pending->resume();
        self::assertTrue($pending->isTerminated());
        $after = $env->agentRepository->getById($before->getId());
        self::assertSame('Last rename', $after->getName()->toString());
        self::assertSame($winnerState->getCredentialId(), $after->getCredentialId());
        self::assertSame($winnerState->getCredentialRevision(), $after->getCredentialRevision());
        self::assertSame(
            $winnerState->getEncryptedHmacSharedSecretEnvelope(),
            $after->getEncryptedHmacSharedSecretEnvelope()
        );
        self::assertSame($winnerState->getPermissionIds(), $after->getPermissionIds());
        self::assertSame(
            $winnerState->getPermissionAssignmentRevision(),
            $after->getPermissionAssignmentRevision()
        );
        self::assertSame($operations, $env->agentOperationRepository->operations);
        self::assertEquals($now, $after->getUpdatedAt());
        $event = array_last($env->eventDispatcher->events());
        self::assertInstanceOf(AgentNameChanged::class, $event);
        self::assertEquals($after->getUpdatedAt(), $event->getChangedAt());
    }

    public function test_revocation_wins_against_paused_real_and_noop_rename(): void
    {
        foreach ([true, false] as $noop) {
            $env = $this->environment();
            $before = $env->agentRepository->all()[0];
            $name = $noop ? "\u{00A0}".$before->getName()->toString()."\u{3000}" : 'Renamed';
            $env->agentRepository->beforeNameWrite = function () use ($env): void {
                $env->agentRepository->beforeNameWrite = null;
                $env->transaction->suspendBeforeFirstWrite();
            };
            $pending = new Fiber(fn(): Throwable => $this->failure(fn() => $this->handler($env)
                ->handle($this->message($before->getId(), $name))));
            $pending->start();
            $this->revoke($env, $before->getId());
            $revoked = $env->agentRepository->getById($before->getId());
            $pending->resume();
            self::assertInstanceOf(AgentUpdateException::class, $pending->getReturn());
            self::assertSame($revoked, $env->agentRepository->getById($before->getId()));
            self::assertSame(AgentState::REVOKED, $revoked->getState());
            self::assertSame(0, $env->agentRepository->nameWrites);
        }
    }

    public function test_name_winner_rejects_stale_whole_agent_writers_and_fresh_revocation_preserves_name(): void
    {
        $rotation = new RotationEnvironment();
        $env = $rotation->provisioning;
        $env->eventDispatcher = new InMemoryEventDispatcher();

        $before = $env->agentRepository->all()[0];
        $permission = Permission::define(
            PermissionId::generate(),
            PermissionName::fromString('PROFILE'),
            new DateTimeImmutable(self::NOW)
        );
        $env->transaction->authorizationReferenceState()->addPermission($permission);
        $this->handler($env)->handle($this->message($before->getId(), 'Renamed'));
        $renamed = $env->agentRepository->getById($before->getId());
        $env->transaction->commitTransactional(function () use ($env, $before, $permission): void {
            self::assertFalse(
                $env->agentRepository->replace($before, $before->revoke(new DateTimeImmutable(self::NOW)))
            );
            self::assertFalse($env->agentRepository->replacePermissionAssignments(
                $before,
                $before->grantPermission($permission->getId(), new DateTimeImmutable(self::NOW))
            ));
        });
        self::assertSame($renamed, $env->agentRepository->getById($before->getId()));
        $env->transaction->commitTransactional(function () use ($env, $renamed, $permission): void {
            self::assertTrue($env->agentRepository->replacePermissionAssignments(
                $renamed,
                $renamed->grantPermission($permission->getId(), new DateTimeImmutable(self::NOW))
            ));
        });
        self::assertTrue($rotation->service()->rotate($rotation->key, $rotation->request)->isConfirmed());
        $rotated = $env->agentRepository->getById($before->getId());
        self::assertSame('Renamed', $rotated->getName()->toString());
        self::assertTrue($rotated->hasPermission($permission->getId()));
        self::assertSame(1, $rotated->getCredentialRevision());
        $this->revoke($env, $before->getId());
        self::assertSame('Renamed', $env->agentRepository->getById($before->getId())->getName()->toString());
        self::assertSame(AgentState::REVOKED, $env->agentRepository->getById($before->getId())->getState());
    }

    public function test_direct_noops_require_current_correlation_transaction_and_restore_admission(): void
    {
        foreach (['transaction', 'correlation', 'duplicate', 'restore'] as $case) {
            $env = $this->environment();
            $before = $env->agentRepository->all()[0];
            if ($case === 'correlation') {
                $env->agentOperationRepository->operations = [];
            } elseif ($case === 'duplicate') {
                $env->agentOperationRepository->operations['duplicate'] = $env->agentOperationRepository->operations[
                    $env->key->toString()
                ];
            } elseif ($case === 'restore') {
                $env->agentRepository->contract->reconciledGeneration = null;
            }

            $clock = $this->createMock(Clock::class);
            $clock->expects(self::never())->method('now');
            $attempt = fn(): ?DateTimeImmutable => $env->agentRepository->rename(
                $before->getId(),
                $before->getName(),
                $clock->now(...)
            );
            $failure = $this->failure(
                $case === 'transaction' ? $attempt : fn(): mixed => $env->transaction->commitTransactional($attempt)
            );
            self::assertInstanceOf(AgentOperationRejectedException::class, $failure);
            $reason = AgentOperationFailure::UNAVAILABLE;
            if ($case === 'correlation' || $case === 'duplicate') {
                $reason = AgentOperationFailure::CONFLICT;
            }

            self::assertSame($reason, $failure->getReason());
            self::assertSame($before, $env->agentRepository->getById($before->getId()));
            self::assertSame(0, $env->agentRepository->nameWrites);
            self::assertSame([], $env->eventDispatcher->events());
        }
    }

    public function test_direct_name_write_rejects_a_backdating_clock_without_partial_effects(): void
    {
        $env = $this->environment();
        $before = $env->agentRepository->all()[0];
        $operation = $env->agentOperationRepository->operations[$env->key->toString()];
        $failure = $this->failure(fn(): mixed => $env->transaction->commitTransactional(
            fn(): ?DateTimeImmutable => $env->agentRepository->rename(
                $before->getId(),
                AgentName::fromString('Renamed'),
                static fn(): DateTimeImmutable => new DateTimeImmutable('2026-09-27T11:59:59+00:00')
            )
        ));
        self::assertInstanceOf(AgentUpdateException::class, $failure);
        self::assertSame('The Agent name update time is stale.', $failure->getMessage());
        self::assertSame($before, $env->agentRepository->getById($before->getId()));
        self::assertSame($operation, $env->agentOperationRepository->operations[$env->key->toString()]);
        self::assertSame(0, $env->agentRepository->nameWrites);
        self::assertSame([], $env->eventDispatcher->events());
    }

    public function test_void_bus_allows_identical_input_acknowledgements_only_after_success(): void
    {
        $env = $this->environment();
        $id = $env->agentRepository->all()[0]->getId();
        $router = $this->createStub(CommandRouter::class);
        $router->method('match')->willReturnCallback(fn(): UpdateAgentHandler => $this->handler($env));
        $synchronousCommandBus = new RoutingCommandBus($router);
        $acknowledgements = [];
        $acknowledge = static function (UpdateAgent $command) use ($synchronousCommandBus, &$acknowledgements): void {
            $name = AgentName::fromString($command->getName());
            $synchronousCommandBus->execute($command);
            $acknowledgements[] = ['agent_id' => $command->getAgentId()->toString(), 'name' => $name->toString()];
        };
        foreach (['  Renamed  ', 'Renamed'] as $name) {
            $acknowledge(new UpdateAgent(new AgentUpdateInitiator($id), $id, $name));
        }

        self::assertSame([
            ['agent_id' => $id->toString(), 'name' => 'Renamed'],
            ['agent_id' => $id->toString(), 'name' => 'Renamed']
        ], $acknowledgements);
        self::assertSame(1, $env->agentRepository->nameWrites);
        self::assertCount(1, $env->eventDispatcher->events());
        $env->agentRepository->afterNameWrite = static fn(): never => throw new RuntimeException('Write failed.');
        $this->failure(fn() => $acknowledge(new UpdateAgent(new AgentUpdateInitiator($id), $id, 'Another name')));
        self::assertCount(2, $acknowledgements);
        self::assertSame('Renamed', $env->agentRepository->getById($id)->getName()->toString());
        $env->agentRepository->afterNameWrite = null;
        $env->eventDispatcher = new InMemoryEventDispatcher(
            static fn(): never => throw new RuntimeException('Publication failed.')
        );
        $this->failure(fn() => $acknowledge(new UpdateAgent(new AgentUpdateInitiator($id), $id, 'Committed name')));
        self::assertCount(2, $acknowledgements);
        self::assertSame('Committed name', $env->agentRepository->getById($id)->getName()->toString());
    }

    private function environment(): ProvisioningEnvironment
    {
        $env = new ProvisioningEnvironment();
        $env->service()->provision($env->key, $env->request);
        $env->eventDispatcher = new InMemoryEventDispatcher();

        return $env;
    }

    private function handler(ProvisioningEnvironment $env, ?Clock $clock = null): UpdateAgentHandler
    {
        return new UpdateAgentHandler(
            $env->agentRepository,
            $clock ?? new FixedClock(self::NOW),
            $env->transaction,
            $env->eventDispatcher
        );
    }

    private function message(AgentId $id, string $name): CommandMessage
    {
        return CommandMessage::create(new UpdateAgent(new AgentUpdateInitiator(UserId::generate()), $id, $name));
    }

    private function revoke(ProvisioningEnvironment $env, AgentId $id): void
    {
        new AgentCredentialLifecycleService(
            $env->agentRepository,
            $env->auditEvidenceRepository,
            new FixedClock(self::NOW),
            $env->transaction,
            $env->eventDispatcher
        )->revoke('authorized-test-actor', $id);
    }

    private function failure(callable $operation): Throwable
    {
        try {
            $operation();
        } catch (Throwable $throwable) {
            return $throwable;
        }

        self::fail('Expected the operation to reject.');
    }
}
