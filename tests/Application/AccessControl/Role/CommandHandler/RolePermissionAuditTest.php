<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Role\CommandHandler;

use Closure;
use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\Role\CommandHandler\GrantPermissionToCustomRoleHandler;
use Fight\AccessControl\Application\AccessControl\Role\CommandHandler\RevokePermissionFromCustomRoleHandler;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditAction;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidenceRepository;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditRolePermissionOutcome;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionRepository;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionTier;
use Fight\AccessControl\Domain\AccessControl\Role\Command\RevokePermissionFromCustomRole;
use Fight\AccessControl\Domain\AccessControl\Role\Role;
use Fight\AccessControl\Domain\AccessControl\Role\RoleName;
use Fight\AccessControl\Domain\AccessControl\Role\RoleRevision;
use Fight\Common\Application\Error\Exception\StateConflict;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Fight\Common\Domain\Messaging\Event\Event;
use Fight\Test\AccessControl\Application\AccessControl\Audit\Repository\MappedAuditEvidenceRepository;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\Permission\Repository\InMemoryPermissionRepository;
use Fight\Test\AccessControl\Application\AccessControl\Role\Repository\InMemoryRoleRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

#[CoversClass(GrantPermissionToCustomRoleHandler::class)]
#[CoversClass(RevokePermissionFromCustomRoleHandler::class)]
final class RolePermissionAuditTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function operations(): iterable
    {
        yield 'grant' => ['grant'];
        yield 'revoke' => ['revoke'];
    }

    #[DataProvider('operations')]
    public function test_enabled_evidence_joins_the_persisted_outcome_before_commit(string $operation): void
    {
        $fixture = new MembershipContractFixture($operation);
        $audit = new MappedAuditEvidenceRepository($fixture->unitOfWork);
        $message = CommandMessage::create($fixture->command);
        $auditRepository = $this->createMock(AuditEvidenceRepository::class);
        $auditRepository->expects(self::once())->method('add')->willReturnCallback(
            function (AuditEvidence $record) use ($fixture, $audit): void {
                self::assertTrue($fixture->unitOfWork->transactionActive);
                $this->assertChanged($fixture);
                $audit->add($record);
            }
        );
        $observedBeforeCommit = false;
        $fixture->unitOfWork->beforeCommit = function () use ($fixture, $audit, &$observedBeforeCommit): void {
            self::assertTrue($fixture->unitOfWork->transactionActive);
            self::assertFalse($fixture->unitOfWork->transactionCompleted);
            $this->assertChanged($fixture);
            self::assertCount(1, $audit->all());
            self::assertSame([], $fixture->eventDispatcher->events());
            $observedBeforeCommit = true;
        };
        $dispatcher = new InMemoryEventDispatcher(function () use ($fixture, $audit): void {
            self::assertFalse($fixture->unitOfWork->transactionActive);
            self::assertTrue($fixture->unitOfWork->transactionCompleted);
            $this->assertChanged($fixture);
            self::assertCount(1, $audit->all());
        });

        $fixture->handler(eventDispatcher: $dispatcher, auditEvidenceRepository: $auditRepository)->handle($message);

        self::assertTrue($observedBeforeCommit);
        self::assertSame(1, $fixture->unitOfWork->transactions);
        self::assertCount(1, $dispatcher->events());
        $record = $audit->all()[0];
        $after = $fixture->roleRepository->getById($fixture->role->getId());
        self::assertInstanceOf(Role::class, $after);
        self::assertTrue($record->actorId()->equals($fixture->command->getActorId()));
        self::assertTrue($record->getSubject()->getId()->equals($fixture->role->getId()));
        self::assertSame(
            $operation === 'grant' ? AuditAction::ROLE_PERMISSION_GRANTED : AuditAction::ROLE_PERMISSION_REVOKED,
            $record->action()
        );
        self::assertEquals(new DateTimeImmutable('2030-01-02T00:00:00Z'), $record->getOccurredAt());
        self::assertNotEquals($message->timestamp(), $record->getOccurredAt());
        self::assertTrue($message->id()->equals($record->getCorrelation()->getMessageId()));
        self::assertNull($record->getCorrelation()->getOperationKey());
        $facts = $record->getFacts();
        self::assertInstanceOf(AuditRolePermissionOutcome::class, $facts);
        self::assertTrue($facts->getPermissionId()->equals($fixture->permission->getId()));
        self::assertSame([
            'revisions'     => [
                'before' => $fixture->role->getRevision()->toString(),
                'after'  => $after->getRevision()->toString()
            ],
            'permission_id' => $fixture->permission->getId()->toString()
        ], $facts->toArray());
        self::assertSame($record->toArray(), AuditEvidence::fromArray($record->toArray())->toArray());
        self::assertSame($fixture->permission, $fixture->permissionRepository->getById($fixture->permission->getId()));
    }

    #[DataProvider('operations')]
    public function test_disabled_composition_changes_state_without_a_durability_claim(string $operation): void
    {
        $fixture = new MembershipContractFixture($operation);

        $fixture->bus($fixture->handler(auditEvidenceRepository: null))->execute($fixture->command);

        $this->assertChanged($fixture);
        self::assertTrue($fixture->unitOfWork->transactionCompleted);
        self::assertCount(1, $fixture->eventDispatcher->events());
    }

    #[DataProvider('operations')]
    public function test_repeated_desired_state_does_not_duplicate_evidence(string $operation): void
    {
        $fixture = new MembershipContractFixture($operation);
        $audit = new MappedAuditEvidenceRepository($fixture->unitOfWork);
        $handler = $fixture->handler(auditEvidenceRepository: $audit);
        $message = CommandMessage::create($fixture->command);
        $handler->handle($message);
        $after = $fixture->roleRepository->getById($fixture->role->getId());
        $record = $audit->all()[0]->toArray();

        $handler->handle($message);

        self::assertSame($after, $fixture->roleRepository->getById($fixture->role->getId()));
        self::assertSame([$record], array_map(
            static fn(AuditEvidence $item): array => $item->toArray(),
            $audit->all()
        ));
        self::assertCount(1, $fixture->eventDispatcher->events());
    }

    #[DataProvider('operations')]
    public function test_validated_no_change_does_not_touch_even_a_failing_audit_adapter(string $operation): void
    {
        $fixture = new MembershipContractFixture($operation, true);
        $audit = new MappedAuditEvidenceRepository($fixture->unitOfWork, failBeforeSave: true);
        $fixture->handler(auditEvidenceRepository: $audit)->handle(CommandMessage::create($fixture->command));

        self::assertSame($fixture->role, $fixture->roleRepository->getById($fixture->role->getId()));
        self::assertSame([], $audit->all());
        self::assertNull($audit->failure());
        self::assertSame([], $fixture->eventDispatcher->events());
    }

    /** @return iterable<string, array{string, bool}> */
    public static function auditFailures(): iterable
    {
        foreach (['grant', 'revoke'] as $operation) {
            foreach ([false, true] as $afterSave) {
                yield $operation.($afterSave ? ' after insertion' : ' before insertion') => [$operation, $afterSave];
            }
        }
    }

    #[DataProvider('auditFailures')]
    public function test_audit_failure_rolls_back_membership_revision_and_evidence(
        string $operation,
        bool $afterSave
    ): void {
        $fixture = new MembershipContractFixture($operation);
        $audit = new MappedAuditEvidenceRepository(
            $fixture->unitOfWork,
            failAfterSave: $afterSave,
            failBeforeSave: !$afterSave
        );
        $secondary = new RuntimeException('Synthetic notifier diagnostic.');
        $attempts = [];
        $dispatcher = new InMemoryEventDispatcher(static function (Event $event) use (&$attempts, $secondary): void {
            $attempts[] = $event->toArray();

            throw $secondary;
        });

        $failure = $this->failure(fn() => $fixture->handler(
            eventDispatcher: $dispatcher,
            auditEvidenceRepository: $audit
        )->handle(CommandMessage::create($fixture->command)));

        self::assertSame($audit->failure(), $failure);
        self::assertSame($fixture->role, $fixture->roleRepository->getById($fixture->role->getId()));
        self::assertSame([], $audit->all());
        self::assertFalse($fixture->unitOfWork->transactionCompleted);
        self::assertCount(1, $attempts);
        $expected = new CommandFailedEvent($fixture->command, match ($operation) {
            'grant' => 'Custom role permission grant failed.',
            'revoke' => 'Custom role permission revocation failed.'
        });
        self::assertSame($expected->toArray(), $attempts[0]);
    }

    #[DataProvider('operations')]
    public function test_commit_failure_removes_already_staged_audit_and_role(string $operation): void
    {
        $fixture = new MembershipContractFixture($operation);
        $audit = new MappedAuditEvidenceRepository($fixture->unitOfWork);
        $fixture->unitOfWork->failNextCommit = true;
        $fixture->unitOfWork->beforeCommit = static function () use ($audit): void {
            self::assertCount(1, $audit->all());
        };

        $this->failure(fn() => $fixture->handler(auditEvidenceRepository: $audit)
            ->handle(CommandMessage::create($fixture->command)));

        self::assertSame($fixture->role, $fixture->roleRepository->getById($fixture->role->getId()));
        self::assertSame([], $audit->all());
        $this->assertRejected($fixture);
    }

    #[DataProvider('operations')]
    public function test_post_commit_double_publication_failure_preserves_state_and_evidence(string $operation): void
    {
        $fixture = new MembershipContractFixture($operation);
        $audit = new MappedAuditEvidenceRepository($fixture->unitOfWork);
        $primary = new RuntimeException('Synthetic subscriber diagnostic.');
        $secondary = new RuntimeException('Synthetic notifier diagnostic.');
        $attempts = [];
        $dispatcher = new InMemoryEventDispatcher(static function (Event $event) use (
            $fixture,
            $audit,
            $primary,
            $secondary,
            &$attempts
        ): void {
            self::assertTrue($fixture->unitOfWork->transactionCompleted);
            self::assertCount(1, $audit->all());
            $attempts[] = $event;

            throw $event instanceof CommandFailedEvent ? $secondary : $primary;
        });

        $failure = $this->failure(fn() => $fixture->handler(
            eventDispatcher: $dispatcher,
            auditEvidenceRepository: $audit
        )->handle(CommandMessage::create($fixture->command)));

        self::assertSame($primary, $failure);
        $this->assertChanged($fixture);
        self::assertCount(1, $audit->all());
        self::assertCount(2, $attempts);
        self::assertInstanceOf(CommandFailedEvent::class, $attempts[1]);
    }

    #[DataProvider('operations')]
    public function test_lost_commit_acknowledgement_does_not_claim_rollback_or_publish_success(string $operation): void
    {
        foreach ([false, true] as $committed) {
            $fixture = new MembershipContractFixture($operation);
            $audit = new MappedAuditEvidenceRepository($fixture->unitOfWork);
            $uncertain = new RuntimeException('Synthetic lost commit acknowledgement.');
            $loseAcknowledgement = static function () use ($uncertain, $audit): void {
                self::assertCount(1, $audit->all());

                throw $uncertain;
            };
            if ($committed) {
                $fixture->unitOfWork->afterCommit = $loseAcknowledgement;
            } else {
                $fixture->unitOfWork->beforeCommit = $loseAcknowledgement;
            }

            $failure = $this->failure(fn() => $fixture->handler(auditEvidenceRepository: $audit)
                ->handle(CommandMessage::create($fixture->command)));

            self::assertSame($uncertain, $failure);
            if ($committed) {
                $this->assertChanged($fixture);
            } else {
                self::assertSame($fixture->role, $fixture->roleRepository->getById($fixture->role->getId()));
            }

            self::assertCount($committed ? 1 : 0, $audit->all());
            self::assertSame($committed, $fixture->unitOfWork->transactionCompleted);
            self::assertCount(1, $fixture->eventDispatcher->events());
            self::assertInstanceOf(CommandFailedEvent::class, $fixture->eventDispatcher->events()[0]);
        }
    }

    #[DataProvider('operations')]
    public function test_authoritative_cas_loser_does_not_record_a_proposed_successor(string $operation): void
    {
        $fixture = new MembershipContractFixture($operation);
        $audit = new MappedAuditEvidenceRepository($fixture->unitOfWork);
        $winner = $fixture->role->renameCustom(RoleName::fromString('ROLE_WINNER'), $fixture->role->getUpdatedAt());
        $repository = new InMemoryRoleRepository(
            $fixture->unitOfWork,
            beforeReplace: static function () use ($fixture, $winner): void {
                // A completed competing write, not this transaction's staged state.
                $fixture->roleState->roles = [$winner];
                $fixture->unitOfWork->authorizationReferenceState()->addRole($winner);
            },
            state: $fixture->roleState
        );

        $failure = $this->failure(fn() => $fixture->handler(
            roleRepository: $repository,
            auditEvidenceRepository: $audit
        )->handle(CommandMessage::create($fixture->command)));

        self::assertInstanceOf(StateConflict::class, $failure);
        self::assertSame($winner, $fixture->roleRepository->getById($winner->getId()));
        self::assertSame([], $audit->all());
        $this->assertRejected($fixture);
    }

    /** @return iterable<string, array{string, bool, string}> */
    public static function admissionLosses(): iterable
    {
        foreach (['grant', 'revoke'] as $operation) {
            foreach ([false, true] as $noOp) {
                foreach (['reference', 'identity', 'managed', 'reserved', 'missing'] as $loss) {
                    yield $operation.($noOp ? ' no-op ' : ' change ').$loss => [$operation, $noOp, $loss];
                }
            }
        }
    }

    #[DataProvider('admissionLosses')]
    public function test_enabled_audit_does_not_bypass_existing_admission(
        string $operation,
        bool $noOp,
        string $loss
    ): void {
        $fixture = new MembershipContractFixture($operation, $noOp);
        $audit = new MappedAuditEvidenceRepository($fixture->unitOfWork);
        $permissions = $fixture->permissionRepository;
        $roles = $fixture->roleRepository;
        if ($loss === 'identity' || $loss === 'missing') {
            $permissions = $this->createStub(PermissionRepository::class);
            $permissions->method('getById')->willReturn(
                $loss === 'missing' ? null : (new MembershipContractFixture($operation))->permission
            );
        } elseif ($loss === 'managed' || $loss === 'reserved') {
            $role = Role::defineManaged(
                $fixture->role->getId(),
                RoleName::fromString($loss === 'reserved' ? 'ROLE_SUPER_ADMIN' : 'ROLE_MANAGED'),
                $fixture->role->getPermissionIds(),
                $fixture->role->getCreatedAt()
            );
            $fixture->roleState->roles = [$role];
        } else {
            $referenceId = $fixture->permission->getId();
            if ($operation === 'revoke' && !$noOp) {
                $retained = (new MembershipContractFixture('grant'))->permission;
                $fixture->permissionRepository->add($retained);
                $referenceId = $retained->getId();
                $fixture->roleState->roles = [Role::reconstitute(
                    $fixture->role->getId(),
                    $fixture->role->getName(),
                    [$fixture->permission->getId(), $referenceId],
                    false,
                    $fixture->role->getCreatedAt(),
                    $fixture->role->getUpdatedAt(),
                    $fixture->role->getRevision()
                )];
            }

            $roles = new InMemoryRoleRepository(
                $fixture->unitOfWork,
                beforeReplace: static function () use ($fixture, $referenceId): void {
                    $fixture->unitOfWork->authorizationReferenceState()->removePermission($referenceId);
                },
                beforeValidatePermissionReference: $noOp ? static function () use ($fixture, $referenceId): void {
                    $fixture->unitOfWork->authorizationReferenceState()->removePermission($referenceId);
                } : null,
                state: $fixture->roleState
            );
        }

        $before = $fixture->roleRepository->getById($fixture->role->getId());

        $this->failure(fn() => $fixture->handler(
            roleRepository: $roles,
            permissionRepository: $permissions,
            auditEvidenceRepository: $audit
        )->handle(CommandMessage::create($fixture->command)));

        self::assertSame($before, $fixture->roleRepository->getById($fixture->role->getId()));
        self::assertSame([], $audit->all());
        $this->assertRejected($fixture);
    }

    public function test_revocation_of_existing_protected_membership_is_still_allowed(): void
    {
        $fixture = new MembershipContractFixture('revoke');
        $protected = Permission::defineManaged(
            $fixture->permission->getId(),
            $fixture->permission->getName(),
            PermissionTier::SUPER_ADMIN_ONLY,
            $fixture->permission->getCreatedAt()
        );
        $permissions = new InMemoryPermissionRepository($fixture->unitOfWork);
        $permissions->add($protected);

        $audit = new MappedAuditEvidenceRepository($fixture->unitOfWork);

        $fixture->handler(permissionRepository: $permissions, auditEvidenceRepository: $audit)
            ->handle(CommandMessage::create($fixture->command));

        $this->assertChanged($fixture);
        self::assertCount(1, $audit->all());
        self::assertSame($protected, $permissions->getById($protected->getId()));
    }

    public function test_each_new_relationship_outcome_has_its_own_identity_even_with_one_message(): void
    {
        $fixture = new MembershipContractFixture('grant');
        $audit = new MappedAuditEvidenceRepository($fixture->unitOfWork);
        $grant = CommandMessage::create($fixture->command);
        $fixture->handler(auditEvidenceRepository: $audit)->handle($grant);
        $revoke = new MembershipContractFixture('revoke');
        $revokeHandler = $revoke->handler(
            roleRepository: $fixture->roleRepository,
            permissionRepository: $fixture->permissionRepository,
            unitOfWork: $fixture->unitOfWork,
            auditEvidenceRepository: $audit
        );
        $command = new RevokePermissionFromCustomRole(
            $fixture->command->getActorId(),
            $fixture->role->getId(),
            $fixture->permission->getId()
        );
        $revokeHandler->handle(CommandMessage::create($command));
        $fixture->handler(auditEvidenceRepository: $audit)->handle($grant);

        self::assertCount(3, $audit->all());
        self::assertCount(3, array_unique(array_map(
            static fn(AuditEvidence $record): string => $record->getId()->toString(),
            $audit->all()
        )));
        self::assertTrue($audit->all()[0]->getCorrelation()->getMessageId()->equals(
            $audit->all()[2]->getCorrelation()->getMessageId()
        ));
        $this->assertChanged($fixture);
    }

    #[DataProvider('operations')]
    public function test_no_change_still_fences_the_complete_role_before_acceptance(string $operation): void
    {
        $fixture = new MembershipContractFixture($operation, true);
        $audit = new MappedAuditEvidenceRepository($fixture->unitOfWork);
        $winner = $fixture->role->renameCustom(RoleName::fromString('ROLE_WINNER'), $fixture->role->getUpdatedAt());
        $roles = new InMemoryRoleRepository(
            $fixture->unitOfWork,
            state: $fixture->roleState,
            beforeValidateCurrent: static function () use ($fixture, $winner): void {
                $fixture->roleState->roles = [$winner];
            }
        );

        $failure = $this->failure(fn() => $fixture->handler(
            roleRepository: $roles,
            auditEvidenceRepository: $audit
        )->handle(CommandMessage::create($fixture->command)));

        self::assertInstanceOf(StateConflict::class, $failure);
        self::assertSame($winner, $fixture->roleRepository->getById($winner->getId()));
        self::assertSame([], $audit->all());
        $this->assertRejected($fixture);
    }

    public function test_enabled_grant_cannot_bypass_authoritative_tier_validation_or_final_fence(): void
    {
        foreach (['protected', 'stale snapshot', 'final replacement', 'no-op'] as $phase) {
            $noOp = $phase === 'no-op';
            $fixture = new MembershipContractFixture('grant', $noOp);
            $audit = new MappedAuditEvidenceRepository($fixture->unitOfWork);
            $protected = Permission::defineManaged(
                $fixture->permission->getId(),
                $fixture->permission->getName(),
                PermissionTier::SUPER_ADMIN_ONLY,
                $fixture->permission->getCreatedAt()
            );
            $promote = static function () use ($fixture, $protected): void {
                $fixture->unitOfWork->authorizationReferenceState()->addPermission($protected);
            };
            $permissions = $fixture->permissionRepository;
            if ($phase === 'protected') {
                $permissions = new InMemoryPermissionRepository($fixture->unitOfWork);
                $permissions->add($protected);
            }

            $roles = new InMemoryRoleRepository(
                $fixture->unitOfWork,
                beforeReplace: $phase === 'final replacement' ? $promote : null,
                beforeValidatePermissionReference: $phase === 'stale snapshot' || $noOp ? $promote : null,
                state: $fixture->roleState
            );

            $this->failure(fn() => $fixture->handler(
                roleRepository: $roles,
                permissionRepository: $permissions,
                auditEvidenceRepository: $audit
            )->handle(CommandMessage::create($fixture->command)));

            self::assertSame($fixture->role, $fixture->roleRepository->getById($fixture->role->getId()));
            self::assertSame([], $audit->all());
            $this->assertRejected($fixture);
        }
    }

    #[DataProvider('operations')]
    public function test_existing_incarnation_and_noninitial_revisions_are_not_reconstructed(string $operation): void
    {
        foreach ([99, RoleRevision::MAX_SEQUENCE - 1, RoleRevision::MAX_SEQUENCE] as $sequence) {
            $fixture = new MembershipContractFixture($operation);
            $audit = new MappedAuditEvidenceRepository($fixture->unitOfWork);
            $revision = RoleRevision::fromString('11111111-1111-4111-8111-111111111111:'.$sequence);
            $role = Role::reconstitute(
                $fixture->role->getId(),
                $fixture->role->getName(),
                $fixture->role->getPermissionIds(),
                false,
                $fixture->role->getCreatedAt(),
                $fixture->role->getUpdatedAt(),
                $revision
            );
            $fixture->roleState->roles = [$role];
            $handler = $fixture->handler(auditEvidenceRepository: $audit);
            if ($sequence === RoleRevision::MAX_SEQUENCE) {
                $this->failure(fn() => $handler->handle(CommandMessage::create($fixture->command)));
                self::assertSame($role, $fixture->roleRepository->getById($role->getId()));
                self::assertSame([], $audit->all());
                $this->assertRejected($fixture);

                continue;
            }

            $handler->handle(CommandMessage::create($fixture->command));

            self::assertSame([
                'before' => $revision->toString(),
                'after'  => '11111111-1111-4111-8111-111111111111:'.($sequence + 1)
            ], $audit->all()[0]->getFacts()->getRevisions()->toArray());
        }
    }

    private function assertChanged(MembershipContractFixture $fixture): void
    {
        $role = $fixture->roleRepository->getById($fixture->role->getId());
        self::assertInstanceOf(Role::class, $role);
        self::assertSame($fixture->operation === 'grant', $role->hasPermission($fixture->permission->getId()));
        self::assertFalse($fixture->role->getRevision()->equals($role->getRevision()));
    }

    private function assertRejected(MembershipContractFixture $fixture): void
    {
        self::assertFalse($fixture->unitOfWork->transactionCompleted);
        self::assertCount(1, $fixture->eventDispatcher->events());
        self::assertInstanceOf(CommandFailedEvent::class, $fixture->eventDispatcher->events()[0]);
    }

    private function failure(Closure $operation): Throwable
    {
        try {
            $operation();
        } catch (Throwable $throwable) {
            return $throwable;
        }

        self::fail('The operation must fail.');
    }
}
