<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User\CommandHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\AssignRoleToUserHandler;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\RemoveRoleFromUserHandler;
use Fight\AccessControl\Domain\AccessControl\Role\Role;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\Role\RoleName;
use Fight\AccessControl\Domain\AccessControl\Role\RoleRepository;
use Fight\AccessControl\Domain\AccessControl\User\Command\AssignRoleToUser;
use Fight\AccessControl\Domain\AccessControl\User\Command\RemoveRoleFromUser;
use Fight\AccessControl\Domain\AccessControl\User\Event\RoleAssignedToUser;
use Fight\AccessControl\Domain\AccessControl\User\Event\RoleRemovedFromUser;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Application\Error\Exception\ResourceNotFound;
use Fight\Common\Application\Error\Exception\StateConflict;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\Role\Repository\ControllableRoleRepository;
use Fight\Test\AccessControl\Application\AccessControl\Role\Repository\InMemoryRoleRepository;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryUserRepository;
use Fight\Test\AccessControl\Domain\AccessControl\User\UserFixture;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

#[CoversClass(AssignRoleToUserHandler::class)]
#[CoversClass(RemoveRoleFromUserHandler::class)]
#[CoversClass(AssignRoleToUser::class)]
#[CoversClass(RemoveRoleFromUser::class)]
#[CoversClass(RoleAssignedToUser::class)]
#[CoversClass(RoleRemovedFromUser::class)]
#[CoversClass(User::class)]
final class UserRoleAssignmentHandlerTest extends TestCase
{
    private const string NOW = '2026-08-23T12:00:00+00:00';

    public function test_managed_super_admin_can_be_assigned_to_pending_user_and_removed_after_commit(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $userRepository = new InMemoryUserRepository($unitOfWork);
        $user = UserFixture::withState('pending@example.test', UserState::PENDING_ACTIVATION);
        $userRepository->add($user);
        $roleRepository = new InMemoryRoleRepository($unitOfWork);
        $role = $this->role(Role::SUPER_ADMIN_NAME, managed: true);
        $roleRepository->add($role);
        $actorId = UserId::generate();
        $eventDispatcher = new InMemoryEventDispatcher(
            static function ($event) use ($unitOfWork, $userRepository, $user): void {
                self::assertTrue($unitOfWork->transactionCompleted);
                self::assertNotNull($userRepository->getById($user->getId()));
            }
        );
        $assignment = new AssignRoleToUserHandler(
            $userRepository,
            $roleRepository,
            new FixedClock(self::NOW),
            $unitOfWork,
            $eventDispatcher
        );
        $removal = new RemoveRoleFromUserHandler(
            $userRepository,
            $roleRepository,
            new FixedClock(self::NOW),
            $unitOfWork,
            $eventDispatcher
        );
        $beforeVersion = $user->getAuthenticationVersion();

        $assignment->handle(CommandMessage::create(new AssignRoleToUser($actorId, $user->getId(), $role->getId())));
        $assigned = $userRepository->getById($user->getId());
        self::assertInstanceOf(User::class, $assigned);
        self::assertTrue($assigned->hasRole($role->getId()));
        self::assertSame(UserState::PENDING_ACTIVATION, $assigned->getState());
        self::assertSame($beforeVersion, $assigned->getAuthenticationVersion());
        self::assertSame(1, $assigned->getAuthorizationAssignmentRevision());
        self::assertInstanceOf(RoleAssignedToUser::class, $eventDispatcher->events()[0]);
        self::assertSame($actorId, $eventDispatcher->events()[0]->getActorId());

        $removal->handle(CommandMessage::create(new RemoveRoleFromUser($actorId, $user->getId(), $role->getId())));
        $removed = $userRepository->getById($user->getId());
        self::assertInstanceOf(User::class, $removed);
        self::assertFalse($removed->hasRole($role->getId()));
        self::assertSame(2, $removed->getAuthorizationAssignmentRevision());
        self::assertSame(2, $unitOfWork->transactions);
        self::assertInstanceOf(RoleRemovedFromUser::class, $eventDispatcher->events()[1]);
    }

    public function test_disabled_and_deleted_users_retain_ordinary_role_storage_semantics(): void
    {
        foreach ([UserState::DISABLED, UserState::DELETED] as $state) {
            $unitOfWork = new InMemoryUnitOfWork();
            $user = UserFixture::withState($state->value.'@example.test', $state);
            $userRepository = new InMemoryUserRepository($unitOfWork);
            $userRepository->add($user);
            $roleRepository = new InMemoryRoleRepository($unitOfWork);
            $role = $this->role(Role::SUPER_ADMIN_NAME, managed: true);
            $roleRepository->add($role);
            $eventDispatcher = new InMemoryEventDispatcher();
            $actor = UserId::generate();

            new AssignRoleToUserHandler(
                $userRepository,
                $roleRepository,
                new FixedClock(self::NOW),
                $unitOfWork,
                $eventDispatcher
            )
                ->handle(CommandMessage::create(new AssignRoleToUser($actor, $user->getId(), $role->getId())));
            $assigned = $userRepository->getById($user->getId());
            self::assertInstanceOf(User::class, $assigned);
            self::assertSame($state, $assigned->getState());
            self::assertTrue($assigned->hasRole($role->getId()));

            new RemoveRoleFromUserHandler(
                $userRepository,
                $roleRepository,
                new FixedClock(self::NOW),
                $unitOfWork,
                $eventDispatcher
            )
                ->handle(CommandMessage::create(new RemoveRoleFromUser($actor, $user->getId(), $role->getId())));
            $removed = $userRepository->getById($user->getId());
            self::assertInstanceOf(User::class, $removed);
            self::assertSame($state, $removed->getState());
            self::assertFalse($removed->hasRole($role->getId()));
            self::assertSame(2, $removed->getAuthorizationAssignmentRevision());
            self::assertCount(2, $eventDispatcher->events());
        }
    }

    public function test_ordinary_roles_retain_other_assignments_and_do_not_change_authentication_state(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $userRepository = new InMemoryUserRepository($unitOfWork);
        $role = $this->role();
        $existing = $this->role('ROLE_EXISTING');
        $roleRepository = new InMemoryRoleRepository($unitOfWork);
        $roleRepository->add($existing);
        $roleRepository->add($role);

        $user = UserFixture::withRoleAssignments([$existing->getId()], 5);
        $userRepository->add($user);
        $eventDispatcher = new InMemoryEventDispatcher();

        new AssignRoleToUserHandler(
            $userRepository,
            $roleRepository,
            new FixedClock(self::NOW),
            $unitOfWork,
            $eventDispatcher
        )
            ->handle(CommandMessage::create(new AssignRoleToUser(UserId::generate(), $user->getId(), $role->getId())));

        $stored = $userRepository->getById($user->getId());
        self::assertInstanceOf(User::class, $stored);
        self::assertTrue($stored->hasRole($existing->getId()));
        self::assertTrue($stored->hasRole($role->getId()));
        self::assertSame(6, $stored->getAuthorizationAssignmentRevision());
        self::assertSame($user->getAuthenticationVersion(), $stored->getAuthenticationVersion());
        self::assertSame($user->getAuthenticationAuthorityRevision(), $stored->getAuthenticationAuthorityRevision());
        self::assertSame(self::NOW, $stored->getUpdatedAt()->format(DATE_ATOM));
        self::assertCount(1, $eventDispatcher->events());
    }

    public function test_no_op_retries_validate_references_without_writing_or_emitting_events(): void
    {
        foreach ([true, false] as $assigning) {
            $unitOfWork = new InMemoryUnitOfWork();
            $role = $this->role();
            $userRepository = new InMemoryUserRepository($unitOfWork);
            $user = UserFixture::withRoleAssignments($assigning ? [$role->getId()] : [], 7);
            $roleRepository = new InMemoryRoleRepository($unitOfWork);
            $roleRepository->add($role);
            $userRepository->add($user);
            $eventDispatcher = new InMemoryEventDispatcher();
            [$handler, $command] = $this->operation(
                $assigning,
                $userRepository,
                $roleRepository,
                $unitOfWork,
                $eventDispatcher,
                $user,
                $role
            );

            $handler->handle(CommandMessage::create($command));

            self::assertSame($user, $userRepository->getById($user->getId()));
            self::assertSame(7, $user->getAuthorizationAssignmentRevision());
            self::assertCount(0, $eventDispatcher->events());
            self::assertSame(1, $unitOfWork->transactions);
        }
    }

    public function test_missing_user_or_role_and_inconsistent_super_admin_identity_fail_without_changes(): void
    {
        foreach ([true, false] as $assigning) {
            $user = UserFixture::withRoleAssignments([], 3);
            $role = $this->role();
            $designated = $this->role(Role::SUPER_ADMIN_NAME, managed: true);
            foreach (['user', 'role', 'identity'] as $invalid) {
                $unitOfWork = new InMemoryUnitOfWork();
                $userRepository = new InMemoryUserRepository($unitOfWork);
                if ($invalid !== 'user') {
                    $userRepository->add($user);
                }

                $roleRepository = new InMemoryRoleRepository($unitOfWork);
                if ($invalid !== 'role') {
                    $storedRole = $role;
                    if ($invalid === 'identity') {
                        $storedRole = Role::defineManaged(
                            $designated->getId(),
                            RoleName::fromString('ROLE_OTHER'),
                            [],
                            new DateTimeImmutable(self::NOW)
                        );
                    }

                    $roleRepository->add($storedRole);
                }

                if ($invalid === 'identity') {
                    $roleRepository->add($designated);
                    $role = $designated;
                }

                $eventDispatcher = new InMemoryEventDispatcher();
                [$handler, $command] = $this->operation(
                    $assigning,
                    $userRepository,
                    $roleRepository,
                    $unitOfWork,
                    $eventDispatcher,
                    $user,
                    $role
                );
                $failure = $this->captureFailure($handler, $command);

                self::assertInstanceOf(
                    $invalid === 'identity' ? RuntimeException::class : ResourceNotFound::class,
                    $failure
                );
                self::assertSame(3, $user->getAuthorizationAssignmentRevision());
                $this->assertFailure($eventDispatcher, $command, $failure);
            }
        }
    }

    public function test_adapter_mismatched_role_id_and_duplicate_super_admin_name_fail_closed(): void
    {
        foreach ([true, false] as $assigning) {
            foreach (['wrong id', 'duplicate name'] as $case) {
                $unitOfWork = new InMemoryUnitOfWork();
                $user = UserFixture::withRoleAssignments([], 3);
                $userRepository = new InMemoryUserRepository($unitOfWork);
                $userRepository->add($user);
                $requested = $this->role(Role::SUPER_ADMIN_NAME, managed: true);
                $other = $this->role(Role::SUPER_ADMIN_NAME, managed: true);
                $roleRepository = $this->createStub(RoleRepository::class);
                $roleRepository->method('getById')->willReturn($case === 'wrong id' ? $other : $requested);
                $roleRepository->method('getByName')->willReturn($other);
                $eventDispatcher = new InMemoryEventDispatcher();
                $command = new RemoveRoleFromUser(UserId::generate(), $user->getId(), $requested->getId());
                $handler = new RemoveRoleFromUserHandler(
                    $userRepository,
                    $roleRepository,
                    new FixedClock(self::NOW),
                    $unitOfWork,
                    $eventDispatcher
                );
                if ($assigning) {
                    $command = new AssignRoleToUser(UserId::generate(), $user->getId(), $requested->getId());
                    $handler = new AssignRoleToUserHandler(
                        $userRepository,
                        $roleRepository,
                        new FixedClock(self::NOW),
                        $unitOfWork,
                        $eventDispatcher
                    );
                }

                $failure = $this->captureFailure($handler, $command);

                self::assertInstanceOf(LogicException::class, $failure);
                self::assertSame($user, $userRepository->getById($user->getId()));
                $this->assertFailure($eventDispatcher, $command, $failure);
            }
        }
    }

    public function test_stale_reference_or_compare_and_replace_loss_rolls_back_without_success(): void
    {
        foreach ([true, false] as $assigning) {
            foreach (['no-op', 'change', 'compare'] as $case) {
                $unitOfWork = new InMemoryUnitOfWork();
                $role = $this->role();
                $initialRoles = [];
                if (($case === 'no-op') === $assigning) {
                    $initialRoles = [$role->getId()];
                }

                $user = UserFixture::withRoleAssignments($initialRoles, 7);
                $invalidate = static function () use ($unitOfWork, $role): void {
                    $unitOfWork->authorizationReferenceState()->removeRole($role->getId());
                };
                $userRepository = new InMemoryUserRepository(
                    $unitOfWork,
                    replaceRoleAssignmentsSucceeds: $case !== 'compare',
                    beforeReplaceRoleAssignments: $case === 'change' ? $invalidate : null,
                    beforeValidateRoleAssignmentReference: $case === 'no-op' ? $invalidate : null
                );
                $roleRepository = new InMemoryRoleRepository($unitOfWork);
                $roleRepository->add($role);
                $userRepository->add($user);
                $eventDispatcher = new InMemoryEventDispatcher();
                [$handler, $command] = $this->operation(
                    $assigning,
                    $userRepository,
                    $roleRepository,
                    $unitOfWork,
                    $eventDispatcher,
                    $user,
                    $role
                );
                $failure = $this->captureFailure($handler, $command);

                self::assertInstanceOf(StateConflict::class, $failure);
                self::assertSame($user, $userRepository->getById($user->getId()));
                self::assertSame(7, $user->getAuthorizationAssignmentRevision());
                $this->assertFailure($eventDispatcher, $command, $failure);
            }
        }
    }

    public function test_dependency_failures_rethrow_the_identical_throwable(): void
    {
        foreach ([true, false] as $assigning) {
            foreach (['user', 'role', 'clock'] as $dependency) {
                $failure = new RuntimeException('Dependency failed.');
                $unitOfWork = new InMemoryUnitOfWork();
                $user = UserFixture::withRoleAssignments([], 0);
                $userRepository = new InMemoryUserRepository(
                    $unitOfWork,
                    getByIdFailure: $dependency === 'user' ? $failure : null
                );
                $userRepository->add($user);
                $role = $this->role();
                $roleRepository = new ControllableRoleRepository(
                    $role,
                    getFailure: $dependency === 'role' ? $failure : null
                );
                $clock = $dependency === 'clock' ? new readonly class ($failure) implements Clock {
                    public function __construct(private RuntimeException $failure)
                    {
                    }

                    public function now(): DateTimeImmutable
                    {
                        throw $this->failure;
                    }
                } : new FixedClock(self::NOW);
                $eventDispatcher = new InMemoryEventDispatcher();
                $command = new RemoveRoleFromUser(UserId::generate(), $user->getId(), $role->getId());
                $handler = new RemoveRoleFromUserHandler(
                    $userRepository,
                    $roleRepository,
                    $clock,
                    $unitOfWork,
                    $eventDispatcher
                );
                if ($assigning) {
                    $command = new AssignRoleToUser(UserId::generate(), $user->getId(), $role->getId());
                    $handler = new AssignRoleToUserHandler(
                        $userRepository,
                        $roleRepository,
                        $clock,
                        $unitOfWork,
                        $eventDispatcher
                    );
                }

                self::assertSame($failure, $this->captureFailure($handler, $command));
                $this->assertFailure($eventDispatcher, $command, $failure);
            }
        }
    }

    public function test_messages_round_trip_and_reject_missing_data(): void
    {
        self::assertSame(AssignRoleToUser::class, AssignRoleToUserHandler::commandRegistration());
        self::assertSame(RemoveRoleFromUser::class, RemoveRoleFromUserHandler::commandRegistration());
        $actor = UserId::generate();
        $target = UserId::generate();
        $role = RoleId::generate();
        $now = new DateTimeImmutable(self::NOW);
        $messages = [
            new AssignRoleToUser($actor, $target, $role),
            new RemoveRoleFromUser($actor, $target, $role),
            new RoleAssignedToUser($actor, $target, $role, $now),
            new RoleRemovedFromUser($actor, $target, $role, $now)
        ];
        foreach ($messages as $message) {
            self::assertEquals($message, $message::fromArray($message->toArray()));
            self::assertSame($actor, $message->getActorId());
            self::assertSame($target, $message->getTargetUserId());
            self::assertSame($role, $message->getRoleId());
        }

        self::assertSame($now, $messages[2]->getAssignedAt());
        self::assertSame($now, $messages[3]->getRemovedAt());

        $cases = [
            [AssignRoleToUser::class, ['actor_id', 'target_user_id', 'role_id']],
            [RemoveRoleFromUser::class, ['actor_id', 'target_user_id', 'role_id']],
            [RoleAssignedToUser::class, ['actor_id', 'target_user_id', 'role_id', 'assigned_at']],
            [RoleRemovedFromUser::class, ['actor_id', 'target_user_id', 'role_id', 'removed_at']]
        ];
        foreach ($cases as [$type, $keys]) {
            foreach ($keys as $missing) {
                $data = [
                    'actor_id'       => $actor->toString(),
                    'target_user_id' => $target->toString(),
                    'role_id'        => $role->toString(),
                    'assigned_at'    => self::NOW,
                    'removed_at'     => self::NOW
                ];
                unset($data[$missing]);
                try {
                    $type::fromArray($data);
                    self::fail('Missing message data was accepted.');
                } catch (DomainException) {
                    self::addToAssertionCount(1);
                }
            }
        }
    }

    private function role(string $name = 'ROLE_EDITOR', bool $managed = false): Role
    {
        $id = RoleId::generate();
        $roleName = RoleName::fromString($name);
        $now = new DateTimeImmutable('2026-01-01T00:00:00+00:00');

        return $managed ? Role::defineManaged($id, $roleName, [], $now) : Role::define($id, $roleName, [], $now);
    }

    /**
     * @return array{AssignRoleToUserHandler|RemoveRoleFromUserHandler, AssignRoleToUser|RemoveRoleFromUser}
     */
    private function operation(
        bool $assigning,
        InMemoryUserRepository $userRepository,
        InMemoryRoleRepository $roleRepository,
        InMemoryUnitOfWork $unitOfWork,
        InMemoryEventDispatcher $eventDispatcher,
        User $user,
        Role $role
    ): array {
        if ($assigning) {
            return [
                new AssignRoleToUserHandler(
                    $userRepository,
                    $roleRepository,
                    new FixedClock(self::NOW),
                    $unitOfWork,
                    $eventDispatcher
                ),
                new AssignRoleToUser(UserId::generate(), $user->getId(), $role->getId())
            ];
        }

        return [
            new RemoveRoleFromUserHandler(
                $userRepository,
                $roleRepository,
                new FixedClock(self::NOW),
                $unitOfWork,
                $eventDispatcher
            ),
            new RemoveRoleFromUser(UserId::generate(), $user->getId(), $role->getId())
        ];
    }

    private function captureFailure(
        AssignRoleToUserHandler|RemoveRoleFromUserHandler $handler,
        AssignRoleToUser|RemoveRoleFromUser $command
    ): Throwable {
        try {
            $handler->handle(CommandMessage::create($command));
            self::fail('Command must fail.');
        } catch (Throwable $throwable) {
            return $throwable;
        }
    }

    private function assertFailure(
        InMemoryEventDispatcher $eventDispatcher,
        AssignRoleToUser|RemoveRoleFromUser $command,
        Throwable $failure
    ): void {
        self::assertCount(1, $eventDispatcher->events());
        $event = $eventDispatcher->events()[0];
        self::assertInstanceOf(CommandFailedEvent::class, $event);
        self::assertSame($command, $event->getCommand());
        $message = 'User role removal failed.';
        if ($command instanceof AssignRoleToUser) {
            $message = 'User role assignment failed.';
        }

        self::assertSame($message, $event->getErrorMessage());
        self::assertNotSame($failure->getMessage(), $event->getErrorMessage());
    }
}
