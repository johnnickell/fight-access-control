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
use Fight\AccessControl\Domain\AccessControl\User\Command\AssignRoleToUser;
use Fight\AccessControl\Domain\AccessControl\User\Command\RemoveRoleFromUser;
use Fight\AccessControl\Domain\AccessControl\User\Event\RoleAssignedToUser;
use Fight\AccessControl\Domain\AccessControl\User\Event\RoleRemovedFromUser;
use Fight\AccessControl\Domain\AccessControl\User\Exception\UserRoleAssignmentAuthorizationException;
use Fight\AccessControl\Domain\AccessControl\User\Exception\UserRoleAssignmentException;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\Role\Repository\ControllableRoleRepository;
use Fight\Test\AccessControl\Application\AccessControl\Role\Repository\InMemoryRoleRepository;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryUserRepository;
use Fight\Test\AccessControl\Application\AccessControl\User\Service\FixedUserRoleAssignmentAdministrationAuthorization;
use Fight\Test\AccessControl\Domain\AccessControl\User\UserFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

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

    public function test_assignment_persists_then_commits_then_publishes_without_changing_other_user_state(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $users = new InMemoryUserRepository($unitOfWork);
        $user = UserFixture::withRoleAssignments([RoleId::generate()], 5);
        $existingRoleId = $user->getRoleIds()[0];
        $users->add($user);
        $roles = new InMemoryRoleRepository($unitOfWork);
        $role = $this->role();
        $roles->add(Role::define(
            $existingRoleId,
            RoleName::fromString('ROLE_EXISTING'),
            [],
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        ));
        $roles->add($role);

        $actorId = UserId::generate();
        $authorization = new FixedUserRoleAssignmentAdministrationAuthorization(true);
        $events = new InMemoryEventDispatcher(
            static function ($event) use ($role, $unitOfWork, $user, $users): void {
                self::assertInstanceOf(RoleAssignedToUser::class, $event);
                self::assertTrue($unitOfWork->transactionCompleted);
                self::assertTrue($users->getById($user->getId())?->hasRole($role->getId()));
            }
        );
        $handler = new AssignRoleToUserHandler(
            $users,
            $roles,
            $authorization,
            new FixedClock(self::NOW),
            $unitOfWork,
            $events
        );
        $state = $user->getState();
        $authenticationVersion = $user->getAuthenticationVersion();
        $authenticationRevision = $user->getAuthenticationAuthorityRevision();

        $handler->handle(CommandMessage::create(new AssignRoleToUser($actorId, $user->getId(), $role->getId())));

        $stored = $users->getById($user->getId());
        self::assertInstanceOf(User::class, $stored);
        self::assertTrue($stored->hasRole($existingRoleId));
        self::assertTrue($stored->hasRole($role->getId()));
        self::assertSame(6, $stored->getAuthorizationAssignmentRevision());
        self::assertSame($state, $stored->getState());
        self::assertSame($authenticationVersion, $stored->getAuthenticationVersion());
        self::assertSame($authenticationRevision, $stored->getAuthenticationAuthorityRevision());
        self::assertSame(self::NOW, $stored->getUpdatedAt()->format(DATE_ATOM));
        self::assertSame(1, $unitOfWork->transactions);
        self::assertSame(1, $authorization->calls());
        self::assertTrue($authorization->lastActorId()?->equals($actorId));
        self::assertCount(1, $events->events());
        $event = $events->events()[0];
        self::assertInstanceOf(RoleAssignedToUser::class, $event);
        self::assertSame($actorId, $event->getActorId());
        self::assertSame($user->getId(), $event->getTargetUserId());
        self::assertSame($role->getId(), $event->getRoleId());
    }

    public function test_removal_persists_then_commits_then_publishes_one_element_change(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $roles = new InMemoryRoleRepository($unitOfWork);
        $role = $this->role();
        $remainingRole = $this->role('ROLE_REMAINING');
        $roles->add($role);
        $roles->add($remainingRole);

        $users = new InMemoryUserRepository($unitOfWork);
        $user = UserFixture::withRoleAssignments([$role->getId(), $remainingRole->getId()], 2);
        $users->add($user);
        $events = new InMemoryEventDispatcher(
            static function ($event) use ($role, $unitOfWork, $user, $users): void {
                self::assertInstanceOf(RoleRemovedFromUser::class, $event);
                self::assertTrue($unitOfWork->transactionCompleted);
                self::assertFalse($users->getById($user->getId())?->hasRole($role->getId()));
            }
        );
        $handler = new RemoveRoleFromUserHandler(
            $users,
            $roles,
            new FixedUserRoleAssignmentAdministrationAuthorization(true),
            new FixedClock(self::NOW),
            $unitOfWork,
            $events
        );

        $handler->handle(
            CommandMessage::create(new RemoveRoleFromUser(UserId::generate(), $user->getId(), $role->getId()))
        );

        $stored = $users->getById($user->getId());
        self::assertInstanceOf(User::class, $stored);
        self::assertFalse($stored->hasRole($role->getId()));
        self::assertTrue($stored->hasRole($remainingRole->getId()));
        self::assertSame(3, $stored->getAuthorizationAssignmentRevision());
        self::assertSame(1, $unitOfWork->transactions);
        self::assertCount(1, $events->events());
    }

    public function test_commands_and_success_events_round_trip_with_all_accessors(): void
    {
        self::assertSame(AssignRoleToUser::class, AssignRoleToUserHandler::commandRegistration());
        self::assertSame(RemoveRoleFromUser::class, RemoveRoleFromUserHandler::commandRegistration());

        $actorId = UserId::generate();
        $targetUserId = UserId::generate();
        $roleId = RoleId::generate();
        $assignedAt = new DateTimeImmutable(self::NOW);
        $removedAt = new DateTimeImmutable('2026-08-23T13:00:00+00:00');
        $assign = new AssignRoleToUser($actorId, $targetUserId, $roleId);
        $remove = new RemoveRoleFromUser($actorId, $targetUserId, $roleId);
        $assigned = new RoleAssignedToUser($actorId, $targetUserId, $roleId, $assignedAt);
        $removed = new RoleRemovedFromUser($actorId, $targetUserId, $roleId, $removedAt);

        self::assertEquals($assign, AssignRoleToUser::fromArray($assign->toArray()));
        self::assertEquals($remove, RemoveRoleFromUser::fromArray($remove->toArray()));
        self::assertEquals($assigned, RoleAssignedToUser::fromArray($assigned->toArray()));
        self::assertEquals($removed, RoleRemovedFromUser::fromArray($removed->toArray()));
        self::assertSame($actorId, $assign->getActorId());
        self::assertSame($targetUserId, $assign->getTargetUserId());
        self::assertSame($roleId, $assign->getRoleId());
        self::assertSame($actorId, $remove->getActorId());
        self::assertSame($targetUserId, $remove->getTargetUserId());
        self::assertSame($roleId, $remove->getRoleId());
        self::assertSame($actorId, $assigned->getActorId());
        self::assertSame($targetUserId, $assigned->getTargetUserId());
        self::assertSame($roleId, $assigned->getRoleId());
        self::assertSame($assignedAt, $assigned->getAssignedAt());
        self::assertSame($actorId, $removed->getActorId());
        self::assertSame($targetUserId, $removed->getTargetUserId());
        self::assertSame($roleId, $removed->getRoleId());
        self::assertSame($removedAt, $removed->getRemovedAt());
    }

    public function test_missing_command_and_event_data_is_rejected(): void
    {
        $cases = [
            [AssignRoleToUser::class, ['actor_id', 'target_user_id', 'role_id']],
            [RemoveRoleFromUser::class, ['actor_id', 'target_user_id', 'role_id']],
            [RoleAssignedToUser::class, ['actor_id', 'target_user_id', 'role_id', 'assigned_at']],
            [RoleRemovedFromUser::class, ['actor_id', 'target_user_id', 'role_id', 'removed_at']],
        ];

        foreach ($cases as [$type, $keys]) {
            foreach ($keys as $missing) {
                $data = [
                    'actor_id' => 'c3bc62b6-b87c-4371-b585-c47a059878f1',
                    'target_user_id' => 'edb053fd-17d7-49c7-9357-7e4835de9410',
                    'role_id' => '370f0da6-a3ee-4d27-9ef7-79d8fb511deb',
                    'assigned_at' => self::NOW,
                    'removed_at' => self::NOW,
                ];
                unset($data[$missing]);

                try {
                    $type::fromArray($data);
                    self::fail('Missing role-assignment message data was accepted.');
                } catch (DomainException) {
                    self::addToAssertionCount(1);
                }
            }
        }
    }

    public function test_authorization_denial_prevents_both_mutations_and_rethrows_the_same_failure(): void
    {
        foreach ([true, false] as $assigning) {
            $unitOfWork = new InMemoryUnitOfWork();
            $role = $this->role();
            $user = UserFixture::withRoleAssignments($assigning ? [] : [$role->getId()], 3);
            $users = new InMemoryUserRepository($unitOfWork);
            $users->add($user);
            $roles = new InMemoryRoleRepository($unitOfWork);
            $roles->add($role);
            $events = new InMemoryEventDispatcher();
            $authorization = new FixedUserRoleAssignmentAdministrationAuthorization(false);
            $handler = $assigning ? new AssignRoleToUserHandler(
                $users,
                $roles,
                $authorization,
                new FixedClock(self::NOW),
                $unitOfWork,
                $events
            ) : new RemoveRoleFromUserHandler(
                $users,
                $roles,
                $authorization,
                new FixedClock(self::NOW),
                $unitOfWork,
                $events
            );
            if ($assigning) {
                $command = new AssignRoleToUser(UserId::generate(), $user->getId(), $role->getId());
            } else {
                $command = new RemoveRoleFromUser(UserId::generate(), $user->getId(), $role->getId());
            }

            try {
                $handler->handle(CommandMessage::create($command));
                self::fail('An unauthorized User role-assignment mutation was accepted.');
            } catch (UserRoleAssignmentAuthorizationException $failure) {
                self::assertSame(
                    'User role-assignment administration is not authorized.',
                    $failure->getMessage()
                );
                self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
                $stored = $users->getById($user->getId());
                self::assertInstanceOf(User::class, $stored);
                self::assertSame($assigning ? [] : [$role->getId()], $stored->getRoleIds());
                self::assertSame(3, $stored->getAuthorizationAssignmentRevision());
            }
        }
    }

    public function test_missing_user_and_dangling_role_are_rejected_for_both_commands(): void
    {
        foreach ([true, false] as $assigning) {
            foreach ([true, false] as $userExists) {
                $unitOfWork = new InMemoryUnitOfWork();
                $users = new InMemoryUserRepository($unitOfWork);
                $user = UserFixture::withRoleAssignments([], 0);
                if ($userExists) {
                    $users->add($user);
                }

                $roles = new InMemoryRoleRepository($unitOfWork);
                $events = new InMemoryEventDispatcher();
                $roleId = RoleId::generate();
                $handler = $assigning ? new AssignRoleToUserHandler(
                    $users,
                    $roles,
                    new FixedUserRoleAssignmentAdministrationAuthorization(true),
                    new FixedClock(self::NOW),
                    $unitOfWork,
                    $events
                ) : new RemoveRoleFromUserHandler(
                    $users,
                    $roles,
                    new FixedUserRoleAssignmentAdministrationAuthorization(true),
                    new FixedClock(self::NOW),
                    $unitOfWork,
                    $events
                );
                if ($assigning) {
                    $command = new AssignRoleToUser(UserId::generate(), $user->getId(), $roleId);
                } else {
                    $command = new RemoveRoleFromUser(UserId::generate(), $user->getId(), $roleId);
                }

                try {
                    $handler->handle(CommandMessage::create($command));
                    self::fail('A dangling User role-assignment mutation was accepted.');
                } catch (UserRoleAssignmentException) {
                    self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
                    self::assertSame([], $users->getById($user->getId())?->getRoleIds() ?? []);
                }

                if (!$userExists) {
                    break;
                }
            }
        }
    }

    public function test_duplicate_assignment_absent_removal_and_cas_loss_leave_authority_unchanged(): void
    {
        foreach (['duplicate', 'absent', 'assign-cas', 'remove-cas'] as $case) {
            $assigning = $case === 'duplicate' || $case === 'assign-cas';
            $hasRole = $case === 'duplicate' || $case === 'remove-cas';
            $role = $this->role();
            $unitOfWork = new InMemoryUnitOfWork();
            $users = new InMemoryUserRepository(
                $unitOfWork,
                replaceRoleAssignmentsSucceeds: !str_ends_with($case, '-cas')
            );
            $user = UserFixture::withRoleAssignments($hasRole ? [$role->getId()] : [], 7);
            $users->add($user);
            $roles = new InMemoryRoleRepository($unitOfWork);
            $roles->add($role);
            $events = new InMemoryEventDispatcher();
            $handler = $assigning ? new AssignRoleToUserHandler(
                $users,
                $roles,
                new FixedUserRoleAssignmentAdministrationAuthorization(true),
                new FixedClock(self::NOW),
                $unitOfWork,
                $events
            ) : new RemoveRoleFromUserHandler(
                $users,
                $roles,
                new FixedUserRoleAssignmentAdministrationAuthorization(true),
                new FixedClock(self::NOW),
                $unitOfWork,
                $events
            );
            if ($assigning) {
                $command = new AssignRoleToUser(UserId::generate(), $user->getId(), $role->getId());
            } else {
                $command = new RemoveRoleFromUser(UserId::generate(), $user->getId(), $role->getId());
            }

            try {
                $handler->handle(CommandMessage::create($command));
                self::fail('An invalid or stale User role-assignment mutation was accepted.');
            } catch (UserRoleAssignmentException $failure) {
                self::assertNotSame('', $failure->getMessage());
                self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
                $stored = $users->getById($user->getId());
                self::assertInstanceOf(User::class, $stored);
                self::assertSame($hasRole, $stored->hasRole($role->getId()));
                self::assertSame(7, $stored->getAuthorizationAssignmentRevision());
            }
        }
    }

    public function test_assignment_fails_when_the_role_loses_authority_at_the_final_persistence_boundary(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $user = UserFixture::withRoleAssignments([], 4);
        $users = new InMemoryUserRepository(
            $unitOfWork,
            beforeReplaceRoleAssignments: static function () use ($unitOfWork): void {
                self::assertTrue($unitOfWork->authorizationReferenceState()->isReferenceFenceHeld());
            }
        );
        $users->add($user);

        $role = $this->role();
        $roles = new ControllableRoleRepository($role, roleRemainsAuthoritative: false);
        $events = new InMemoryEventDispatcher();
        $handler = new AssignRoleToUserHandler(
            $users,
            $roles,
            new FixedUserRoleAssignmentAdministrationAuthorization(true),
            new FixedClock(self::NOW),
            $unitOfWork,
            $events
        );

        try {
            $handler->handle(
                CommandMessage::create(
                    new AssignRoleToUser(UserId::generate(), $user->getId(), $role->getId())
                )
            );
            self::fail('A role that lost authority before persistence was assigned.');
        } catch (UserRoleAssignmentException) {
            $stored = $users->getById($user->getId());
            self::assertInstanceOf(User::class, $stored);
            self::assertFalse($stored->hasRole($role->getId()));
            self::assertSame(4, $stored->getAuthorizationAssignmentRevision());
            self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
        }
    }

    public function test_dependency_failures_are_rethrown_by_identity_and_reported(): void
    {
        $failures = [
            'authorization' => new RuntimeException('authorization failed'),
            'user repository' => new RuntimeException('user repository failed'),
            'role repository' => new RuntimeException('role repository failed'),
            'clock' => new RuntimeException('clock failed'),
        ];

        foreach ($failures as $dependency => $expectedFailure) {
            $unitOfWork = new InMemoryUnitOfWork();
            $role = $this->role();
            $user = UserFixture::withRoleAssignments([$role->getId()], 9);
            $users = new InMemoryUserRepository(
                $unitOfWork,
                getByIdFailure: $dependency === 'user repository' ? $expectedFailure : null
            );
            $users->add($user);
            if ($dependency === 'role repository') {
                $roles = new ControllableRoleRepository($role, getFailure: $expectedFailure);
            } else {
                $roles = new ControllableRoleRepository($role);
            }

            $authorization = new FixedUserRoleAssignmentAdministrationAuthorization(
                true,
                $dependency === 'authorization' ? $expectedFailure : null
            );
            $clock = $dependency === 'clock' ? new readonly class ($expectedFailure) implements Clock {
                public function __construct(private RuntimeException $failure)
                {
                }

                public function now(): DateTimeImmutable
                {
                    throw $this->failure;
                }
            } : new FixedClock(self::NOW);
            $events = new InMemoryEventDispatcher();
            $handler = new RemoveRoleFromUserHandler(
                $users,
                $roles,
                $authorization,
                $clock,
                $unitOfWork,
                $events
            );

            try {
                $handler->handle(
                    CommandMessage::create(
                        new RemoveRoleFromUser(UserId::generate(), $user->getId(), $role->getId())
                    )
                );
                self::fail('A dependency failure was swallowed.');
            } catch (RuntimeException $actualFailure) {
                self::assertSame($expectedFailure, $actualFailure);
                self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
                if ($dependency !== 'user repository') {
                    $stored = $users->getById($user->getId());
                    self::assertInstanceOf(User::class, $stored);
                    self::assertTrue($stored->hasRole($role->getId()));
                    self::assertSame(9, $stored->getAuthorizationAssignmentRevision());
                }
            }
        }
    }

    private function role(string $name = 'ROLE_EDITOR'): Role
    {
        return Role::define(
            RoleId::generate(),
            RoleName::fromString($name),
            [],
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        );
    }
}
