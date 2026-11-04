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
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionRepository;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionTier;
use Fight\AccessControl\Domain\AccessControl\Role\Command\GrantPermissionToCustomRole;
use Fight\AccessControl\Domain\AccessControl\Role\Command\RemoveCustomRole;
use Fight\AccessControl\Domain\AccessControl\Role\Command\RevokePermissionFromCustomRole;
use Fight\AccessControl\Domain\AccessControl\Role\Event\CustomRolePermissionGranted;
use Fight\AccessControl\Domain\AccessControl\Role\Event\CustomRolePermissionRevoked;
use Fight\AccessControl\Domain\AccessControl\Role\Event\CustomRoleRemoved;
use Fight\AccessControl\Domain\AccessControl\Role\Exception\CustomRoleException;
use Fight\AccessControl\Domain\AccessControl\Role\Role;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\Role\RoleName;
use Fight\AccessControl\Domain\AccessControl\Role\RoleRepository;
use Fight\AccessControl\Domain\AccessControl\Role\RoleRevision;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\Command;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\Permission\Repository\InMemoryPermissionRepository;
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
        $roleRepository = new InMemoryRoleRepository($unitOfWork);
        $roleRepository->add($role);

        $permissionRepository = new InMemoryPermissionRepository($unitOfWork);
        $permissionRepository->add($permission);

        $grantedAt = new DateTimeImmutable('2026-08-23T12:00:00+00:00');
        $eventDispatcher = new InMemoryEventDispatcher(
            function () use ($roleRepository, $role, $permission, $unitOfWork): void {
                self::assertTrue($roleRepository->getById($role->getId())?->hasPermission($permission->getId()));
                self::assertTrue($unitOfWork->transactionCompleted);
            }
        );
        $grant = new GrantPermissionToCustomRole($this->actorId(), $role->getId(), $permission->getId());

        $this->grantHandler($roleRepository, $permissionRepository, $unitOfWork, $eventDispatcher, now: $grantedAt)
            ->handle(CommandMessage::create($grant));

        self::assertSame(GrantPermissionToCustomRole::class, GrantPermissionToCustomRoleHandler::commandRegistration());
        self::assertSame(1, $unitOfWork->transactions);
        self::assertInstanceOf(CustomRolePermissionGranted::class, $eventDispatcher->events()[0]);
        $granted = $eventDispatcher->events()[0];
        self::assertSame($grant->getActorId(), $granted->getActorId());
        self::assertSame($grant->getRoleId(), $granted->getRoleId());
        self::assertSame($grant->getPermissionId(), $granted->getPermissionId());
        self::assertSame($grantedAt, $granted->getGrantedAt());
        $grantedRole = $roleRepository->getById($role->getId());
        self::assertInstanceOf(Role::class, $grantedRole);
        self::assertTrue($role->getRevision()->next()->equals($grantedRole->getRevision()));

        $revokedAt = new DateTimeImmutable('2026-08-23T13:00:00+00:00');
        $eventDispatcher = new InMemoryEventDispatcher(
            function () use ($roleRepository, $role, $permission, $unitOfWork): void {
                self::assertFalse($roleRepository->getById($role->getId())?->hasPermission($permission->getId()));
                self::assertTrue($unitOfWork->transactionCompleted);
            }
        );
        $revoke = new RevokePermissionFromCustomRole($this->actorId(), $role->getId(), $permission->getId());

        $this->revokeHandler($roleRepository, $permissionRepository, $unitOfWork, $eventDispatcher, $revokedAt)
            ->handle(CommandMessage::create($revoke));

        self::assertSame(
            RevokePermissionFromCustomRole::class,
            RevokePermissionFromCustomRoleHandler::commandRegistration()
        );
        self::assertSame(2, $unitOfWork->transactions);
        self::assertInstanceOf(CustomRolePermissionRevoked::class, $eventDispatcher->events()[0]);
        $revoked = $eventDispatcher->events()[0];
        self::assertSame($revoke->getActorId(), $revoked->getActorId());
        self::assertSame($revoke->getRoleId(), $revoked->getRoleId());
        self::assertSame($revoke->getPermissionId(), $revoked->getPermissionId());
        self::assertSame($revokedAt, $revoked->getRevokedAt());
        $revokedRole = $roleRepository->getById($role->getId());
        self::assertInstanceOf(Role::class, $revokedRole);
        self::assertTrue($grantedRole->getRevision()->next()->equals($revokedRole->getRevision()));
    }

    public function test_membership_no_ops_skip_replacement_and_success_events(): void
    {
        $permission = $this->permission();
        $commands = [
            [
                new GrantPermissionToCustomRole($this->actorId(), $this->role()->getId(), $permission->getId()),
                $this->role([$permission->getId()])
            ],
            [
                new RevokePermissionFromCustomRole($this->actorId(), $this->role()->getId(), $permission->getId()),
                $this->role()
            ]
        ];

        foreach ($commands as [$command, $role]) {
            $updatedAt = $role->getUpdatedAt();
            $unitOfWork = new InMemoryUnitOfWork();
            $roleRepository = new InMemoryRoleRepository(
                $unitOfWork,
                beforeReplace: static function (): void {
                    self::fail('An already satisfied membership command must not replace the role.');
                }
            );
            $permissionRepository = new InMemoryPermissionRepository($unitOfWork);
            $permissionRepository->add($permission);
            $roleRepository->add($role);
            $eventDispatcher = new InMemoryEventDispatcher();
            if ($command instanceof GrantPermissionToCustomRole) {
                $handler = $this->grantHandler($roleRepository, $permissionRepository, $unitOfWork, $eventDispatcher);
            } else {
                $handler = $this->revokeHandler($roleRepository, $permissionRepository, $unitOfWork, $eventDispatcher);
            }

            $handler->handle(CommandMessage::create($command));

            self::assertSame(1, $unitOfWork->transactions);
            self::assertSame($role, $roleRepository->getById($role->getId()));
            self::assertSame($updatedAt, $role->getUpdatedAt());
            self::assertSame([], $eventDispatcher->events());
        }
    }

    public function test_no_op_membership_commands_validate_every_precondition_before_acceptance(): void
    {
        $permission = $this->permission();
        $customRoleWithPermission = $this->role([$permission->getId()]);
        $customRoleWithoutPermission = $this->role();
        $managedRoleWithPermission = Role::defineManaged(
            RoleId::generate(),
            RoleName::fromString('ROLE_MANAGED_WITH_PERMISSION'),
            [$permission->getId()],
            new DateTimeImmutable('2026-08-22T00:00:00+00:00')
        );
        $managedRoleWithoutPermission = Role::defineManaged(
            RoleId::generate(),
            RoleName::fromString('ROLE_MANAGED_WITHOUT_PERMISSION'),
            [],
            new DateTimeImmutable('2026-08-22T00:00:00+00:00')
        );
        /**
         * @var list<array{
         *     string,
         *     class-string<GrantPermissionToCustomRole>|class-string<RevokePermissionFromCustomRole>,
         *     Role|null,
         *     bool
         * }> $cases
         */
        $cases = [
            ['missing target grant', GrantPermissionToCustomRole::class, null, true],
            ['missing target revoke', RevokePermissionFromCustomRole::class, null, true],
            ['managed grant no-op', GrantPermissionToCustomRole::class, $managedRoleWithPermission, true],
            [
                'managed revoke no-op',
                RevokePermissionFromCustomRole::class,
                $managedRoleWithoutPermission,
                true
            ],
            [
                'missing permission grant no-op',
                GrantPermissionToCustomRole::class,
                $customRoleWithPermission,
                false
            ],
            [
                'missing permission revoke no-op',
                RevokePermissionFromCustomRole::class,
                $customRoleWithoutPermission,
                false
            ]
        ];

        foreach ($cases as [$case, $commandClass, $role, $permissionExists]) {
            $unitOfWork = new InMemoryUnitOfWork();
            $permissionRepository = new InMemoryPermissionRepository($unitOfWork);
            if ($permissionExists) {
                $permissionRepository->add($permission);
            }

            if (!$permissionExists) {
                $roleRepository = new ControllableRoleRepository($role);
            } else {
                $roleRepository = new InMemoryRoleRepository(
                    $unitOfWork,
                    beforeReplace: static function () use ($commandClass): void {
                        self::fail($commandClass.' rejected no-op membership command must not replace the role.');
                    }
                );
                if ($role instanceof Role) {
                    $roleRepository->add($role);
                }
            }

            $eventDispatcher = new InMemoryEventDispatcher();
            $roleId = $role instanceof Role ? $role->getId() : RoleId::generate();
            if ($commandClass === GrantPermissionToCustomRole::class) {
                $command = new GrantPermissionToCustomRole($this->actorId(), $roleId, $permission->getId());
                $handler = $this->grantHandler($roleRepository, $permissionRepository, $unitOfWork, $eventDispatcher);
            } else {
                $command = new RevokePermissionFromCustomRole($this->actorId(), $roleId, $permission->getId());
                $handler = $this->revokeHandler($roleRepository, $permissionRepository, $unitOfWork, $eventDispatcher);
            }

            $failure = $this->captureFailure($handler->handle(...), $command);

            self::assertInstanceOf(CustomRoleException::class, $failure);
            self::assertSame(1, $unitOfWork->transactions);
            self::assertFalse($unitOfWork->transactionCompleted);
            self::assertSame($role, $roleRepository->getById($roleId), $case);
            $this->assertCommandFailure($eventDispatcher, $command, $failure);
        }
    }

    public function test_real_membership_replacements_hold_the_final_permission_reference_fence(): void
    {
        $permission = $this->permission();
        $cases = [
            [GrantPermissionToCustomRole::class, $this->role(), CustomRolePermissionGranted::class],
            [
                RevokePermissionFromCustomRole::class,
                $this->role([$permission->getId()]),
                CustomRolePermissionRevoked::class
            ]
        ];

        foreach ($cases as [$commandClass, $role, $eventClass]) {
            $unitOfWork = new InMemoryUnitOfWork();
            $fenceChecks = 0;
            $roleRepository = new InMemoryRoleRepository(
                $unitOfWork,
                beforeReplace: static function () use ($unitOfWork, &$fenceChecks): void {
                    ++$fenceChecks;
                    self::assertTrue($unitOfWork->authorizationReferenceState()->isReferenceFenceHeld());
                }
            );
            $permissionRepository = new InMemoryPermissionRepository($unitOfWork);
            $permissionRepository->add($permission);
            $roleRepository->add($role);
            $eventDispatcher = new InMemoryEventDispatcher();
            if ($commandClass === GrantPermissionToCustomRole::class) {
                $command = new GrantPermissionToCustomRole($this->actorId(), $role->getId(), $permission->getId());
                $handler = $this->grantHandler($roleRepository, $permissionRepository, $unitOfWork, $eventDispatcher);
            } else {
                $command = new RevokePermissionFromCustomRole($this->actorId(), $role->getId(), $permission->getId());
                $handler = $this->revokeHandler($roleRepository, $permissionRepository, $unitOfWork, $eventDispatcher);
            }

            $handler->handle(CommandMessage::create($command));

            self::assertSame(1, $fenceChecks);
            self::assertSame(1, $unitOfWork->transactions);
            self::assertCount(1, $eventDispatcher->events());
            self::assertInstanceOf($eventClass, $eventDispatcher->events()[0]);
        }
    }

    public function test_final_permission_reference_fence_rejects_both_no_op_retries_without_replacement(): void
    {
        $permission = $this->permission();
        $cases = [
            [GrantPermissionToCustomRole::class, $this->role([$permission->getId()])],
            [RevokePermissionFromCustomRole::class, $this->role()]
        ];

        foreach ($cases as [$commandClass, $role]) {
            $replacementCalls = 0;
            $unitOfWork = new InMemoryUnitOfWork();
            $roleRepository = new InMemoryRoleRepository(
                $unitOfWork,
                beforeReplace: static function () use (&$replacementCalls): void {
                    ++$replacementCalls;
                },
                beforeValidatePermissionReference: static function () use ($permission, $unitOfWork): void {
                    self::assertTrue($unitOfWork->authorizationReferenceState()->isReferenceFenceHeld());
                    $unitOfWork->authorizationReferenceState()->removePermission($permission->getId());
                }
            );
            $permissionRepository = new InMemoryPermissionRepository($unitOfWork);
            $permissionRepository->add($permission);
            $roleRepository->add($role);
            if ($commandClass === GrantPermissionToCustomRole::class) {
                $command = new GrantPermissionToCustomRole($this->actorId(), $role->getId(), $permission->getId());
            } else {
                $command = new RevokePermissionFromCustomRole($this->actorId(), $role->getId(), $permission->getId());
            }

            $eventDispatcher = new InMemoryEventDispatcher(
                static function ($event) use ($unitOfWork): void {
                    self::assertInstanceOf(CommandFailedEvent::class, $event);
                    self::assertFalse($unitOfWork->transactionActive);
                    self::assertFalse($unitOfWork->transactionCompleted);
                }
            );
            if ($command instanceof GrantPermissionToCustomRole) {
                $handler = $this->grantHandler($roleRepository, $permissionRepository, $unitOfWork, $eventDispatcher);
            } else {
                $handler = $this->revokeHandler($roleRepository, $permissionRepository, $unitOfWork, $eventDispatcher);
            }

            $updatedAt = $role->getUpdatedAt();

            $failure = $this->captureFailure($handler->handle(...), $command);

            self::assertInstanceOf(CustomRoleException::class, $failure);
            self::assertSame('The authoritative permission changed concurrently.', $failure->getMessage());
            self::assertSame($role, $roleRepository->getById($role->getId()));
            self::assertSame($updatedAt, $role->getUpdatedAt());
            self::assertSame(0, $replacementCalls);
            self::assertSame(1, $unitOfWork->transactions);
            $this->assertCommandFailure($eventDispatcher, $command, $failure);
        }
    }

    public function test_a_tier_change_before_final_validation_or_write_rejects_grants_atomically(): void
    {
        foreach ([false, true] as $noOp) {
            $unitOfWork = new InMemoryUnitOfWork();
            $permission = $this->managedPermission();
            $permissionRepository = new InMemoryPermissionRepository($unitOfWork);
            $permissionRepository->add($permission);
            $role = $this->role($noOp ? [$permission->getId()] : []);
            $protected = $permission->reconcileManaged(
                $permission->getName(),
                PermissionTier::SUPER_ADMIN_ONLY,
                new DateTimeImmutable('2026-08-23T00:00:00+00:00')
            );
            $promote = static function () use (
                $permissionRepository,
                $permission,
                $protected,
                $unitOfWork,
                $noOp
            ): void {
                self::assertTrue($unitOfWork->authorizationReferenceState()->isReferenceFenceHeld());
                if ($noOp) {
                    // Model a stale authoritative tier snapshot: promotion itself now rejects live membership.
                    $unitOfWork->authorizationReferenceState()->addPermission($protected);

                    return;
                }

                self::assertTrue($permissionRepository->replace($permission, $protected));
            };
            $roleRepository = new InMemoryRoleRepository(
                $unitOfWork,
                beforeReplace: $noOp ? null : $promote,
                beforeValidatePermissionReference: $noOp ? $promote : null
            );
            $roleRepository->add($role);
            $eventDispatcher = new InMemoryEventDispatcher();
            $command = new GrantPermissionToCustomRole($this->actorId(), $role->getId(), $permission->getId());
            $failure = $this->captureFailure(
                $this->grantHandler($roleRepository, $permissionRepository, $unitOfWork, $eventDispatcher)->handle(...),
                $command
            );

            self::assertInstanceOf(CustomRoleException::class, $failure);
            self::assertSame($role, $roleRepository->getById($role->getId()));
            self::assertSame($permission, $permissionRepository->getById($permission->getId()));
            self::assertFalse($unitOfWork->transactionCompleted);
            $this->assertCommandFailure($eventDispatcher, $command, $failure);
        }
    }

    public function test_it_removes_only_an_unreferenced_custom_role_before_post_commit_success(): void
    {
        $role = $this->role();
        $unitOfWork = new InMemoryUnitOfWork();
        $roleRepository = new InMemoryRoleRepository($unitOfWork);
        $roleRepository->add($role);

        $removedAt = new DateTimeImmutable('2026-08-23T14:00:00+00:00');
        $eventDispatcher = new InMemoryEventDispatcher(function () use ($roleRepository, $role, $unitOfWork): void {
            self::assertNull($roleRepository->getById($role->getId()));
            self::assertTrue($unitOfWork->transactionCompleted);
        });
        $command = new RemoveCustomRole($this->actorId(), $role->getId(), $role->getRevision());
        $handler = $this->removeHandler(
            $roleRepository,
            new InMemoryUserRepository(),
            $unitOfWork,
            $eventDispatcher,
            now: $removedAt
        );

        $handler->handle(CommandMessage::create($command));

        self::assertSame(RemoveCustomRole::class, RemoveCustomRoleHandler::commandRegistration());
        self::assertSame(1, $unitOfWork->transactions);
        self::assertInstanceOf(CustomRoleRemoved::class, $eventDispatcher->events()[0]);
        $removed = $eventDispatcher->events()[0];
        self::assertSame($command->getActorId(), $removed->getActorId());
        self::assertSame($command->getRoleId(), $removed->getRoleId());
        self::assertSame($removedAt, $removed->getRemovedAt());
    }

    public function test_membership_final_boundary_rejects_a_permission_removed_after_handler_read(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $permission = $this->permission();
        $permissionRepository = new InMemoryPermissionRepository($unitOfWork);
        $permissionRepository->add($permission);

        $roleRepository = null;
        $roleRepository = new InMemoryRoleRepository(
            $unitOfWork,
            beforeReplace: static function () use (&$roleRepository, $permission, $permissionRepository): void {
                self::assertInstanceOf(InMemoryRoleRepository::class, $roleRepository);
                self::assertTrue($permissionRepository->remove($permission));
            }
        );
        $role = $this->role();
        $roleRepository->add($role);
        $eventDispatcher = new InMemoryEventDispatcher();
        $command = new GrantPermissionToCustomRole($this->actorId(), $role->getId(), $permission->getId());

        $failure = $this->captureFailure(
            $this->grantHandler($roleRepository, $permissionRepository, $unitOfWork, $eventDispatcher)->handle(...),
            $command
        );

        self::assertInstanceOf(CustomRoleException::class, $failure);
        self::assertSame('The custom role changed concurrently.', $failure->getMessage());
        self::assertInstanceOf(Permission::class, $permissionRepository->getById($permission->getId()));
        self::assertFalse($roleRepository->getById($role->getId())?->hasPermission($permission->getId()));
        self::assertFalse($unitOfWork->transactionCompleted);
        $this->assertCommandFailure($eventDispatcher, $command, $failure);
    }

    public function test_protected_grants_fail_for_any_actor_without_forbidden_existing_membership(): void
    {
        $superAdminRole = Role::defineManaged(
            RoleId::generate(),
            RoleName::fromString('ROLE_SUPER_ADMIN'),
            [],
            new DateTimeImmutable('2026-08-22T00:00:00+00:00')
        );
        $superAdmin = UserFixture::withRoleAssignments([$superAdminRole->getId()], 1);
        self::assertTrue($superAdmin->hasRole($superAdminRole->getId()));

        foreach ([$this->actorId(), $superAdmin->getId()] as $actorId) {
                $unitOfWork = new InMemoryUnitOfWork();
                $permission = $this->managedPermission();
                $permissionRepository = new InMemoryPermissionRepository($unitOfWork);
                $permissionRepository->add($permission);
                $role = $this->role();
                $roleRepository = new InMemoryRoleRepository(
                    $unitOfWork,
                    beforeReplace: static function (): void {
                        self::fail('A protected grant cannot write the Role.');
                    }
                );
                $roleRepository->add($role);
                $protected = $permission->reconcileManaged(
                    $permission->getName(),
                    PermissionTier::SUPER_ADMIN_ONLY,
                    $permission->getUpdatedAt()
                );
                self::assertTrue($permissionRepository->replace($permission, $protected));
                $eventDispatcher = new InMemoryEventDispatcher();
                $command = new GrantPermissionToCustomRole($actorId, $role->getId(), $permission->getId());
                $failure = $this->captureFailure(
                    $this->grantHandler(
                        $roleRepository,
                        $permissionRepository,
                        $unitOfWork,
                        $eventDispatcher
                    )->handle(...),
                    $command
                );

                self::assertInstanceOf(CustomRoleException::class, $failure);
                self::assertSame($role, $roleRepository->getById($role->getId()));
                self::assertFalse($unitOfWork->transactionCompleted);
                $this->assertCommandFailure($eventDispatcher, $command, $failure);
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
                $permission
            ],
            [
                new GrantPermissionToCustomRole($this->actorId(), $role->getId(), PermissionId::generate()),
                $role,
                null
            ],
            [
                new RevokePermissionFromCustomRole($this->actorId(), RoleId::generate(), $permissionId),
                null,
                $permission
            ],
            [
                new RevokePermissionFromCustomRole($this->actorId(), $role->getId(), PermissionId::generate()),
                $role,
                null
            ]
        ];

        foreach ($cases as [$command, $seedRole, $seedPermission]) {
            $unitOfWork = new InMemoryUnitOfWork();
            $permissionRepository = new InMemoryPermissionRepository($unitOfWork);
            if ($seedPermission instanceof Permission) {
                $permissionRepository->add($seedPermission);
            }

            if ($seedRole instanceof Role) {
                foreach ($seedRole->getPermissionIds() as $seedPermissionId) {
                    if (!$permissionRepository->getById($seedPermissionId) instanceof Permission) {
                        $permissionRepository->add(Permission::define(
                            $seedPermissionId,
                            PermissionName::fromString('EXISTING_PERMISSION'),
                            new DateTimeImmutable('2026-08-22T00:00:00+00:00')
                        ));
                    }
                }
            }

            $roleRepository = new InMemoryRoleRepository($unitOfWork);
            if ($seedRole instanceof Role) {
                $roleRepository->add($seedRole);
            }

            $eventDispatcher = new InMemoryEventDispatcher();
            if ($command instanceof GrantPermissionToCustomRole) {
                $handler = $this->grantHandler($roleRepository, $permissionRepository, $unitOfWork, $eventDispatcher);
            } else {
                $handler = $this->revokeHandler($roleRepository, $permissionRepository, $unitOfWork, $eventDispatcher);
            }

            $failure = $this->captureFailure($handler->handle(...), $command);

            self::assertInstanceOf(CustomRoleException::class, $failure);
            $this->assertCommandFailure($eventDispatcher, $command, $failure);
        }
    }

    public function test_grant_rejects_inconsistent_repository_identities_without_writing(): void
    {
        foreach ([true, false] as $wrongRole) {
            $requestedRole = RoleId::generate();
            $requestedPermission = PermissionId::generate();
            $roleRepository = $this->createMock(RoleRepository::class);
            $roleRepository->method('getById')->willReturn($this->role());
            $roleRepository->expects(self::never())->method('replace');
            $permissionRepository = $this->createMock(PermissionRepository::class);
            if ($wrongRole) {
                $permissionRepository->expects(self::never())->method('getById');
            } else {
                $requestedRole = $this->role()->getId();
                $permissionRepository->expects(self::once())->method('getById')->willReturn($this->permission());
            }

            $eventDispatcher = new InMemoryEventDispatcher();
            $unitOfWork = new InMemoryUnitOfWork();
            $command = new GrantPermissionToCustomRole($this->actorId(), $requestedRole, $requestedPermission);
            $failure = $this->captureFailure(
                $this->grantHandler($roleRepository, $permissionRepository, $unitOfWork, $eventDispatcher)->handle(...),
                $command
            );

            self::assertInstanceOf(CustomRoleException::class, $failure);
            self::assertFalse($unitOfWork->transactionCompleted);
            $this->assertCommandFailure($eventDispatcher, $command, $failure);
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
            new RemoveCustomRole($this->actorId(), $role->getId(), $role->getRevision())
        ];

        foreach ($commands as $command) {
            $unitOfWork = new InMemoryUnitOfWork();
            $permissionRepository = new InMemoryPermissionRepository($unitOfWork);
            $permissionRepository->add($permission);
            if ($command instanceof GrantPermissionToCustomRole) {
                $permissionRepository->add(Permission::define(
                    $command->getPermissionId(),
                    PermissionName::fromString('SECOND_PERMISSION'),
                    new DateTimeImmutable('2026-08-22T00:00:00+00:00')
                ));
            }

            $roleRepository = new InMemoryRoleRepository($unitOfWork);
            $roleRepository->add($role);

            $eventDispatcher = new InMemoryEventDispatcher();
            $handler = match ($command::class) {
                GrantPermissionToCustomRole::class => $this->grantHandler(
                    $roleRepository,
                    $permissionRepository,
                    $unitOfWork,
                    $eventDispatcher
                ),
                RevokePermissionFromCustomRole::class => $this->revokeHandler(
                    $roleRepository,
                    $permissionRepository,
                    $unitOfWork,
                    $eventDispatcher
                ),
                RemoveCustomRole::class => $this->removeHandler(
                    $roleRepository,
                    new InMemoryUserRepository(),
                    new InMemoryUnitOfWork(),
                    $eventDispatcher
                ),
            };

            $failure = $this->captureFailure($handler->handle(...), $command);

            self::assertInstanceOf(CustomRoleException::class, $failure);
            self::assertSame($role, $roleRepository->getById($role->getId()));
            $this->assertCommandFailure($eventDispatcher, $command, $failure);
        }
    }

    public function test_concurrent_membership_replacement_and_removal_loss_are_rejected(): void
    {
        $permission = $this->permission();
        $roleWithPermission = $this->role([$permission->getId()]);
        $emptyRole = $this->role();
        $commands = [
            new GrantPermissionToCustomRole($this->actorId(), $this->role()->getId(), $permission->getId()),
            new RevokePermissionFromCustomRole($this->actorId(), $roleWithPermission->getId(), $permission->getId()),
            new RemoveCustomRole($this->actorId(), $emptyRole->getId(), $emptyRole->getRevision())
        ];

        foreach ($commands as $command) {
            $seed = $command instanceof RevokePermissionFromCustomRole ? $roleWithPermission : $emptyRole;
            $roleRepository = new ControllableRoleRepository(
                $seed,
                replaceSucceeds: false,
                removeSucceeds: false
            );
            $permissionRepository = new InMemoryPermissionRepository();
            $permissionRepository->add($permission);
            $eventDispatcher = new InMemoryEventDispatcher();
            $handler = match ($command::class) {
                GrantPermissionToCustomRole::class => $this->grantHandler(
                    $roleRepository,
                    $permissionRepository,
                    new InMemoryUnitOfWork(),
                    $eventDispatcher
                ),
                RevokePermissionFromCustomRole::class => $this->revokeHandler(
                    $roleRepository,
                    $permissionRepository,
                    new InMemoryUnitOfWork(),
                    $eventDispatcher
                ),
                RemoveCustomRole::class => $this->removeHandler(
                    $roleRepository,
                    new InMemoryUserRepository(),
                    new InMemoryUnitOfWork(),
                    $eventDispatcher
                ),
            };

            $failure = $this->captureFailure($handler->handle(...), $command);

            self::assertInstanceOf(CustomRoleException::class, $failure);
            $this->assertCommandFailure($eventDispatcher, $command, $failure);
        }
    }

    public function test_removal_rejects_missing_and_live_user_referenced_roles(): void
    {
        $role = $this->role();
        $unitOfWork = new InMemoryUnitOfWork();
        $unitOfWork->authorizationReferenceState()->addRole($role);
        $userRepository = new InMemoryUserRepository($unitOfWork);
        $userRepository->add(UserFixture::withRoleAssignments([$role->getId()], 1));
        foreach ([new InMemoryRoleRepository(), $this->rolesContaining($role)] as $roleRepository) {
            $eventDispatcher = new InMemoryEventDispatcher();
            $command = new RemoveCustomRole($this->actorId(), $role->getId(), $role->getRevision());
            $handler = $this->removeHandler(
                $roleRepository,
                $userRepository,
                new InMemoryUnitOfWork(),
                $eventDispatcher
            );

            $failure = $this->captureFailure($handler->handle(...), $command);

            self::assertInstanceOf(CustomRoleException::class, $failure);
            $this->assertCommandFailure($eventDispatcher, $command, $failure);
        }
    }

    public function test_removal_fails_when_a_user_assignment_appears_at_the_final_persistence_boundary(): void
    {
        $role = $this->role();
        $unitOfWork = new InMemoryUnitOfWork();
        $referenceChecks = 0;
        $userRepository = new InMemoryUserRepository(
            $unitOfWork,
            beforeHasRoleAssignment: static function () use (&$referenceChecks): bool {
                ++$referenceChecks;

                return false;
            }
        );
        $roleRepository = new InMemoryRoleRepository(
            $unitOfWork,
            beforeRemove: static function () use ($role, $unitOfWork, $userRepository): void {
                self::assertTrue($unitOfWork->authorizationReferenceState()->isReferenceFenceHeld());
                $userRepository->add(UserFixture::withRoleAssignments([$role->getId()], 1));
            }
        );
        $roleRepository->add($role);

        $eventDispatcher = new InMemoryEventDispatcher();
        $command = new RemoveCustomRole($this->actorId(), $role->getId(), $role->getRevision());

        $failure = $this->captureFailure(
            $this->removeHandler($roleRepository, $userRepository, $unitOfWork, $eventDispatcher)->handle(...),
            $command
        );

        self::assertInstanceOf(CustomRoleException::class, $failure);
        self::assertSame(1, $referenceChecks);
        self::assertSame($role, $roleRepository->getById($role->getId()));
        $this->assertCommandFailure($eventDispatcher, $command, $failure);
    }

    public function test_each_handler_rethrows_the_identical_repository_failure(): void
    {
        $expected = new RuntimeException('role read failed');
        $permission = $this->permission();
        $permissionRepository = new InMemoryPermissionRepository();
        $permissionRepository->add($permission);

        $commands = [
            new GrantPermissionToCustomRole($this->actorId(), RoleId::generate(), $permission->getId()),
            new RevokePermissionFromCustomRole($this->actorId(), RoleId::generate(), $permission->getId()),
            new RemoveCustomRole($this->actorId(), RoleId::generate(), RoleRevision::initial())
        ];

        foreach ($commands as $command) {
            $roleRepository = new ControllableRoleRepository(getFailure: $expected);
            $eventDispatcher = new InMemoryEventDispatcher();
            $handler = match ($command::class) {
                GrantPermissionToCustomRole::class => $this->grantHandler(
                    $roleRepository,
                    $permissionRepository,
                    new InMemoryUnitOfWork(),
                    $eventDispatcher
                ),
                RevokePermissionFromCustomRole::class => $this->revokeHandler(
                    $roleRepository,
                    $permissionRepository,
                    new InMemoryUnitOfWork(),
                    $eventDispatcher
                ),
                RemoveCustomRole::class => $this->removeHandler(
                    $roleRepository,
                    new InMemoryUserRepository(),
                    new InMemoryUnitOfWork(),
                    $eventDispatcher
                ),
            };

            $actual = $this->captureFailure($handler->handle(...), $command);

            self::assertSame($expected, $actual);
            $this->assertCommandFailure($eventDispatcher, $command, $expected);
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
            new RemoveCustomRole($actorId, $roleId, RoleRevision::initial()),
            new CustomRolePermissionGranted($actorId, $roleId, $permissionId, $now),
            new CustomRolePermissionRevoked($actorId, $roleId, $permissionId, $now),
            new CustomRoleRemoved($actorId, $roleId, $now)
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

    private function managedPermission(): Permission
    {
        return Permission::defineManaged(
            $this->permission()->getId(),
            PermissionName::fromString('READ_CASES'),
            PermissionTier::ADMIN_SAFE,
            new DateTimeImmutable('2026-08-22T00:00:00+00:00')
        );
    }

    private function actorId(): UserId
    {
        return UserId::fromString('018f0000-0000-7000-8000-000000000001');
    }

    private function rolesContaining(Role $role): InMemoryRoleRepository
    {
        $roleRepository = new InMemoryRoleRepository();
        $roleRepository->add($role);

        return $roleRepository;
    }

    private function grantHandler(
        RoleRepository $roleRepository,
        PermissionRepository $permissionRepository,
        InMemoryUnitOfWork $unitOfWork,
        InMemoryEventDispatcher $eventDispatcher,
        DateTimeImmutable|string $now = '2026-08-23T12:00:00+00:00'
    ): GrantPermissionToCustomRoleHandler {
        return new GrantPermissionToCustomRoleHandler(
            $roleRepository,
            $permissionRepository,
            new FixedClock($now),
            $unitOfWork,
            $eventDispatcher
        );
    }

    private function revokeHandler(
        RoleRepository $roleRepository,
        InMemoryPermissionRepository $permissionRepository,
        InMemoryUnitOfWork $unitOfWork,
        InMemoryEventDispatcher $eventDispatcher,
        DateTimeImmutable|string $now = '2026-08-23T12:00:00+00:00'
    ): RevokePermissionFromCustomRoleHandler {
        return new RevokePermissionFromCustomRoleHandler(
            $roleRepository,
            $permissionRepository,
            new FixedClock($now),
            $unitOfWork,
            $eventDispatcher
        );
    }

    private function removeHandler(
        RoleRepository $roleRepository,
        InMemoryUserRepository $userRepository,
        InMemoryUnitOfWork $unitOfWork,
        InMemoryEventDispatcher $eventDispatcher,
        DateTimeImmutable|string $now = '2026-08-23T12:00:00+00:00'
    ): RemoveCustomRoleHandler {
        return new RemoveCustomRoleHandler(
            $roleRepository,
            $userRepository,
            new FixedClock($now),
            $unitOfWork,
            $eventDispatcher
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
        InMemoryEventDispatcher $eventDispatcher,
        Command $command,
        Throwable $throwable
    ): void {
        self::assertCount(1, $eventDispatcher->events());
        self::assertInstanceOf(CommandFailedEvent::class, $eventDispatcher->events()[0]);
        self::assertSame($command, $eventDispatcher->events()[0]->getCommand());
        $message = match ($command::class) {
            GrantPermissionToCustomRole::class => 'Custom role permission grant failed.',
            RevokePermissionFromCustomRole::class => 'Custom role permission revocation failed.',
            RemoveCustomRole::class => 'Custom role removal failed.',
            default => throw new LogicException('Unsupported custom role command.')
        };
        self::assertSame($message, $eventDispatcher->events()[0]->getErrorMessage());
        self::assertNotSame($throwable->getMessage(), $eventDispatcher->events()[0]->getErrorMessage());
    }
}
