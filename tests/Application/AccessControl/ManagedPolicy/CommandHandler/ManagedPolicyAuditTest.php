<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\ManagedPolicy\CommandHandler;

use Fight\AccessControl\Application\AccessControl\ManagedPolicy\CommandHandler\ReconcileManagedPolicyHandler;
use Fight\AccessControl\Application\AccessControl\ManagedPolicy\QueryHandler\PreviewManagedPolicyHandler;
use Fight\AccessControl\Application\AccessControl\ManagedPolicy\Service\ManagedPolicyPlanner;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditAction;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidenceRepository;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditManagedRoleOutcome;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditPermissionOutcome;
use Fight\AccessControl\Domain\AccessControl\Audit\SystemId;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\Command\ReconcileManagedPolicy;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\Event\ManagedPolicyReconciled;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\ManagedPermissionDefinition;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\ManagedPolicy;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\ManagedRoleDefinition;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\Query\PreviewManagedPolicy;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionRepository;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionTier;
use Fight\AccessControl\Domain\AccessControl\Role\Role;
use Fight\AccessControl\Domain\AccessControl\Role\RoleName;
use Fight\AccessControl\Domain\AccessControl\Role\RoleRepository;
use Fight\Common\Application\Error\Exception\InvalidInput;
use Fight\Common\Application\Error\Exception\StateConflict;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Fight\Common\Domain\Messaging\Event\Event;
use Fight\Common\Domain\Messaging\Query\QueryMessage;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Repository\InMemoryAgentRepository;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\Permission\Repository\InMemoryPermissionRepository;
use Fight\Test\AccessControl\Application\AccessControl\Role\Repository\InMemoryRoleRepository;
use Fight\Test\AccessControl\Application\AccessControl\Role\Repository\InMemoryRoleRepositoryState;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryUserRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

#[CoversClass(ReconcileManagedPolicyHandler::class)]
#[CoversClass(AuditEvidence::class)]
#[CoversClass(AuditManagedRoleOutcome::class)]
#[CoversClass(AuditPermissionOutcome::class)]
#[CoversClass(ReconcileManagedPolicy::class)]
final class ManagedPolicyAuditTest extends TestCase
{
    public function test_mixed_batch_records_only_six_authoritative_changed_subjects(): void
    {
        $f = new ManagedPolicyAuditFixture();
        $plan = new PreviewManagedPolicyHandler($f->planner)->handle(
            QueryMessage::create(new PreviewManagedPolicy($f->policy))
        );
        self::assertSame(0, $f->unit->transactions);
        self::assertSame([], $f->audit->all());
        $actor = SystemId::generate();
        $message = CommandMessage::create(new ReconcileManagedPolicy($f->policy, $actor));
        $f->handler($f->audit)->handle($message);

        $records = $f->audit->all();
        self::assertCount(6, $records);
        self::assertCount(6, array_unique(array_map(
            static fn(AuditEvidence $e): string => $e->getId()->toString(),
            $records
        )));
        $byAction = [];
        foreach ($records as $record) {
            self::assertSame($actor, $record->actorId());
            self::assertSame($f->now, $record->getOccurredAt());
            self::assertSame($message->id()->toString(), $record->getCorrelation()->toArray()['message_id']);
            self::assertSame($record->toArray(), AuditEvidence::fromArray($record->toArray())->toArray());
            $byAction[$record->action()->value] = $record;
        }

        self::assertCount(6, $byAction);
        $role = $f->roles->getById($f->createdRoleId);
        self::assertInstanceOf(Role::class, $role);
        self::assertSame([
            'revisions'             => ['before' => null, 'after' => $role->getRevision()->toString()],
            'before_permission_ids' => null,
            'after_permission_ids'  => [$f->createdPermissionId->toString()]
        ], $byAction[AuditAction::MANAGED_ROLE_CREATED->value]->getFacts()->toArray());
        $changed = $f->roles->getById($f->beforeRoles[1]->getId());
        self::assertInstanceOf(Role::class, $changed);
        self::assertSame($f->beforeRoles[1]->getRevision()->next()->toString(), $changed->getRevision()->toString());
        self::assertSame([
            'revisions'             => [
                'before' => $f->beforeRoles[1]->getRevision()->toString(),
                'after'  => $changed->getRevision()->toString()
            ],
            'before_permission_ids' => [$f->beforePermissions[0]->getId()->toString()],
            'after_permission_ids'  => [$f->createdPermissionId->toString()]
        ], $byAction[AuditAction::MANAGED_ROLE_RECONCILED->value]->getFacts()->toArray());
        self::assertSame([
            'revisions'             => ['before' => $f->beforeRoles[2]->getRevision()->toString(), 'after' => null],
            'before_permission_ids' => [$f->beforePermissions[0]->getId()->toString()],
            'after_permission_ids'  => null
        ], $byAction[AuditAction::MANAGED_ROLE_REMOVED->value]->getFacts()->toArray());
        self::assertSame([
            'before' => null,
            'after'  => ['name' => 'NEW', 'tier' => 'ADMIN_SAFE', 'managed' => true]
        ], $byAction[AuditAction::PERMISSION_CREATED->value]->getFacts()->toArray());
        self::assertSame([
            'before' => ['name' => 'OLD', 'tier' => 'ADMIN_SAFE', 'managed' => true],
            'after'  => ['name' => 'CHANGED', 'tier' => 'ADMIN_SAFE', 'managed' => true]
        ], $byAction[AuditAction::PERMISSION_RECONCILED->value]->getFacts()->toArray());
        self::assertSame([
            'before' => ['name' => 'REMOVE', 'tier' => 'ADMIN_SAFE', 'managed' => true],
            'after'  => null
        ], $byAction[AuditAction::PERMISSION_REMOVED->value]->getFacts()->toArray());
        self::assertSame($f->beforeRoles[0], $f->roles->getById($f->beforeRoles[0]->getId()));
        self::assertSame($f->beforePermissions[0], $f->permissions->getById($f->beforePermissions[0]->getId()));
        self::assertNull($f->roles->getById($f->beforeRoles[2]->getId()));
        self::assertNull($f->permissions->getById($f->beforePermissions[2]->getId()));
        self::assertSame($f->now, $changed->getUpdatedAt());
        self::assertSame($f->now, $f->permissions->getById($f->createdPermissionId)->getCreatedAt());
        self::assertInstanceOf(ManagedPolicyReconciled::class, $f->events->events()[0]);
        self::assertSame($plan->toArray(), $f->events->events()[0]->toArray()['plan']);
    }

    public function test_disabled_batch_needs_no_new_actor_and_no_change_enabled_pass_still_publishes(): void
    {
        $f = new ManagedPolicyAuditFixture();
        $f->handler()->handle(CommandMessage::create(new ReconcileManagedPolicy($f->policy)));
        self::assertSame([], $f->audit->all());
        $roles = $f->roles->getManaged();
        $permissions = $f->permissions->getManaged();
        $f->handler($f->audit)->handle(CommandMessage::create(new ReconcileManagedPolicy($f->policy)));
        self::assertSame($roles, $f->roles->getManaged());
        self::assertSame($permissions, $f->permissions->getManaged());
        self::assertSame([], $f->audit->all());
        self::assertCount(2, $f->events->events());
        self::assertInstanceOf(ManagedPolicyReconciled::class, $f->events->events()[1]);
    }

    public function test_enabled_changed_batch_rejects_missing_attribution(): void
    {
        $f = new ManagedPolicyAuditFixture();
        try {
            $f->handler($f->audit)->handle(CommandMessage::create(new ReconcileManagedPolicy($f->policy)));
            self::fail('Enabled changed work requires explicit provenance.');
        } catch (InvalidInput) {
            $this->assertRolledBack($f);
            self::assertInstanceOf(CommandFailedEvent::class, $f->events->events()[0]);
        }
    }

    /** @return iterable<string, array{int, bool}> */
    public static function auditFailures(): iterable
    {
        foreach (range(1, 6) as $position) {
            foreach ([false, true] as $notificationFails) {
                yield $position.'-'.(int) $notificationFails => [$position, $notificationFails];
            }
        }
    }

    #[DataProvider('auditFailures')]
    public function test_each_late_audit_failure_rolls_back_the_entire_mixed_batch(
        int $position,
        bool $notificationFails
    ): void {
        $f = new ManagedPolicyAuditFixture();
        $primary = new RuntimeException('Synthetic audit fault.');
        $count = 0;
        $audit = $this->createMock(AuditEvidenceRepository::class);
        $audit->expects(self::exactly($position))->method('add')->willReturnCallback(static function (
            AuditEvidence $e
        ) use (
            $f,
            $primary,
            $position,
            &$count
        ): void {
            self::assertTrue($f->unit->transactionActive);
            $f->audit->add($e);
            if (++$count === $position) {
                throw $primary;
            }
        });
        $attempts = [];
        $events = $this->events($attempts, null, $notificationFails);
        $caught = null;
        try {
            $f->handler($audit, $events)->handle(CommandMessage::create(new ReconcileManagedPolicy(
                $f->policy,
                SystemId::generate()
            )));
        } catch (Throwable $throwable) {
            $caught = $throwable;
        }

        self::assertSame($primary, $caught);
        self::assertSame($position, $count);
        $this->assertRolledBack($f);
        self::assertCount(1, $attempts);
        self::assertSame('Managed policy reconciliation failed.', $attempts[0]->toArray()['error_message']);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function transactionFailures(): iterable
    {
        foreach (['commit', 'lost-ack-rollback', 'lost-ack-committed', 'publication'] as $boundary) {
            foreach ([false, true] as $notificationFails) {
                yield $boundary.'-'.(int) $notificationFails => [$boundary, $notificationFails];
            }
        }
    }

    #[DataProvider('transactionFailures')]
    public function test_commit_uncertainty_and_publication_preserve_real_persisted_outcomes(
        string $boundary,
        bool $notificationFails
    ): void {
        $f = new ManagedPolicyAuditFixture();
        $primary = new RuntimeException('Synthetic transaction or publication fault.');
        $throw = static function () use ($primary): void {
            throw $primary;
        };
        if ($boundary === 'commit' || $boundary === 'lost-ack-rollback') {
            $f->unit->beforeCommit = $throw;
        } elseif ($boundary === 'lost-ack-committed') {
            $f->unit->afterCommit = $throw;
        }

        $attempts = [];
        $events = $this->events($attempts, $boundary === 'publication' ? $primary : null, $notificationFails);
        $caught = null;
        try {
            $f->handler($f->audit, $events)->handle(CommandMessage::create(new ReconcileManagedPolicy(
                $f->policy,
                SystemId::generate()
            )));
        } catch (Throwable $throwable) {
            $caught = $throwable;
        }

        self::assertSame($primary, $caught);
        if ($boundary === 'commit' || $boundary === 'lost-ack-rollback') {
            $this->assertRolledBack($f);
        } else {
            self::assertCount(6, $f->audit->all());
            self::assertNull($f->roles->getById($f->beforeRoles[2]->getId()));
            self::assertNull($f->permissions->getById($f->beforePermissions[2]->getId()));
            self::assertInstanceOf(Role::class, $f->roles->getById($f->createdRoleId));
            self::assertInstanceOf(Permission::class, $f->permissions->getById($f->createdPermissionId));
            self::assertTrue($f->unit->transactionCompleted);
        }

        self::assertCount($boundary === 'publication' ? 2 : 1, $attempts);
        self::assertInstanceOf(CommandFailedEvent::class, $attempts[array_key_last($attempts)]);
    }

    public function test_empty_enabled_policy_needs_no_actor(): void
    {
        $f = new ManagedPolicyAuditFixture();
        $f->handler()->handle(CommandMessage::create(new ReconcileManagedPolicy(new ManagedPolicy([], []))));
        $f->handler($f->audit)->handle(CommandMessage::create(new ReconcileManagedPolicy(new ManagedPolicy([], []))));
        self::assertSame([], $f->audit->all());
        self::assertCount(2, $f->events->events());
    }

    public function test_converged_reconcile_items_require_no_actor_and_record_no_mutation(): void
    {
        $f = new ManagedPolicyAuditFixture();
        $before = $f->beforePermissions[1];
        $converged = $before->reconcileManaged(PermissionName::fromString('CONVERGED'), $before->getTier(), $f->now);
        $permissionRepository = $this->createMock(PermissionRepository::class);
        $permissionRepository->expects(self::exactly(2))->method('getById')->willReturnOnConsecutiveCalls(
            $before,
            $converged
        );
        $permissionRepository->expects(self::once())->method('getByName')->willReturn($converged);
        $permissionRepository->expects(self::once())->method('getManaged')->willReturn([$converged]);
        $permissionRepository->expects(self::once())->method('replace')->willReturnCallback(
            static function (Permission $expected, Permission $replacement) use ($converged): bool {
                self::assertSame($converged, $expected);
                self::assertSame($converged->getName(), $replacement->getName());

                return true;
            }
        );
        $beforeRole = $f->beforeRoles[1];
        $convergedRole = $beforeRole->reconcileManaged($beforeRole->getName(), [], $f->now);
        $roleRepository = $this->createMock(RoleRepository::class);
        $roleRepository->expects(self::exactly(2))->method('getById')->willReturnOnConsecutiveCalls(
            $beforeRole,
            $convergedRole
        );
        $roleRepository->expects(self::exactly(3))->method('getByName')->willReturnOnConsecutiveCalls(
            $beforeRole,
            null,
            null
        );
        $roleRepository->expects(self::once())->method('getManaged')->willReturn([$convergedRole]);
        $roleRepository->expects(self::once())->method('replace')
            ->with($convergedRole, $convergedRole)->willReturn(true);
        $audit = $this->createMock(AuditEvidenceRepository::class);
        $audit->expects(self::never())->method('add');
        $planner = new ManagedPolicyPlanner(
            $permissionRepository,
            $roleRepository,
            new InMemoryUserRepository($f->unit),
            new InMemoryAgentRepository($f->unit)
        );
        $policy = new ManagedPolicy([
            new ManagedPermissionDefinition($before->getId(), $converged->getName(), PermissionTier::ADMIN_SAFE)
        ], [new ManagedRoleDefinition($beforeRole->getId(), $beforeRole->getName(), [])]);
        new ReconcileManagedPolicyHandler(
            $permissionRepository,
            $roleRepository,
            $planner,
            $f->unit,
            $f->events,
            new FixedClock($f->now),
            $audit
        )->handle(CommandMessage::create(new ReconcileManagedPolicy($policy)));
        self::assertInstanceOf(ManagedPolicyReconciled::class, $f->events->events()[0]);
    }

    public function test_stale_role_cas_rolls_back_earlier_evidence_without_undoing_the_winner(): void
    {
        $f = new ManagedPolicyAuditFixture();
        $state = new InMemoryRoleRepositoryState();
        $before = $f->beforeRoles[1]->reconcileManaged($f->beforeRoles[1]->getName(), [], $f->now);
        $state->roles = [$before];
        $persisted = new InMemoryRoleRepository($f->unit, state: $state);
        $otherConnection = new InMemoryRoleRepository(state: $state);
        $winner = $before->reconcileManaged(RoleName::fromString('ROLE_WINNER'), [], $f->now);
        $reads = 0;
        $roleRepository = $this->createMock(RoleRepository::class);
        $roleRepository->expects(self::exactly(2))->method('getById')->willReturnCallback(
            static function () use (&$reads, $before, $otherConnection, $winner, $f): Role {
                if (++$reads === 2) {
                    self::assertCount(1, $f->audit->all());
                    self::assertTrue($otherConnection->replace($before, $winner));
                }

                return $before;
            }
        );
        $roleRepository->expects(self::exactly(3))->method('getByName')->willReturnOnConsecutiveCalls(
            null,
            null,
            null
        );
        $roleRepository->expects(self::once())->method('getManaged')->willReturn([$before]);
        $roleRepository->expects(self::once())->method('replace')->willReturnCallback($persisted->replace(...));
        $permissionRepository = new InMemoryPermissionRepository($f->unit);
        $planner = new ManagedPolicyPlanner(
            $permissionRepository,
            $roleRepository,
            new InMemoryUserRepository($f->unit),
            new InMemoryAgentRepository($f->unit)
        );
        $policy = new ManagedPolicy([
            new ManagedPermissionDefinition(
                $f->createdPermissionId,
                PermissionName::fromString('NEW'),
                PermissionTier::ADMIN_SAFE
            )
        ], [new ManagedRoleDefinition($before->getId(), RoleName::fromString('ROLE_DESIRED'), [])]);
        try {
            new ReconcileManagedPolicyHandler(
                $permissionRepository,
                $roleRepository,
                $planner,
                $f->unit,
                $f->events,
                new FixedClock($f->now),
                $f->audit
            )->handle(CommandMessage::create(new ReconcileManagedPolicy($policy, SystemId::generate())));
            self::fail('A losing expected-state replacement cannot commit its earlier batch writes.');
        } catch (StateConflict) {
            self::assertSame([$winner], $persisted->getManaged());
            self::assertSame([], $permissionRepository->getManaged());
            self::assertSame([], $f->audit->all());
            self::assertFalse($f->unit->transactionCompleted);
            self::assertInstanceOf(CommandFailedEvent::class, $f->events->events()[0]);
        }
    }

    private function assertRolledBack(ManagedPolicyAuditFixture $f): void
    {
        self::assertSame($f->beforeRoles, $f->roles->getManaged());
        self::assertSame($f->beforePermissions, $f->permissions->getManaged());
        self::assertSame([], $f->audit->all());
        self::assertNull($f->roles->getById($f->createdRoleId));
        self::assertNull($f->permissions->getById($f->createdPermissionId));
        self::assertFalse($f->unit->transactionActive);
        self::assertFalse($f->unit->transactionCompleted);
    }

    /** @param list<Event> $attempts */
    private function events(
        array &$attempts,
        ?Throwable $publicationFailure,
        bool $notificationFails
    ): InMemoryEventDispatcher {
        return new InMemoryEventDispatcher(static function (Event $event) use (
            &$attempts,
            $publicationFailure,
            $notificationFails
        ): void {
            $attempts[] = $event;
            if ($event instanceof ManagedPolicyReconciled && $publicationFailure !== null) {
                throw $publicationFailure;
            }

            if ($event instanceof CommandFailedEvent && $notificationFails) {
                throw new RuntimeException('Synthetic notification fault.');
            }
        });
    }
}
