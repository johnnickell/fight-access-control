<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Role\CommandHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\Role\CommandHandler\CreateCustomRoleHandler;
use Fight\AccessControl\Application\AccessControl\Role\CommandHandler\RemoveCustomRoleHandler;
use Fight\AccessControl\Application\AccessControl\Role\CommandHandler\RenameCustomRoleHandler;
use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditAction;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditRoleRevisions;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Role\Role;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\Role\RoleName;
use Fight\AccessControl\Domain\AccessControl\Role\RoleRevision;
use Fight\Common\Application\Error\Exception\StateConflict;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Fight\Common\Domain\Messaging\Event\Event;
use Fight\Test\AccessControl\Application\AccessControl\Audit\Conformance\AuditTransactionConformance;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\Role\Repository\InMemoryRoleRepository;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/** Ordinary real-handler binding; completion snapshots are modeled bytes, not SQL or process durability */
#[CoversClass(CreateCustomRoleHandler::class)]
#[CoversClass(RenameCustomRoleHandler::class)]
#[CoversClass(RemoveCustomRoleHandler::class)]
final class RoleAuditCompositionTest extends TestCase
{
    private RuntimeException $primaryFailure;

    #[DataProviderExternal(RoleLifecycleAuditTest::class, 'operations')]
    public function test_shared_composition_reopens_complete_role_and_evidence(string $operation): void
    {
        $fixture = new RoleLifecycleAuditFixture($operation, mappedAudit: true);
        $message = CommandMessage::create($fixture->command);
        $fixture->bus->dispatch($message);

        $current = $this->reopenRole($fixture);
        $records = $fixture->audit->all();
        self::assertSame($fixture->unitOfWork, $fixture->audit->transactionIdentity());
        self::assertSame($fixture->unitOfWork, $fixture->roleRepository->transactionIdentity());
        self::assertSame($fixture->unitOfWork, $fixture->userRepository->transactionIdentity());
        self::assertSame(1, $fixture->unitOfWork->transactions);
        self::assertCount(1, $records);
        $evidence = $records[0];
        self::assertNotSame($evidence, $fixture->audit->all()[0]);
        self::assertSame('user', $evidence->toArray()['actor']['kind']);
        self::assertSame($fixture->command->getActorId()->toString(), $evidence->actorId()->toString());
        self::assertSame('role', $evidence->toArray()['subject']['kind']);
        self::assertSame($fixture->original->getId()->toString(), $evidence->getSubject()->getId()->toString());
        self::assertSame($fixture->outcomeTime->format('Y-m-d\TH:i:s.uP'), $evidence->toArray()['occurred_at']);
        self::assertEquals($message->id(), $evidence->getCorrelation()?->getMessageId());
        self::assertNull($evidence->getCorrelation()?->getOperationKey());
        self::assertSame(match ($operation) {
            'create' => AuditAction::ROLE_CREATED,
            'rename' => AuditAction::ROLE_RENAMED,
            'remove' => AuditAction::ROLE_REMOVED
        }, $evidence->action());
        self::assertEquals(
            new AuditRoleRevisions(
                $operation === 'create' ? null : $fixture->original->getRevision(),
                $current?->getRevision()
            ),
            $evidence->getFacts()
        );
        self::assertSame($operation !== 'remove', $current !== null);
        if ($current !== null) {
            self::assertNotSame($current, $this->reopenRole($fixture));
            self::assertSame(
                $operation === 'create' ? 'ROLE_SUPPORT' : 'ROLE_HELP_DESK',
                $current->getName()->toString()
            );
            self::assertEquals($fixture->outcomeTime, $current->getUpdatedAt());
        }
    }

    #[DataProviderExternal(AuditTransactionConformance::class, 'rollbackFaults')]
    public function test_reusable_rollback_controls_reopen_neither_outcome(string $fault): void
    {
        foreach (['create', 'rename', 'remove'] as $operation) {
            $fixture = $this->faultFixture($operation, $fault);
            $before = $this->reopenRole($fixture);
            $failure = $this->executeFailure($fixture);
            self::assertSame($fixture->audit->failure() ?? $this->primaryFailure, $failure);
            self::assertEquals($before, $this->reopenRole($fixture));
            self::assertSame([], $fixture->audit->all());
        }
    }

    #[DataProviderExternal(AuditTransactionConformance::class, 'committedFaults')]
    public function test_reusable_committed_controls_reopen_both_despite_throw(string $fault): void
    {
        foreach (['create', 'rename', 'remove'] as $operation) {
            $fixture = $this->faultFixture($operation, $fault);
            $failure = $this->executeFailure($fixture);
            self::assertSame($this->primaryFailure, $failure);
            self::assertCount(1, $fixture->audit->all());
            self::assertSame($operation !== 'remove', $this->reopenRole($fixture) !== null);
            self::assertEquals(
                $this->reopenRole($fixture)?->getRevision(),
                $fixture->audit->all()[0]->getFacts()->getAfter()
            );
        }
    }

    #[DataProviderExternal(RoleLifecycleAuditTest::class, 'operations')]
    public function test_final_concurrent_loser_reopens_original_and_no_evidence(string $operation): void
    {
        $fixture = new RoleLifecycleAuditFixture($operation, mappedAudit: true);
        $before = $this->reopenRole($fixture);
        $failure = new StateConflict('Synthetic final validation loss.');
        $fixture->unitOfWork->beforeCommit = static function () use ($fixture, $failure): void {
            self::assertCount(1, $fixture->audit->all());

            throw $failure;
        };
        self::assertSame($failure, $this->executeFailure($fixture));
        self::assertEquals($before, $this->reopenRole($fixture));
        self::assertSame([], $fixture->audit->all());
    }

    public function test_independent_winner_survives_losing_rename_and_remove(): void
    {
        foreach (['rename', 'remove'] as $operation) {
            $clock = $this->createMock(Clock::class);
            $fixture = new RoleLifecycleAuditFixture($operation, clock: $clock, mappedAudit: true);
            $winner = new InMemoryUnitOfWork();
            $winnerRepository = new InMemoryRoleRepository(
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
                $winnerRepository,
                $fixture,
                $replacement
            ): DateTimeImmutable {
                $winner->commitTransactional(static function () use ($winnerRepository, $fixture, $replacement): void {
                    self::assertTrue($winnerRepository->replace($fixture->original, $replacement));
                });

                return $fixture->outcomeTime;
            });
            self::assertInstanceOf(StateConflict::class, $this->executeFailure($fixture));
            self::assertTrue($replacement->hasSameState($this->reopenRole($fixture)));
            self::assertSame([], $fixture->audit->all());
        }
    }

    public function test_genuinely_new_outcomes_have_distinct_non_nil_evidence_identity(): void
    {
        $ids = [];
        foreach (['create', 'rename', 'remove'] as $operation) {
            $fixture = new RoleLifecycleAuditFixture($operation, mappedAudit: true);
            $fixture->bus->execute($fixture->command);
            $ids[] = $fixture->audit->all()[0]->getId()->toString();
        }

        self::assertCount(3, array_unique($ids));
        self::assertNotContains('00000000-0000-0000-0000-000000000000', $ids);
    }

    public function test_disabled_and_validated_no_change_reopen_without_backfill(): void
    {
        foreach (['create', 'rename', 'remove', 'same-name'] as $operation) {
            foreach ([false, true] as $omitAudit) {
                $fixture = new RoleLifecycleAuditFixture(
                    $operation,
                    auditEnabled: false,
                    omitAudit: $omitAudit,
                    mappedAudit: true
                );
                $fixture->bus->execute($fixture->command);
                self::assertSame([], $fixture->audit->all());
                self::assertSame($operation !== 'remove', $this->reopenRole($fixture) !== null);
            }
        }

        $fixture = new RoleLifecycleAuditFixture('same-name', mappedAudit: true, failBeforeAudit: true);
        $before = $this->reopenRole($fixture);
        $fixture->bus->execute($fixture->command);
        self::assertEquals($before, $this->reopenRole($fixture));
        self::assertSame([], $fixture->audit->all());
        self::assertSame([], $fixture->eventDispatcher->events());
    }

    private function faultFixture(string $operation, string $fault): RoleLifecycleAuditFixture
    {
        $primary = new RuntimeException('Synthetic composition fault.');
        $this->primaryFailure = $primary;
        $fixture = new RoleLifecycleAuditFixture(
            $operation,
            failAudit: $fault === 'audit-after',
            eventDispatcher: new InMemoryEventDispatcher(static function (Event $event) use ($fault, $primary): void {
                if ($event instanceof CommandFailedEvent) {
                    throw new RuntimeException('Synthetic secondary fault.');
                }

                if ($fault === 'publication') {
                    throw $primary;
                }
            }),
            mappedAudit: true,
            failBeforeAudit: $fault === 'audit-before'
        );
        $injection = static function () use ($primary): void {
            throw $primary;
        };
        if (in_array($fault, ['rollback', 'uncertain-uncommitted'], true)) {
            $fixture->unitOfWork->beforeCommit = $injection;
        } elseif ($fault === 'uncertain-committed') {
            $fixture->unitOfWork->afterCommit = $injection;
        }

        return $fixture;
    }

    private function executeFailure(RoleLifecycleAuditFixture $fixture): Throwable
    {
        try {
            $fixture->bus->execute($fixture->command);
        } catch (Throwable $throwable) {
            return $throwable;
        }

        self::fail('The required fault did not propagate.');
    }

    private function reopenRole(RoleLifecycleAuditFixture $fixture): ?Role
    {
        $role = $fixture->roleRepository->getById($fixture->original->getId());
        if ($role === null) {
            return null;
        }

        // Observe backing state even after failure; never hide broken rollback behind a prior checkpoint.
        $bytes = json_encode([
            'id'          => $role->getId()->toString(),
            'name'        => $role->getName()->toString(),
            'permissions' => array_map(
                static fn(PermissionId $id): string => $id->toString(),
                $role->getPermissionIds()
            ),
            'managed'     => $role->isManaged(),
            'created'     => $role->getCreatedAt()->format('Y-m-d\TH:i:s.uP'),
            'updated'     => $role->getUpdatedAt()->format('Y-m-d\TH:i:s.uP'),
            'revision'    => $role->getRevision()->toString()
        ], JSON_THROW_ON_ERROR);
        $data = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);

        return Role::reconstitute(
            RoleId::fromString($data['id']),
            RoleName::fromString($data['name']),
            array_map(PermissionId::fromString(...), $data['permissions']),
            $data['managed'],
            new DateTimeImmutable($data['created']),
            new DateTimeImmutable($data['updated']),
            RoleRevision::fromString($data['revision'])
        );
    }
}
