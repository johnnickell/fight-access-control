<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User\CommandHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\AssignRoleToUserHandler;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\RemoveRoleFromUserHandler;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditAction;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidenceRepository;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditUserRevisions;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditUserRoleOutcome;
use Fight\AccessControl\Domain\AccessControl\Role\RoleRepository;
use Fight\AccessControl\Domain\AccessControl\User\Command\AssignRoleToUser;
use Fight\AccessControl\Domain\AccessControl\User\Command\RemoveRoleFromUser;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserRepository;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Application\Error\Exception\ResourceNotFound;
use Fight\Common\Application\Error\Exception\StateConflict;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Fight\Common\Domain\Messaging\Event\Event;
use Fight\Test\AccessControl\Application\AccessControl\Audit\Repository\MappedAuditEvidenceRepository;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\Role\CommandHandler\MembershipContractFixture;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryUserRepository;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

#[CoversClass(AssignRoleToUserHandler::class)]
#[CoversClass(RemoveRoleFromUserHandler::class)]
final class UserRoleAuditTest extends TestCase
{
    /** @return iterable<string, array{bool}> */
    public static function operations(): iterable
    {
        yield 'assign' => [true];
        yield 'remove' => [false];
    }

    /** @return iterable<string, array{bool, UserState, bool}> */
    public static function memberships(): iterable
    {
        foreach ([true, false] as $assigning) {
            foreach (UserState::cases() as $state) {
                foreach ([false, true] as $superAdmin) {
                    yield ($assigning ? 'assign ' : 'remove ').$state->value.($superAdmin ? ' super' : ' ordinary')
                        => [$assigning, $state, $superAdmin];
                }
            }
        }
    }

    #[DataProvider('memberships')]
    public function test_enabled_evidence_records_actual_membership_and_not_authentication_or_role_mutation(
        bool $assigning,
        UserState $state,
        bool $superAdmin
    ): void {
        $fixture = new UserRoleAuditFixture($assigning, state: $state, superAdmin: $superAdmin);
        $audit = new MappedAuditEvidenceRepository($fixture->unitOfWork);
        $message = CommandMessage::create($fixture->command);
        $writer = $this->createMock(AuditEvidenceRepository::class);
        $writer->expects(self::once())->method('add')->willReturnCallback(
            function (AuditEvidence $record) use ($fixture, $audit): void {
                self::assertTrue($fixture->unitOfWork->transactionActive);
                $this->assertChanged($fixture);
                $audit->add($record);
            }
        );
        $fixture->unitOfWork->beforeCommit = static function () use ($fixture, $audit): void {
            self::assertCount(1, $audit->all());
            self::assertSame([], $fixture->eventDispatcher->events());
        };
        $dispatcher = new InMemoryEventDispatcher(static function () use ($fixture, $audit): void {
            self::assertTrue($fixture->unitOfWork->transactionCompleted);
            self::assertFalse($fixture->unitOfWork->transactionActive);
            self::assertCount(1, $audit->all());
        });

        $fixture->handler(auditEvidenceRepository: $writer, eventDispatcher: $dispatcher)->handle($message);

        $after = $this->assertChanged($fixture);
        self::assertSame(1, $fixture->unitOfWork->transactions);
        self::assertCount(1, $dispatcher->events());
        $record = $audit->all()[0];
        self::assertTrue($record->actorId()->equals($fixture->command->getActorId()));
        self::assertFalse($record->actorId()->equals($fixture->user->getId()));
        self::assertTrue($record->getSubject()->getId()->equals($fixture->user->getId()));
        self::assertSame(
            $assigning ? AuditAction::USER_ROLE_ASSIGNED : AuditAction::USER_ROLE_REMOVED,
            $record->action()
        );
        self::assertEquals(new DateTimeImmutable('2030-01-02T03:04:05.123456+00:00'), $record->getOccurredAt());
        self::assertNotEquals($message->timestamp(), $record->getOccurredAt());
        self::assertTrue($record->getCorrelation()->getMessageId()->equals($message->id()));
        self::assertNull($record->getCorrelation()->getOperationKey());
        $facts = $record->getFacts();
        self::assertInstanceOf(AuditUserRoleOutcome::class, $facts);
        self::assertSame([
            'before'                  => AuditUserRevisions::fromUser($fixture->user)->toArray(),
            'after'                   => AuditUserRevisions::fromUser($after)->toArray(),
            'role_id'                 => $fixture->role->getId()->toString(),
            'role_reference_revision' => $fixture->role->getRevision()->toString()
        ], $facts->toArray());
        self::assertSame($record->toArray(), AuditEvidence::fromArray($record->toArray())->toArray());
    }

    #[DataProvider('operations')]
    public function test_explicit_null_changes_membership_without_a_durability_promise(bool $assigning): void
    {
        $fixture = new UserRoleAuditFixture($assigning);
        $fixture->handler(auditEvidenceRepository: null)->handle(CommandMessage::create($fixture->command));
        $this->assertChanged($fixture);
        self::assertCount(1, $fixture->eventDispatcher->events());
    }

    #[DataProvider('operations')]
    public function test_retries_do_not_duplicate_evidence(bool $assigning): void
    {
        $fixture = new UserRoleAuditFixture($assigning);
        $audit = new MappedAuditEvidenceRepository($fixture->unitOfWork);
        $handler = $fixture->handler($audit);
        $message = CommandMessage::create($fixture->command);
        $handler->handle($message);
        $after = $fixture->userRepository->getById($fixture->user->getId());
        $record = $audit->all()[0]->toArray();
        $handler->handle($message);
        self::assertSame($after, $fixture->userRepository->getById($fixture->user->getId()));
        self::assertSame([$record], array_map(
            static fn(AuditEvidence $item): array => $item->toArray(),
            $audit->all()
        ));
        self::assertCount(1, $fixture->eventDispatcher->events());
    }

    #[DataProvider('operations')]
    public function test_new_transition_is_not_deduplicated_by_a_reused_message_id(bool $assigning): void
    {
        $fixture = new UserRoleAuditFixture($assigning);
        $audit = new MappedAuditEvidenceRepository($fixture->unitOfWork);
        $handler = $fixture->handler($audit);
        $message = CommandMessage::create($fixture->command);
        $handler->handle($message);
        $arguments = [
            $fixture->userRepository,
            $fixture->roleRepository,
            new FixedClock('2030-01-02T03:04:05.123456+00:00'),
            $fixture->unitOfWork,
            $fixture->eventDispatcher,
            $audit
        ];
        if ($assigning) {
            $opposite = new RemoveRoleFromUserHandler(...$arguments);
            $command = new RemoveRoleFromUser(
                $fixture->command->getActorId(),
                $fixture->user->getId(),
                $fixture->role->getId()
            );
        } else {
            $opposite = new AssignRoleToUserHandler(...$arguments);
            $command = new AssignRoleToUser(
                $fixture->command->getActorId(),
                $fixture->user->getId(),
                $fixture->role->getId()
            );
        }

        $opposite->handle(CommandMessage::create($command));
        $handler->handle($message);

        $records = $audit->all();
        self::assertCount(3, $records);
        self::assertTrue($records[0]->getCorrelation()->getMessageId()->equals(
            $records[2]->getCorrelation()->getMessageId()
        ));
        self::assertFalse($records[0]->getId()->equals($records[2]->getId()));
        self::assertSame(
            $fixture->user->getAuthorizationAssignmentRevision() + 3,
            $fixture->userRepository->getById($fixture->user->getId())->getAuthorizationAssignmentRevision()
        );
    }

    #[DataProvider('operations')]
    public function test_current_command_round_trip_and_common_routing_support_optional_storage(bool $assigning): void
    {
        $fixture = new MembershipContractFixture($assigning ? 'assign' : 'unassign');
        $audit = new MappedAuditEvidenceRepository($fixture->unitOfWork);
        $command = $fixture->command::fromArray($fixture->command->toArray());

        $fixture->bus($fixture->handler(auditEvidenceRepository: $audit))->execute($command);

        self::assertCount(1, $audit->all());
        self::assertTrue($audit->all()[0]->actorId()->equals($command->getActorId()));
        self::assertNotNull($audit->all()[0]->getCorrelation()->getMessageId());
        self::assertCount(1, $fixture->eventDispatcher->events());
    }

    #[DataProvider('operations')]
    public function test_no_change_does_not_invoke_a_configured_failing_writer(bool $assigning): void
    {
        $fixture = new UserRoleAuditFixture($assigning, noOp: true);
        $audit = new MappedAuditEvidenceRepository($fixture->unitOfWork, failBeforeSave: true);
        $fixture->handler($audit)->handle(CommandMessage::create($fixture->command));
        self::assertSame($fixture->user, $fixture->userRepository->getById($fixture->user->getId()));
        self::assertSame([], $audit->all());
        self::assertNull($audit->failure());
        self::assertSame([], $fixture->eventDispatcher->events());
    }

    #[DataProvider('operations')]
    public function test_audit_failure_rolls_back_and_preserves_primary_fault(bool $assigning): void
    {
        foreach ([false, true] as $afterSave) {
            $fixture = new UserRoleAuditFixture($assigning);
            $audit = new MappedAuditEvidenceRepository(
                $fixture->unitOfWork,
                failAfterSave: $afterSave,
                failBeforeSave: !$afterSave
            );
            $attempts = [];
            $dispatcher = new InMemoryEventDispatcher(static function (Event $event) use (&$attempts): void {
                $attempts[] = $event->toArray();

                throw new RuntimeException('Synthetic notifier fault.');
            });
            $failure = $this->failure(fn() => $fixture->handler($audit, $dispatcher)
                ->handle(CommandMessage::create($fixture->command)));
            self::assertSame($audit->failure(), $failure);
            self::assertSame($fixture->user, $fixture->userRepository->getById($fixture->user->getId()));
            self::assertSame([], $audit->all());
            self::assertFalse($fixture->unitOfWork->transactionCompleted);
            self::assertSame([new CommandFailedEvent(
                $fixture->command,
                $assigning ? 'User role assignment failed.' : 'User role removal failed.'
            )->toArray()], $attempts);
        }
    }

    #[DataProvider('operations')]
    public function test_commit_failure_rolls_back_staged_membership_and_evidence(bool $assigning): void
    {
        $fixture = new UserRoleAuditFixture($assigning);
        $audit = new MappedAuditEvidenceRepository($fixture->unitOfWork);
        $fixture->unitOfWork->failNextCommit = true;
        $fixture->unitOfWork->beforeCommit = static function () use ($audit): void {
            self::assertCount(1, $audit->all());
        };
        $this->failure(fn() => $fixture->handler($audit)->handle(CommandMessage::create($fixture->command)));
        self::assertSame($fixture->user, $fixture->userRepository->getById($fixture->user->getId()));
        self::assertSame([], $audit->all());
        self::assertInstanceOf(CommandFailedEvent::class, $fixture->eventDispatcher->events()[0]);
    }

    #[DataProvider('operations')]
    public function test_committed_evidence_survives_both_publication_failures(bool $assigning): void
    {
        $fixture = new UserRoleAuditFixture($assigning);
        $audit = new MappedAuditEvidenceRepository($fixture->unitOfWork);
        $primary = new RuntimeException('Synthetic subscriber fault.');
        $attempts = [];
        $dispatcher = new InMemoryEventDispatcher(static function (Event $event) use (
            $fixture,
            $audit,
            $primary,
            &$attempts
        ): void {
            self::assertTrue($fixture->unitOfWork->transactionCompleted);
            self::assertCount(1, $audit->all());
            $attempts[] = $event;

            throw $event instanceof CommandFailedEvent ? new RuntimeException('Synthetic notifier fault.') : $primary;
        });
        self::assertSame($primary, $this->failure(fn() => $fixture->handler($audit, $dispatcher)
            ->handle(CommandMessage::create($fixture->command))));
        $this->assertChanged($fixture);
        self::assertCount(1, $audit->all());
        self::assertCount(2, $attempts);
        self::assertInstanceOf(CommandFailedEvent::class, $attempts[1]);
    }

    #[DataProvider('operations')]
    public function test_lost_commit_acknowledgement_preserves_both_possible_outcomes(bool $assigning): void
    {
        foreach ([false, true] as $committed) {
            $fixture = new UserRoleAuditFixture($assigning);
            $audit = new MappedAuditEvidenceRepository($fixture->unitOfWork);
            $uncertain = new RuntimeException('Synthetic lost acknowledgement.');
            $loseAcknowledgement = static function () use ($uncertain, $audit): void {
                self::assertCount(1, $audit->all());

                throw $uncertain;
            };
            if ($committed) {
                $fixture->unitOfWork->afterCommit = $loseAcknowledgement;
            } else {
                $fixture->unitOfWork->beforeCommit = $loseAcknowledgement;
            }

            self::assertSame($uncertain, $this->failure(fn() => $fixture->handler($audit)
                ->handle(CommandMessage::create($fixture->command))));
            if ($committed) {
                $this->assertChanged($fixture);
            } else {
                self::assertSame($fixture->user, $fixture->userRepository->getById($fixture->user->getId()));
            }

            self::assertCount($committed ? 1 : 0, $audit->all());
            self::assertSame($committed, $fixture->unitOfWork->transactionCompleted);
            self::assertCount(1, $fixture->eventDispatcher->events());
            self::assertInstanceOf(CommandFailedEvent::class, $fixture->eventDispatcher->events()[0]);
        }
    }

    #[DataProvider('operations')]
    public function test_reference_and_cas_loss_reject_without_evidence_including_no_change(bool $assigning): void
    {
        foreach (['no-op reference', 'changed reference', 'cas'] as $loss) {
            $fixture = new UserRoleAuditFixture($assigning, noOp: $loss === 'no-op reference');
            $audit = new MappedAuditEvidenceRepository($fixture->unitOfWork);
            $invalidate = static function () use ($fixture): void {
                $fixture->unitOfWork->authorizationReferenceState()->removeRole($fixture->role->getId());
            };
            $repository = new InMemoryUserRepository(
                $fixture->unitOfWork,
                replaceRoleAssignmentsSucceeds: $loss !== 'cas',
                beforeReplaceRoleAssignments: $loss === 'changed reference' ? $invalidate : null,
                beforeValidateRoleAssignmentReference: $loss === 'no-op reference' ? $invalidate : null
            );
            $repository->add($fixture->user);
            $failure = $this->failure(fn() => $fixture->handler($audit, userRepository: $repository)
                ->handle(CommandMessage::create($fixture->command)));
            self::assertInstanceOf(StateConflict::class, $failure);
            self::assertSame($fixture->user, $repository->getById($fixture->user->getId()));
            self::assertSame([], $audit->all());
            self::assertCount(1, $fixture->eventDispatcher->events());
            self::assertInstanceOf(CommandFailedEvent::class, $fixture->eventDispatcher->events()[0]);
        }
    }

    #[DataProvider('operations')]
    public function test_complete_state_cas_preserves_a_competing_winner_without_loser_evidence(bool $assigning): void
    {
        $fixture = new UserRoleAuditFixture($assigning);
        $audit = new MappedAuditEvidenceRepository($fixture->unitOfWork);
        $winner = clone $fixture->user;
        $winner->removeRole($fixture->otherRole->getId(), new DateTimeImmutable('2030-01-02T00:00:00Z'));

        $repository = new InMemoryUserRepository(
            $fixture->unitOfWork,
            state: $fixture->userState,
            beforeReplaceRoleAssignments: static function () use ($fixture, $winner): void {
                // Model an already committed competing state, not this transaction's staged write.
                $fixture->userState->users = [$winner];
            }
        );

        $failure = $this->failure(fn() => $fixture->handler($audit, userRepository: $repository)
            ->handle(CommandMessage::create($fixture->command)));

        self::assertInstanceOf(StateConflict::class, $failure);
        self::assertSame($winner, $fixture->userRepository->getById($fixture->user->getId()));
        self::assertSame([], $audit->all());
        self::assertCount(1, $fixture->eventDispatcher->events());
        self::assertInstanceOf(CommandFailedEvent::class, $fixture->eventDispatcher->events()[0]);
    }

    #[DataProvider('operations')]
    public function test_enabled_audit_does_not_admit_missing_or_unrelated_identities(bool $assigning): void
    {
        foreach (['missing user', 'missing role', 'wrong user', 'wrong role'] as $loss) {
            $fixture = new MembershipContractFixture($assigning ? 'assign' : 'unassign');
            $other = new MembershipContractFixture($assigning ? 'assign' : 'unassign');
            $audit = new MappedAuditEvidenceRepository($fixture->unitOfWork);
            $users = $fixture->userRepository;
            $roles = $fixture->roleRepository;
            if (str_ends_with($loss, 'user')) {
                $users = $this->createStub(UserRepository::class);
                $users->method('getById')->willReturn($loss === 'missing user' ? null : $other->user);
            } else {
                $roles = $this->createStub(RoleRepository::class);
                $roles->method('getById')->willReturn($loss === 'missing role' ? null : $other->role);
            }

            $failure = $this->failure(fn() => $fixture->handler(
                roleRepository: $roles,
                userRepository: $users,
                auditEvidenceRepository: $audit
            )->handle(CommandMessage::create($fixture->command)));

            self::assertInstanceOf(
                str_starts_with($loss, 'missing') ? ResourceNotFound::class : LogicException::class,
                $failure
            );
            self::assertSame($fixture->user, $fixture->userRepository->getById($fixture->user->getId()));
            self::assertSame([], $audit->all());
            self::assertCount(1, $fixture->eventDispatcher->events());
            self::assertInstanceOf(CommandFailedEvent::class, $fixture->eventDispatcher->events()[0]);
        }
    }

    private function assertChanged(UserRoleAuditFixture $fixture): User
    {
        $after = $fixture->userRepository->getById($fixture->user->getId());
        self::assertInstanceOf(User::class, $after);
        self::assertSame($fixture->assigning, $after->hasRole($fixture->role->getId()));
        self::assertTrue($after->hasRole($fixture->otherRole->getId()));
        self::assertSame(
            $fixture->user->getAuthorizationAssignmentRevision() + 1,
            $after->getAuthorizationAssignmentRevision()
        );
        $beforeCounters = AuditUserRevisions::fromUser($fixture->user)->toArray();
        $afterCounters = AuditUserRevisions::fromUser($after)->toArray();
        unset($beforeCounters['authorization_assignment'], $afterCounters['authorization_assignment']);
        self::assertSame($beforeCounters, $afterCounters);
        self::assertSame($fixture->role, $fixture->roleRepository->getById($fixture->role->getId()));

        return $after;
    }

    private function failure(callable $operation): Throwable
    {
        try {
            $operation();
        } catch (Throwable $throwable) {
            return $throwable;
        }

        self::fail('The operation must fail.');
    }
}
