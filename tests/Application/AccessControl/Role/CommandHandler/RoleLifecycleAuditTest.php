<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Role\CommandHandler;

use Fight\AccessControl\Application\AccessControl\Role\CommandHandler\CreateCustomRoleHandler;
use Fight\AccessControl\Application\AccessControl\Role\CommandHandler\RemoveCustomRoleHandler;
use Fight\AccessControl\Application\AccessControl\Role\CommandHandler\RenameCustomRoleHandler;
use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditAction;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditRoleRevisions;
use Fight\AccessControl\Domain\AccessControl\Role\Role;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\Role\RoleName;
use Fight\Common\Application\Error\Exception\PreconditionFailed;
use Fight\Common\Application\Error\Exception\RuleViolation;
use Fight\Common\Application\Error\Exception\StateConflict;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Fight\Common\Domain\Messaging\Event\Event;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\Role\Repository\InMemoryRoleRepository;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

#[CoversClass(CreateCustomRoleHandler::class)]
#[CoversClass(RenameCustomRoleHandler::class)]
#[CoversClass(RemoveCustomRoleHandler::class)]
final class RoleLifecycleAuditTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function operations(): iterable
    {
        foreach (['create', 'rename', 'remove'] as $operation) {
            yield $operation => [$operation];
        }
    }

    #[DataProvider('operations')]
    public function test_enabled_evidence_matches_persisted_outcome_and_round_tripped_message(string $operation): void
    {
        $fixture = new RoleLifecycleAuditFixture($operation);
        $message = CommandMessage::arrayDeserialize(CommandMessage::create($fixture->command)->arraySerialize());
        $fixture->bus->dispatch($message);
        $current = $fixture->roleRepository->getById($fixture->original->getId());
        self::assertCount(1, $fixture->audit->all());
        $evidence = $fixture->audit->all()[0];
        self::assertEquals($evidence, AuditEvidence::fromArray($evidence->toArray()));
        self::assertEquals($fixture->command->getActorId(), $evidence->actorId());
        self::assertEquals($fixture->original->getId(), $evidence->getSubject()->getId());
        self::assertSame(match ($operation) {
            'create' => AuditAction::ROLE_CREATED,
            'rename' => AuditAction::ROLE_RENAMED,
            'remove' => AuditAction::ROLE_REMOVED
        }, $evidence->action());
        self::assertSame($fixture->outcomeTime, $evidence->getOccurredAt());
        self::assertEquals($message->id(), $evidence->getCorrelation()?->getMessageId());
        $facts = $evidence->getFacts();
        self::assertInstanceOf(AuditRoleRevisions::class, $facts);
        self::assertEquals($operation === 'create' ? null : $fixture->original->getRevision(), $facts->getBefore());
        self::assertEquals($current?->getRevision(), $facts->getAfter());
        self::assertSame($operation !== 'remove', $current !== null);
        if ($current !== null) {
            self::assertSame($fixture->outcomeTime, $current->getUpdatedAt());
        }

        self::assertTrue($fixture->unitOfWork->transactionCompleted);
        self::assertCount(1, $fixture->eventDispatcher->events());
    }

    #[DataProvider('operations')]
    public function test_omitted_and_explicit_null_audit_preserve_business_outcomes(string $operation): void
    {
        foreach ([false, true] as $omitAudit) {
            $fixture = new RoleLifecycleAuditFixture($operation, auditEnabled: false, omitAudit: $omitAudit);
            $fixture->bus->execute($fixture->command);
            self::assertSame([], $fixture->audit->all());
            self::assertTrue($fixture->unitOfWork->transactionCompleted);
            self::assertSame($operation !== 'remove', $fixture->roleRepository->getById(
                $fixture->original->getId()
            ) !== null);
            self::assertCount(1, $fixture->eventDispatcher->events());
        }
    }

    #[DataProvider('operations')]
    public function test_audit_failure_rolls_back_both_and_preserves_primary_fault(string $operation): void
    {
        $notifications = new InMemoryEventDispatcher(static function (): void {
            throw new RuntimeException('Secondary notification fault.');
        });
        $fixture = new RoleLifecycleAuditFixture($operation, failAudit: true, eventDispatcher: $notifications);
        $failure = $this->failure(fn() => $fixture->bus->execute($fixture->command));
        self::assertSame($fixture->audit->failure(), $failure);
        $this->assertOutcome($fixture, $operation, false);
    }

    #[DataProvider('operations')]
    public function test_final_commit_loss_rolls_back_already_staged_evidence(string $operation): void
    {
        $fixture = new RoleLifecycleAuditFixture($operation);
        $fixture->unitOfWork->beforeCommit = static function () use ($fixture): void {
            self::assertCount(1, $fixture->audit->all());
            self::assertTrue($fixture->unitOfWork->transactionActive);
        };
        $fixture->unitOfWork->failNextCommit = true;
        $this->failure(fn() => $fixture->bus->execute($fixture->command));
        $this->assertOutcome($fixture, $operation, false);
    }

    #[DataProvider('operations')]
    public function test_lost_commit_acknowledgement_keeps_both_actual_outcomes_truthful(string $operation): void
    {
        foreach ([false, true] as $committed) {
            $fixture = new RoleLifecycleAuditFixture($operation);
            $primary = new RuntimeException('Commit acknowledgement unavailable.');
            $fault = static function () use ($primary): void {
                throw $primary;
            };
            if ($committed) {
                $fixture->unitOfWork->afterCommit = $fault;
            } else {
                $fixture->unitOfWork->beforeCommit = $fault;
            }

            self::assertSame($primary, $this->failure(fn() => $fixture->bus->execute($fixture->command)));
            $this->assertOutcome($fixture, $operation, $committed);
            self::assertCount(1, $fixture->eventDispatcher->events());
            self::assertInstanceOf(CommandFailedEvent::class, $fixture->eventDispatcher->events()[0]);
        }
    }

    #[DataProvider('operations')]
    public function test_publication_double_failure_leaves_evidence_committed(string $operation): void
    {
        $primary = new RuntimeException('Subscriber failure.');
        $attempts = [];
        $fixture = new RoleLifecycleAuditFixture($operation, eventDispatcher: new InMemoryEventDispatcher(
            static function (Event $event) use ($primary, &$attempts, &$fixture): void {
                self::assertTrue($fixture->unitOfWork->transactionCompleted);
                self::assertCount(1, $fixture->audit->all());
                $attempts[] = $event;
                if ($event instanceof CommandFailedEvent) {
                    throw new RuntimeException('Secondary notification failure.');
                }

                throw $primary;
            }
        ));
        self::assertSame($primary, $this->failure(fn() => $fixture->bus->execute($fixture->command)));
        $this->assertOutcome($fixture, $operation, true);
        self::assertCount(2, $attempts);
        self::assertInstanceOf(CommandFailedEvent::class, $attempts[1]);
    }

    public function test_validated_same_name_rename_never_samples_time_or_writes_mutation_evidence(): void
    {
        $clock = $this->createMock(Clock::class);
        $clock->expects(self::never())->method('now');
        $fixture = new RoleLifecycleAuditFixture('same-name', failAudit: true, clock: $clock);
        $fixture->bus->execute($fixture->command);
        self::assertSame($fixture->original, $fixture->roleRepository->getById($fixture->original->getId()));
        self::assertSame([], $fixture->audit->all());
        self::assertSame([], $fixture->eventDispatcher->events());
        self::assertTrue($fixture->unitOfWork->transactionCompleted);
    }

    public function test_stale_aba_and_recreated_intent_never_produces_evidence_including_same_name(): void
    {
        foreach (['rename', 'remove', 'same-name'] as $operation) {
            foreach (['aba', 'recreation'] as $writer) {
                $fixture = new RoleLifecycleAuditFixture($operation);
                $role = $fixture->original;
                $fixture->unitOfWork->commitTransactional(static function () use ($fixture, $role, $writer): void {
                    if ($writer === 'recreation') {
                        self::assertTrue($fixture->roleRepository->remove($role));
                        $fixture->roleRepository->add(Role::define(
                            $role->getId(),
                            $role->getName(),
                            [],
                            $role->getUpdatedAt()
                        ));

                        return;
                    }

                    $middle = $role->renameCustom(RoleName::fromString('ROLE_OTHER'), $role->getUpdatedAt());
                    self::assertTrue($fixture->roleRepository->replace($role, $middle));
                    self::assertTrue($fixture->roleRepository->replace(
                        $middle,
                        $middle->renameCustom($role->getName(), $role->getUpdatedAt())
                    ));
                });
                $current = $fixture->roleRepository->getById($role->getId());
                self::assertInstanceOf(PreconditionFailed::class, $this->failure(
                    fn() => $fixture->bus->execute($fixture->command)
                ));
                self::assertSame($current, $fixture->roleRepository->getById($role->getId()));
                self::assertSame([], $fixture->audit->all());
            }
        }
    }

    public function test_concurrent_winner_before_cas_survives_without_loser_evidence(): void
    {
        foreach (['rename', 'remove'] as $operation) {
            $clock = $this->createMock(Clock::class);
            $fixture = new RoleLifecycleAuditFixture($operation, clock: $clock);
            $winner = new InMemoryUnitOfWork();
            $repository = new InMemoryRoleRepository(
                $winner,
                authorizationReferences: $fixture->unitOfWork->authorizationReferenceState(),
                state: $fixture->state
            );
            $replacement = $fixture->original->renameCustom(
                RoleName::fromString('ROLE_WINNER'),
                $fixture->outcomeTime
            );
            $clock->expects(self::once())->method('now')->willReturnCallback(static function () use (
                $winner,
                $repository,
                $fixture,
                $replacement
            ) {
                $winner->commitTransactional(static function () use ($repository, $fixture, $replacement): void {
                    self::assertTrue($repository->replace($fixture->original, $replacement));
                });

                return $fixture->outcomeTime;
            });
            self::assertInstanceOf(StateConflict::class, $this->failure(
                fn() => $fixture->bus->execute($fixture->command)
            ));
            self::assertSame($replacement, $fixture->roleRepository->getById($fixture->original->getId()));
            self::assertSame([], $fixture->audit->all());
        }
    }

    public function test_enabled_audit_preserves_name_collisions_and_managed_identity_guards(): void
    {
        foreach (['create', 'rename'] as $operation) {
            $fixture = new RoleLifecycleAuditFixture($operation);
            $existing = Role::define(
                RoleId::generate(),
                RoleName::fromString($operation === 'create' ? 'ROLE_SUPPORT' : 'ROLE_HELP_DESK'),
                [],
                $fixture->outcomeTime
            );
            $fixture->roleRepository->add($existing);
            self::assertInstanceOf(StateConflict::class, $this->failure(
                fn() => $fixture->bus->execute($fixture->command)
            ));
            self::assertSame($existing, $fixture->roleRepository->getById($existing->getId()));
            self::assertSame([], $fixture->audit->all());
        }

        foreach (['rename', 'remove'] as $operation) {
            $fixture = new RoleLifecycleAuditFixture($operation);
            $role = $fixture->original;
            $managed = Role::reconstitute(
                $role->getId(),
                $role->getName(),
                [],
                true,
                $role->getCreatedAt(),
                $role->getUpdatedAt(),
                $role->getRevision()
            );
            $fixture->state->roles = [$managed];
            self::assertInstanceOf(RuleViolation::class, $this->failure(
                fn() => $fixture->bus->execute($fixture->command)
            ));
            self::assertSame($managed, $fixture->roleRepository->getById($role->getId()));
            self::assertSame([], $fixture->audit->all());
        }
    }

    private function assertOutcome(RoleLifecycleAuditFixture $fixture, string $operation, bool $committed): void
    {
        self::assertCount($committed ? 1 : 0, $fixture->audit->all());
        self::assertSame($committed, $fixture->unitOfWork->transactionCompleted);
        $current = $fixture->roleRepository->getById($fixture->original->getId());
        if (!$committed) {
            self::assertSame($operation === 'create' ? null : $fixture->original, $current);

            return;
        }

        self::assertSame($operation !== 'remove', $current !== null);
        if ($current !== null) {
            self::assertSame(
                $operation === 'create' ? 'ROLE_SUPPORT' : 'ROLE_HELP_DESK',
                $current->getName()->toString()
            );
            self::assertEquals($current->getRevision(), $fixture->audit->all()[0]->getFacts()->getAfter());
        }
    }

    private function failure(callable $operation): Throwable
    {
        try {
            $operation();
        } catch (Throwable $throwable) {
            return $throwable;
        }

        self::fail('The operation unexpectedly succeeded.');
    }
}
