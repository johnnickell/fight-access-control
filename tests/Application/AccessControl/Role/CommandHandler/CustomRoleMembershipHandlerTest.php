<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Role\CommandHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\Role\CommandHandler\GrantPermissionToCustomRoleHandler;
use Fight\AccessControl\Application\AccessControl\Role\CommandHandler\RemoveCustomRoleHandler;
use Fight\AccessControl\Application\AccessControl\Role\CommandHandler\RevokePermissionFromCustomRoleHandler;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\AccessControl\Domain\AccessControl\Role\Command\GrantPermissionToCustomRole;
use Fight\AccessControl\Domain\AccessControl\Role\Command\RemoveCustomRole;
use Fight\AccessControl\Domain\AccessControl\Role\Command\RevokePermissionFromCustomRole;
use Fight\AccessControl\Domain\AccessControl\Role\Event\CustomRolePermissionGranted;
use Fight\AccessControl\Domain\AccessControl\Role\Event\CustomRolePermissionRevoked;
use Fight\AccessControl\Domain\AccessControl\Role\Event\CustomRoleRemoved;
use Fight\AccessControl\Domain\AccessControl\Role\Exception\CustomRoleException;
use Fight\AccessControl\Domain\AccessControl\Role\Exception\RoleAdministrationAuthorizationException;
use Fight\AccessControl\Domain\AccessControl\Role\Role;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\Role\RoleName;
use Fight\AccessControl\Domain\AccessControl\Role\RoleRepository;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\Command;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\Permission\Repository\InMemoryPermissionRepository;
use Fight\Test\AccessControl\Application\AccessControl\Role\Repository\ControllableRoleRepository;
use Fight\Test\AccessControl\Application\AccessControl\Role\Repository\InMemoryRoleRepository;
use Fight\Test\AccessControl\Application\AccessControl\Role\Service\FixedRoleAdministrationAuthorization;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryUserRepository;
use Fight\Test\AccessControl\Domain\AccessControl\User\UserFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

#[CoversClass(GrantPermissionToCustomRoleHandler::class)]
#[CoversClass(RevokePermissionFromCustomRoleHandler::class)]
#[CoversClass(RemoveCustomRoleHandler::class)]
#[CoversClass(GrantPermissionToCustomRole::class)]
#[CoversClass(RevokePermissionFromCustomRole::class)]
#[CoversClass(RemoveCustomRole::class)]
#[CoversClass(CustomRolePermissionGranted::class)]
#[CoversClass(CustomRolePermissionRevoked::class)]
#[CoversClass(CustomRoleRemoved::class)]
final class CustomRoleMembershipHandlerTest extends TestCase
{
    public function test_it_grants_and_revokes_permission_membership_before_commit_and_success_event(): void
    {
        $permission = $this->permission();
        $role = $this->role();
        $unitOfWork = new InMemoryUnitOfWork();
        $roles = new InMemoryRoleRepository($unitOfWork);
        $roles->add($role);

        $permissions = new InMemoryPermissionRepository($unitOfWork);
        $permissions->add($permission);

        $grantedAt = new DateTimeImmutable('2026-08-23T12:00:00+00:00');
        $events = new InMemoryEventDispatcher(function () use ($roles, $role, $permission, $unitOfWork): void {
            self::assertTrue($roles->getById($role->getId())?->hasPermission($permission->getId()));
            self::assertTrue($unitOfWork->transactionCompleted);
        });
        $grant = new GrantPermissionToCustomRole($this->actorId(), $role->getId(), $permission->getId());

        $this->grantHandler($roles, $permissions, $unitOfWork, $events, now: $grantedAt)
            ->handle(CommandMessage::create($grant));

        self::assertSame(GrantPermissionToCustomRole::class, GrantPermissionToCustomRoleHandler::commandRegistration());
        self::assertSame(1, $unitOfWork->transactions);
        self::assertInstanceOf(CustomRolePermissionGranted::class, $events->events()[0]);
        $granted = $events->events()[0];
        self::assertSame($grant->getActorId(), $granted->getActorId());
        self::assertSame($grant->getRoleId(), $granted->getRoleId());
        self::assertSame($grant->getPermissionId(), $granted->getPermissionId());
        self::assertSame($grantedAt, $granted->getGrantedAt());

        $revokedAt = new DateTimeImmutable('2026-08-23T13:00:00+00:00');
        $events = new InMemoryEventDispatcher(function () use ($roles, $role, $permission, $unitOfWork): void {
            self::assertFalse($roles->getById($role->getId())?->hasPermission($permission->getId()));
            self::assertTrue($unitOfWork->transactionCompleted);
        });
        $revoke = new RevokePermissionFromCustomRole($this->actorId(), $role->getId(), $permission->getId());

        $this->revokeHandler($roles, $permissions, $unitOfWork, $events, $revokedAt)
            ->handle(CommandMessage::create($revoke));

        self::assertSame(
            RevokePermissionFromCustomRole::class,
            RevokePermissionFromCustomRoleHandler::commandRegistration()
        );
        self::assertSame(2, $unitOfWork->transactions);
        self::assertInstanceOf(CustomRolePermissionRevoked::class, $events->events()[0]);
        $revoked = $events->events()[0];
        self::assertSame($revoke->getActorId(), $revoked->getActorId());
        self::assertSame($revoke->getRoleId(), $revoked->getRoleId());
        self::assertSame($revoke->getPermissionId(), $revoked->getPermissionId());
        self::assertSame($revokedAt, $revoked->getRevokedAt());
    }

    public function test_it_removes_only_an_unreferenced_custom_role_before_post_commit_success(): void
    {
        $role = $this->role();
        $unitOfWork = new InMemoryUnitOfWork();
        $roles = new InMemoryRoleRepository($unitOfWork);
        $roles->add($role);

        $removedAt = new DateTimeImmutable('2026-08-23T14:00:00+00:00');
        $events = new InMemoryEventDispatcher(function () use ($roles, $role, $unitOfWork): void {
            self::assertNull($roles->getById($role->getId()));
            self::assertTrue($unitOfWork->transactionCompleted);
        });
        $command = new RemoveCustomRole($this->actorId(), $role->getId());
        $handler = $this->removeHandler($roles, new InMemoryUserRepository(), $unitOfWork, $events, now: $removedAt);

        $handler->handle(CommandMessage::create($command));

        self::assertSame(RemoveCustomRole::class, RemoveCustomRoleHandler::commandRegistration());
        self::assertSame(1, $unitOfWork->transactions);
        self::assertInstanceOf(CustomRoleRemoved::class, $events->events()[0]);
        $removed = $events->events()[0];
        self::assertSame($command->getActorId(), $removed->getActorId());
        self::assertSame($command->getRoleId(), $removed->getRoleId());
        self::assertSame($removedAt, $removed->getRemovedAt());
    }

    public function test_membership_final_boundary_rejects_a_permission_removed_after_handler_read(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $permission = $this->permission();
        $permissions = new InMemoryPermissionRepository($unitOfWork);
        $permissions->add($permission);

        $roles = null;
        $roles = new InMemoryRoleRepository(
            $unitOfWork,
            beforeReplace: static function () use (&$roles, $permission, $permissions): void {
                self::assertInstanceOf(InMemoryRoleRepository::class, $roles);
                self::assertTrue($permissions->remove($permission));
            }
        );
        $role = $this->role();
        $roles->add($role);
        $events = new InMemoryEventDispatcher();
        $command = new GrantPermissionToCustomRole($this->actorId(), $role->getId(), $permission->getId());

        $failure = $this->captureFailure(
            $this->grantHandler($roles, $permissions, $unitOfWork, $events)->handle(...),
            $command
        );

        self::assertInstanceOf(CustomRoleException::class, $failure);
        self::assertSame('The custom role changed concurrently.', $failure->getMessage());
        self::assertInstanceOf(Permission::class, $permissions->getById($permission->getId()));
        self::assertFalse($roles->getById($role->getId())?->hasPermission($permission->getId()));
        self::assertFalse($unitOfWork->transactionCompleted);
        $this->assertCommandFailure($events, $command, $failure);
    }

    public function test_authorization_denial_prevents_all_three_mutations(): void
    {
        $permission = $this->permission();
        $role = $this->role();
        $commands = [
            new GrantPermissionToCustomRole($this->actorId(), $role->getId(), $permission->getId()),
            new RevokePermissionFromCustomRole($this->actorId(), $role->getId(), $permission->getId()),
            new RemoveCustomRole($this->actorId(), $role->getId()),
        ];

        foreach ($commands as $command) {
            $roles = new InMemoryRoleRepository();
            $roles->add($role);
            $permissions = new InMemoryPermissionRepository();
            $permissions->add($permission);
            $events = new InMemoryEventDispatcher();
            $unitOfWork = new InMemoryUnitOfWork();
            $authorization = new FixedRoleAdministrationAuthorization(false);
            $handler = match ($command::class) {
                GrantPermissionToCustomRole::class => $this->grantHandler(
                    $roles,
                    $permissions,
                    $unitOfWork,
                    $events,
                    $authorization
                ),
                RevokePermissionFromCustomRole::class => $this->revokeHandler(
                    $roles,
                    $permissions,
                    $unitOfWork,
                    $events,
                    authorization: $authorization
                ),
                RemoveCustomRole::class => $this->removeHandler(
                    $roles,
                    new InMemoryUserRepository(),
                    $unitOfWork,
                    $events,
                    $authorization
                ),
            };

            $failure = $this->captureFailure($handler->handle(...), $command);

            self::assertInstanceOf(RoleAdministrationAuthorizationException::class, $failure);
            self::assertSame($role, $roles->getById($role->getId()));
            $this->assertCommandFailure($events, $command, $failure);
        }
    }

    public function test_membership_rejects_missing_roles_permissions_and_invalid_membership_without_mutation(): void
    {
        $permission = $this->permission();
        $permissionId = $permission->getId();
        $role = $this->role([$permissionId]);
        $cases = [
            [
                new GrantPermissionToCustomRole($this->actorId(), RoleId::generate(), $permissionId),
                null,
                $permission,
            ],
            [
                new GrantPermissionToCustomRole($this->actorId(), $role->getId(), PermissionId::generate()),
                $role,
                null,
            ],
            [new GrantPermissionToCustomRole($this->actorId(), $role->getId(), $permissionId), $role, $permission],
            [
                new RevokePermissionFromCustomRole($this->actorId(), RoleId::generate(), $permissionId),
                null,
                $permission,
            ],
            [
                new RevokePermissionFromCustomRole($this->actorId(), $role->getId(), PermissionId::generate()),
                $role,
                null,
            ],
            [
                new RevokePermissionFromCustomRole($this->actorId(), $role->getId(), $permissionId),
                $this->role(),
                $permission,
            ],
        ];

        foreach ($cases as [$command, $seedRole, $seedPermission]) {
            $unitOfWork = new InMemoryUnitOfWork();
            $permissions = new InMemoryPermissionRepository($unitOfWork);
            if ($seedPermission instanceof Permission) {
                $permissions->add($seedPermission);
            }

            if ($seedRole instanceof Role) {
                foreach ($seedRole->getPermissionIds() as $seedPermissionId) {
                    if (!$permissions->getById($seedPermissionId) instanceof Permission) {
                        $permissions->add(Permission::define(
                            $seedPermissionId,
                            PermissionName::fromString('EXISTING_PERMISSION'),
                            new DateTimeImmutable('2026-08-22T00:00:00+00:00')
                        ));
                    }
                }
            }

            $roles = new InMemoryRoleRepository($unitOfWork);
            if ($seedRole instanceof Role) {
                $roles->add($seedRole);
            }

            $events = new InMemoryEventDispatcher();
            if ($command instanceof GrantPermissionToCustomRole) {
                $handler = $this->grantHandler($roles, $permissions, $unitOfWork, $events);
            } else {
                $handler = $this->revokeHandler($roles, $permissions, $unitOfWork, $events);
            }

            $failure = $this->captureFailure($handler->handle(...), $command);

            self::assertInstanceOf(CustomRoleException::class, $failure);
            $this->assertCommandFailure($events, $command, $failure);
        }
    }

    public function test_managed_roles_reject_membership_mutation_and_removal(): void
    {
        $permission = $this->permission();
        $role = Role::defineManaged(
            RoleId::generate(),
            RoleName::fromString('ROLE_MANAGED'),
            [$permission->getId()],
            new DateTimeImmutable('2026-08-22T00:00:00+00:00')
        );
        $commands = [
            new GrantPermissionToCustomRole($this->actorId(), $role->getId(), PermissionId::generate()),
            new RevokePermissionFromCustomRole($this->actorId(), $role->getId(), $permission->getId()),
            new RemoveCustomRole($this->actorId(), $role->getId()),
        ];

        foreach ($commands as $command) {
            $unitOfWork = new InMemoryUnitOfWork();
            $permissions = new InMemoryPermissionRepository($unitOfWork);
            $permissions->add($permission);
            if ($command instanceof GrantPermissionToCustomRole) {
                $permissions->add(Permission::define(
                    $command->getPermissionId(),
                    PermissionName::fromString('SECOND_PERMISSION'),
                    new DateTimeImmutable('2026-08-22T00:00:00+00:00')
                ));
            }

            $roles = new InMemoryRoleRepository($unitOfWork);
            $roles->add($role);

            $events = new InMemoryEventDispatcher();
            $handler = match ($command::class) {
                GrantPermissionToCustomRole::class => $this->grantHandler(
                    $roles,
                    $permissions,
                    $unitOfWork,
                    $events
                ),
                RevokePermissionFromCustomRole::class => $this->revokeHandler(
                    $roles,
                    $permissions,
                    $unitOfWork,
                    $events
                ),
                RemoveCustomRole::class => $this->removeHandler(
                    $roles,
                    new InMemoryUserRepository(),
                    new InMemoryUnitOfWork(),
                    $events
                ),
            };

            $failure = $this->captureFailure($handler->handle(...), $command);

            self::assertInstanceOf(CustomRoleException::class, $failure);
            self::assertSame($role, $roles->getById($role->getId()));
            $this->assertCommandFailure($events, $command, $failure);
        }
    }

    public function test_concurrent_membership_replacement_and_removal_loss_are_rejected(): void
    {
        $permission = $this->permission();
        $roleWithPermission = $this->role([$permission->getId()]);
        $commands = [
            new GrantPermissionToCustomRole($this->actorId(), $this->role()->getId(), $permission->getId()),
            new RevokePermissionFromCustomRole($this->actorId(), $roleWithPermission->getId(), $permission->getId()),
            new RemoveCustomRole($this->actorId(), $this->role()->getId()),
        ];

        foreach ($commands as $command) {
            $seed = $command instanceof RevokePermissionFromCustomRole ? $roleWithPermission : $this->role();
            $roles = new ControllableRoleRepository(
                $seed,
                replaceSucceeds: false,
                removeSucceeds: false
            );
            $permissions = new InMemoryPermissionRepository();
            $permissions->add($permission);
            $events = new InMemoryEventDispatcher();
            $handler = match ($command::class) {
                GrantPermissionToCustomRole::class => $this->grantHandler(
                    $roles,
                    $permissions,
                    new InMemoryUnitOfWork(),
                    $events
                ),
                RevokePermissionFromCustomRole::class => $this->revokeHandler(
                    $roles,
                    $permissions,
                    new InMemoryUnitOfWork(),
                    $events
                ),
                RemoveCustomRole::class => $this->removeHandler(
                    $roles,
                    new InMemoryUserRepository(),
                    new InMemoryUnitOfWork(),
                    $events
                ),
            };

            $failure = $this->captureFailure($handler->handle(...), $command);

            self::assertInstanceOf(CustomRoleException::class, $failure);
            $this->assertCommandFailure($events, $command, $failure);
        }
    }

    public function test_removal_rejects_missing_and_live_user_referenced_roles(): void
    {
        $role = $this->role();
        $userRepository = new InMemoryUserRepository();
        $userRepository->add(UserFixture::withRoleAssignments([$role->getId()], 1));
        foreach ([new InMemoryRoleRepository(), $this->rolesContaining($role)] as $roles) {
            $events = new InMemoryEventDispatcher();
            $command = new RemoveCustomRole($this->actorId(), $role->getId());
            $handler = $this->removeHandler(
                $roles,
                $userRepository,
                new InMemoryUnitOfWork(),
                $events
            );

            $failure = $this->captureFailure($handler->handle(...), $command);

            self::assertInstanceOf(CustomRoleException::class, $failure);
            $this->assertCommandFailure($events, $command, $failure);
        }
    }

    public function test_removal_fails_when_a_user_assignment_appears_at_the_final_persistence_boundary(): void
    {
        $role = $this->role();
        $unitOfWork = new InMemoryUnitOfWork();
        $referenceChecks = 0;
        $users = new InMemoryUserRepository(
            $unitOfWork,
            beforeHasRoleAssignment: static function () use (&$referenceChecks): bool {
                ++$referenceChecks;

                return false;
            }
        );
        $roles = new InMemoryRoleRepository(
            $unitOfWork,
            beforeRemove: static function () use ($role, $unitOfWork, $users): void {
                self::assertTrue($unitOfWork->authorizationReferenceState()->isReferenceFenceHeld());
                $users->add(UserFixture::withRoleAssignments([$role->getId()], 1));
            }
        );
        $roles->add($role);

        $events = new InMemoryEventDispatcher();
        $command = new RemoveCustomRole($this->actorId(), $role->getId());

        $failure = $this->captureFailure(
            $this->removeHandler($roles, $users, $unitOfWork, $events)->handle(...),
            $command
        );

        self::assertInstanceOf(CustomRoleException::class, $failure);
        self::assertSame(1, $referenceChecks);
        self::assertSame($role, $roles->getById($role->getId()));
        $this->assertCommandFailure($events, $command, $failure);
    }

    public function test_each_handler_rethrows_the_identical_repository_failure(): void
    {
        $expected = new RuntimeException('role read failed');
        $permission = $this->permission();
        $permissions = new InMemoryPermissionRepository();
        $permissions->add($permission);

        $commands = [
            new GrantPermissionToCustomRole($this->actorId(), RoleId::generate(), $permission->getId()),
            new RevokePermissionFromCustomRole($this->actorId(), RoleId::generate(), $permission->getId()),
            new RemoveCustomRole($this->actorId(), RoleId::generate()),
        ];

        foreach ($commands as $command) {
            $roles = new ControllableRoleRepository(getFailure: $expected);
            $events = new InMemoryEventDispatcher();
            $handler = match ($command::class) {
                GrantPermissionToCustomRole::class => $this->grantHandler(
                    $roles,
                    $permissions,
                    new InMemoryUnitOfWork(),
                    $events
                ),
                RevokePermissionFromCustomRole::class => $this->revokeHandler(
                    $roles,
                    $permissions,
                    new InMemoryUnitOfWork(),
                    $events
                ),
                RemoveCustomRole::class => $this->removeHandler(
                    $roles,
                    new InMemoryUserRepository(),
                    new InMemoryUnitOfWork(),
                    $events
                ),
            };

            $actual = $this->captureFailure($handler->handle(...), $command);

            self::assertSame($expected, $actual);
            $this->assertCommandFailure($events, $command, $expected);
        }
    }

    public function test_commands_and_events_round_trip_and_reject_missing_required_data(): void
    {
        $actorId = $this->actorId();
        $roleId = RoleId::generate();
        $permissionId = PermissionId::generate();
        $now = new DateTimeImmutable('2026-08-23T12:00:00+00:00');
        $messages = [
            new GrantPermissionToCustomRole($actorId, $roleId, $permissionId),
            new RevokePermissionFromCustomRole($actorId, $roleId, $permissionId),
            new RemoveCustomRole($actorId, $roleId),
            new CustomRolePermissionGranted($actorId, $roleId, $permissionId, $now),
            new CustomRolePermissionRevoked($actorId, $roleId, $permissionId, $now),
            new CustomRoleRemoved($actorId, $roleId, $now),
        ];

        $rejected = 0;
        foreach ($messages as $message) {
            self::assertEquals($message, $message::fromArray($message->toArray()));

            try {
                $message::fromArray([]);
                self::fail('Missing required message data must be rejected.');
            } catch (DomainException) {
                ++$rejected;
            }
        }

        self::assertSame(6, $rejected);
    }

    /** @param list<PermissionId> $permissionIds */
    private function role(array $permissionIds = []): Role
    {
        return Role::define(
            RoleId::fromString('018f0000-0000-7000-8000-000000000002'),
            RoleName::fromString('ROLE_SUPPORT'),
            $permissionIds,
            new DateTimeImmutable('2026-08-22T00:00:00+00:00')
        );
    }

    private function permission(): Permission
    {
        return Permission::define(
            PermissionId::fromString('018f0000-0000-7000-8000-000000000003'),
            PermissionName::fromString('READ_CASES'),
            new DateTimeImmutable('2026-08-22T00:00:00+00:00')
        );
    }

    private function actorId(): UserId
    {
        return UserId::fromString('018f0000-0000-7000-8000-000000000001');
    }

    private function rolesContaining(Role $role): InMemoryRoleRepository
    {
        $roles = new InMemoryRoleRepository();
        $roles->add($role);

        return $roles;
    }

    private function grantHandler(
        RoleRepository $roles,
        InMemoryPermissionRepository $permissions,
        InMemoryUnitOfWork $unitOfWork,
        InMemoryEventDispatcher $events,
        ?FixedRoleAdministrationAuthorization $authorization = null,
        DateTimeImmutable|string $now = '2026-08-23T12:00:00+00:00'
    ): GrantPermissionToCustomRoleHandler {
        return new GrantPermissionToCustomRoleHandler(
            $roles,
            $permissions,
            $authorization ?? new FixedRoleAdministrationAuthorization(true),
            new FixedClock($now),
            $unitOfWork,
            $events
        );
    }

    private function revokeHandler(
        RoleRepository $roles,
        InMemoryPermissionRepository $permissions,
        InMemoryUnitOfWork $unitOfWork,
        InMemoryEventDispatcher $events,
        DateTimeImmutable|string $now = '2026-08-23T12:00:00+00:00',
        ?FixedRoleAdministrationAuthorization $authorization = null
    ): RevokePermissionFromCustomRoleHandler {
        return new RevokePermissionFromCustomRoleHandler(
            $roles,
            $permissions,
            $authorization ?? new FixedRoleAdministrationAuthorization(true),
            new FixedClock($now),
            $unitOfWork,
            $events
        );
    }

    private function removeHandler(
        RoleRepository $roles,
        InMemoryUserRepository $users,
        InMemoryUnitOfWork $unitOfWork,
        InMemoryEventDispatcher $events,
        ?FixedRoleAdministrationAuthorization $authorization = null,
        DateTimeImmutable|string $now = '2026-08-23T12:00:00+00:00'
    ): RemoveCustomRoleHandler {
        return new RemoveCustomRoleHandler(
            $roles,
            $users,
            $authorization ?? new FixedRoleAdministrationAuthorization(true),
            new FixedClock($now),
            $unitOfWork,
            $events
        );
    }

    /** @param callable(CommandMessage): void $handle */
    private function captureFailure(callable $handle, Command $command): Throwable
    {
        try {
            $handle(CommandMessage::create($command));
            self::fail('The command must fail.');
        } catch (Throwable $throwable) {
            return $throwable;
        }
    }

    private function assertCommandFailure(
        InMemoryEventDispatcher $events,
        Command $command,
        Throwable $throwable
    ): void {
        self::assertCount(1, $events->events());
        self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
        self::assertSame($command, $events->events()[0]->getCommand());
        self::assertSame($throwable->getMessage(), $events->events()[0]->getErrorMessage());
    }
}
