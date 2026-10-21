<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Role\CommandHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\Role\CommandHandler\RemoveCustomRoleHandler;
use Fight\AccessControl\Application\AccessControl\Role\CommandHandler\RenameCustomRoleHandler;
use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\AccessControl\Domain\AccessControl\Role\Command\RemoveCustomRole;
use Fight\AccessControl\Domain\AccessControl\Role\Command\RenameCustomRole;
use Fight\AccessControl\Domain\AccessControl\Role\Event\CustomRoleRemoved;
use Fight\AccessControl\Domain\AccessControl\Role\Event\CustomRoleRenamed;
use Fight\AccessControl\Domain\AccessControl\Role\Exception\RoleConflictException;
use Fight\AccessControl\Domain\AccessControl\Role\Role;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\Role\RoleName;
use Fight\AccessControl\Domain\AccessControl\Role\RoleRepository;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Fight\Common\Domain\Messaging\Event\Event;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\Permission\Repository\InMemoryPermissionRepository;
use Fight\Test\AccessControl\Application\AccessControl\Role\Repository\InMemoryRoleRepository;
use Fight\Test\AccessControl\Application\AccessControl\Role\Repository\InMemoryRoleRepositoryState;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryUserRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

#[CoversClass(RenameCustomRoleHandler::class)]
#[CoversClass(RemoveCustomRoleHandler::class)]
#[CoversClass(RenameCustomRole::class)]
#[CoversClass(RemoveCustomRole::class)]
#[CoversClass(RoleConflictException::class)]
final class RoleRevisionHandlerTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function operations(): iterable
    {
        yield 'rename' => ['rename'];
        yield 'remove' => ['remove'];
        yield 'same-name' => ['same-name'];
    }

    #[DataProvider('operations')]
    public function test_stale_intent_rejects_name_membership_aba_and_recreation(string $operation): void
    {
        foreach (['name', 'membership', 'recreation'] as $writer) {
            $unitOfWork = new InMemoryUnitOfWork();
            $roles = new InMemoryRoleRepository($unitOfWork);
            $role = $this->role();
            $roles->add($role);
            $middle = $role->renameCustom(RoleName::fromString('ROLE_B'), $role->getUpdatedAt());
            $current = $middle->renameCustom($role->getName(), $role->getUpdatedAt());
            if ($writer === 'membership') {
                $permission = Permission::define(
                    PermissionId::generate(),
                    PermissionName::fromString('READ_CASES'),
                    $role->getUpdatedAt()
                );
                new InMemoryPermissionRepository($unitOfWork)->add($permission);
                $middle = $role->grantPermissionToCustom($permission->getId(), $role->getUpdatedAt());
                $current = $middle->revokePermissionFromCustom($permission->getId(), $role->getUpdatedAt());
            }

            $unitOfWork->commitTransactional(static function () use ($roles, $role, $middle, &$current, $writer): void {
                if ($writer === 'recreation') {
                    self::assertTrue($roles->remove($role));
                    $current = Role::define($role->getId(), $role->getName(), [], $role->getUpdatedAt());
                    $roles->add($current);
                } else {
                    self::assertTrue($roles->replace($role, $middle));
                    self::assertTrue($roles->replace($middle, $current));
                }
            });
            $unitOfWork->transactionCompleted = false;

            $attempts = [];
            $events = new InMemoryEventDispatcher(static function (Event $event) use (&$attempts): void {
                $attempts[] = $event;
                throw new RuntimeException('Synthetic notification detail.');
            });
            $command = $this->command($operation, $role);
            $caught = $this->failure(fn() => $this->dispatch($command, $roles, $unitOfWork, $events));
            self::assertInstanceOf(RoleConflictException::class, $caught);
            self::assertSame($current, $roles->getById($role->getId()));
            self::assertFalse($unitOfWork->transactionCompleted);
            self::assertCount(1, $attempts);
            self::assertInstanceOf(CommandFailedEvent::class, $attempts[0]);
            $data = $attempts[0]->toArray();
            self::assertSame($command->toArray(), $data['command_data']);
            self::assertSame($data, CommandFailedEvent::fromArray($data)->toArray());
            self::assertStringNotContainsString('detail', serialize($data));
            self::assertSame(
                'Custom role '.($operation === 'remove' ? 'removal' : 'rename').' failed.',
                $data['error_message']
            );
        }
    }

    public function test_fresh_same_name_fences_current_state_without_writing_or_success(): void
    {
        $role = $this->role();
        $roles = $this->createMock(RoleRepository::class);
        $roles->method('getById')->willReturn($role);
        $roles->expects(self::once())->method('validateCurrent')->with($role)->willReturn(true);
        $roles->expects(self::never())->method('replace');
        $roles->expects(self::never())->method('remove');
        $clock = $this->createMock(Clock::class);
        $clock->expects(self::never())->method('now');
        $unitOfWork = new InMemoryUnitOfWork();
        $events = new InMemoryEventDispatcher();
        $this->dispatch($this->command('same-name', $role), $roles, $unitOfWork, $events, $clock);
        self::assertTrue($unitOfWork->transactionCompleted);
        self::assertSame([], $events->events());
    }

    public function test_same_name_final_comparison_loss_is_a_conflict_without_a_write_or_success(): void
    {
        $role = $this->role();
        $roles = $this->createMock(RoleRepository::class);
        $roles->method('getById')->willReturn($role);
        $roles->expects(self::once())->method('validateCurrent')->with($role)->willReturn(false);
        $roles->expects(self::never())->method('replace');
        $events = new InMemoryEventDispatcher();
        $caught = $this->failure(fn() => $this->dispatch(
            $this->command('same-name', $role),
            $roles,
            new InMemoryUnitOfWork(),
            $events
        ));
        self::assertInstanceOf(RoleConflictException::class, $caught);
        self::assertCount(1, $events->events());
        self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
    }

    public function test_change_after_handler_read_wins_final_comparison_and_survives_loser_rollback(): void
    {
        foreach (['rename', 'remove'] as $operation) {
            $unitOfWork = new InMemoryUnitOfWork();
            $state = new InMemoryRoleRepositoryState();
            $roles = new InMemoryRoleRepository($unitOfWork, state: $state);
            $role = $this->role();
            $roles->add($role);
            $winner = new InMemoryUnitOfWork();
            $competing = new InMemoryRoleRepository(
                $winner,
                authorizationReferences: $unitOfWork->authorizationReferenceState(),
                state: $state
            );
            $replacement = $role->renameCustom(RoleName::fromString('ROLE_WINNER'), $role->getUpdatedAt());
            $clock = $this->createMock(Clock::class);
            $clock->expects(self::once())->method('now')->willReturnCallback(static function () use (
                $winner,
                $competing,
                $role,
                $replacement
            ): DateTimeImmutable {
                $winner->commitTransactional(static function () use ($competing, $role, $replacement): void {
                    self::assertTrue($competing->replace($role, $replacement));
                });

                return $role->getUpdatedAt();
            });
            $events = new InMemoryEventDispatcher();
            $caught = $this->failure(fn() => $this->dispatch(
                $this->command($operation, $role),
                $roles,
                $unitOfWork,
                $events,
                $clock
            ));
            self::assertInstanceOf(RoleConflictException::class, $caught);
            self::assertSame($replacement, $roles->getById($role->getId()));
            self::assertTrue($winner->transactionCompleted);
            self::assertFalse($unitOfWork->transactionCompleted);
            self::assertCount(1, $events->events());
            self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
        }
    }

    public function test_no_op_holds_the_writer_fence_until_commit_or_rollback(): void
    {
        foreach ([false, true] as $rollback) {
            $unitOfWork = new InMemoryUnitOfWork();
            $state = new InMemoryRoleRepositoryState();
            $roles = new InMemoryRoleRepository($unitOfWork, state: $state);
            $role = $this->role();
            $roles->add($role);
            $competitor = new InMemoryUnitOfWork();
            $competing = new InMemoryRoleRepository(
                $competitor,
                authorizationReferences: $unitOfWork->authorizationReferenceState(),
                state: $state
            );
            $replacement = $role->renameCustom(RoleName::fromString('ROLE_B'), $role->getUpdatedAt());
            $unitOfWork->beforeCommit = static function () use ($competitor, $competing, $role, $replacement): void {
                $competitor->commitTransactional(static function () use ($competing, $role, $replacement): void {
                    self::assertFalse($competing->replace($role, $replacement));
                    self::assertFalse($competing->remove($role));
                });
            };
            $unitOfWork->failNextCommit = $rollback;
            $events = new InMemoryEventDispatcher();
            try {
                $this->dispatch($this->command('same-name', $role), $roles, $unitOfWork, $events);
                self::assertFalse($rollback);
                self::assertSame([], $events->events());
            } catch (RuntimeException $caught) {
                self::assertTrue($rollback);
                self::assertSame('Injected transaction failure.', $caught->getMessage());
                self::assertCount(1, $events->events());
            }

            self::assertSame($role, $roles->getById($role->getId()));
            $competitor->commitTransactional(static function () use ($competing, $role, $replacement): void {
                self::assertTrue($competing->replace($role, $replacement));
            });
            self::assertSame($replacement, $roles->getById($role->getId()));
        }
    }

    public function test_transaction_and_publication_failures_preserve_primary_and_actual_state(): void
    {
        foreach (['rename', 'remove'] as $operation) {
            foreach (['before-commit', 'after-commit', 'publication'] as $stage) {
                $unitOfWork = new InMemoryUnitOfWork();
                $roles = new InMemoryRoleRepository($unitOfWork);
                $role = $this->role();
                $roles->add($role);
                $primary = new RuntimeException('Synthetic primary storage or publication detail.');
                $fail = static function () use ($primary): void {
                    throw $primary;
                };
                if ($stage === 'before-commit') {
                    $unitOfWork->beforeCommit = $fail;
                }

                if ($stage === 'after-commit') {
                    $unitOfWork->afterCommit = $fail;
                }

                $attempts = [];
                $events = new InMemoryEventDispatcher(static function (Event $event) use (
                    &$attempts,
                    $primary,
                    $unitOfWork
                ): void {
                    $attempts[] = $event;
                    if (!$event instanceof CommandFailedEvent) {
                        self::assertTrue($unitOfWork->transactionCompleted);
                        throw $primary;
                    }

                    throw new RuntimeException('Synthetic secondary notification detail.');
                });
                $command = $this->command($operation, $role);
                $caught = $this->failure(fn() => $this->dispatch($command, $roles, $unitOfWork, $events));
                self::assertSame($primary, $caught);
                $current = $roles->getById($role->getId());
                if ($stage === 'before-commit') {
                    self::assertSame($role, $current);
                } elseif ($operation === 'remove') {
                    self::assertNull($current);
                } else {
                    self::assertInstanceOf(Role::class, $current);
                    self::assertSame('ROLE_B', $current->getName()->toString());
                    self::assertTrue($role->getRevision()->next()->equals($current->getRevision()));
                }

                $failure = $attempts[array_key_last($attempts)];
                self::assertInstanceOf(CommandFailedEvent::class, $failure);
                self::assertSame($command->toArray(), $failure->toArray()['command_data']);
                self::assertStringNotContainsString('detail', serialize($failure->toArray()));
                self::assertCount($stage === 'publication' ? 2 : 1, $attempts);
                if ($stage === 'publication') {
                    self::assertInstanceOf(
                        $operation === 'rename' ? CustomRoleRenamed::class : CustomRoleRemoved::class,
                        $attempts[0]
                    );
                }
            }
        }
    }

    public function test_current_payloads_require_every_key_and_reject_malformed_revisions_without_fallback(): void
    {
        $role = $this->role();
        foreach (['rename', 'remove'] as $operation) {
            $command = $this->command($operation, $role);
            self::assertTrue($role->getRevision()->equals($command->getExpectedRevision()));
            self::assertSame($role->getRevision()->toString(), $command->toArray()['expected_revision']);
            self::assertEquals($command, $command::fromArray($command->toArray()));
            foreach (array_keys($command->toArray()) as $missing) {
                $data = $command->toArray();
                unset($data[$missing]);
                self::assertInstanceOf(
                    DomainException::class,
                    $this->failure(fn(): RenameCustomRole|RemoveCustomRole => $command::fromArray($data))
                );
            }

            foreach ([null, 1, [], 'bad', '11111111-1111-4111-8111-111111111111:0'] as $invalid) {
                $data = $command->toArray();
                $data['expected_revision'] = $invalid;
                self::assertInstanceOf(
                    DomainException::class,
                    $this->failure(fn(): RenameCustomRole|RemoveCustomRole => $command::fromArray($data))
                );
            }
        }
    }

    private function role(): Role
    {
        return Role::define(
            RoleId::generate(),
            RoleName::fromString('ROLE_A'),
            [],
            new DateTimeImmutable('2030-01-01T00:00:00Z')
        );
    }

    private function command(string $operation, Role $role): RenameCustomRole|RemoveCustomRole
    {
        $actor = UserId::generate();
        if ($operation === 'remove') {
            return new RemoveCustomRole($actor, $role->getId(), $role->getRevision());
        }

        return new RenameCustomRole(
            $actor,
            $role->getId(),
            RoleName::fromString($operation === 'same-name' ? 'ROLE_A' : 'ROLE_B'),
            $role->getRevision()
        );
    }

    private function dispatch(
        RenameCustomRole|RemoveCustomRole $command,
        RoleRepository $roles,
        InMemoryUnitOfWork $unitOfWork,
        InMemoryEventDispatcher $events,
        ?Clock $clock = null
    ): void {
        $clock ??= new FixedClock('2030-01-01T00:00:00Z');
        if ($command instanceof RemoveCustomRole) {
            new RemoveCustomRoleHandler($roles, new InMemoryUserRepository($unitOfWork), $clock, $unitOfWork, $events)
                ->handle(CommandMessage::create($command));

            return;
        }

        new RenameCustomRoleHandler($roles, $clock, $unitOfWork, $events)->handle(CommandMessage::create($command));
    }

    /** @param callable(): mixed $operation */
    private function failure(callable $operation): Throwable
    {
        try {
            $operation();
        } catch (Throwable $throwable) {
            return $throwable;
        }

        self::fail('The operation must reject.');
    }
}
