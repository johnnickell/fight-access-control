<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Permission\CommandHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\Permission\CommandHandler\ReconcileManagedPolicyHandler;
use Fight\AccessControl\Application\AccessControl\Permission\QueryHandler\PreviewManagedPolicyHandler;
use Fight\AccessControl\Application\AccessControl\Permission\Service\ManagedPolicyPlanner;
use Fight\AccessControl\Domain\AccessControl\Permission\Command\ReconcileManagedPolicy;
use Fight\AccessControl\Domain\AccessControl\Permission\Event\ManagedPolicyReconciled;
use Fight\AccessControl\Domain\AccessControl\Permission\Exception\ManagedPolicyDefinitionException;
use Fight\AccessControl\Domain\AccessControl\Permission\ManagedPermissionDefinition;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionRepository;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionTier;
use Fight\AccessControl\Domain\AccessControl\Permission\Query\ManagedPolicyPlan;
use Fight\AccessControl\Domain\AccessControl\Permission\Query\PreviewManagedPolicy;
use Fight\AccessControl\Domain\AccessControl\Role\ManagedRoleDefinition;
use Fight\AccessControl\Domain\AccessControl\Role\Role;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\Role\RoleName;
use Fight\AccessControl\Domain\AccessControl\Role\RoleRepository;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Fight\Common\Domain\Messaging\Query\QueryMessage;
use Fight\Common\Domain\Repository\Pagination;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\Permission\Repository\InMemoryPermissionRepository;
use Fight\Test\AccessControl\Application\AccessControl\Role\Repository\InMemoryRoleRepository;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryUserRepository;
use Fight\Test\AccessControl\Domain\AccessControl\User\UserFixture;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(ReconcileManagedPolicyHandler::class)]
#[CoversClass(ManagedPolicyPlanner::class)]
#[CoversClass(ReconcileManagedPolicy::class)]
#[CoversClass(ManagedPolicyReconciled::class)]
#[CoversClass(ManagedPolicyPlan::class)]
#[CoversClass(Permission::class)]
#[CoversClass(PreviewManagedPolicy::class)]
#[CoversClass(Role::class)]
final class ReconcileManagedPolicyHandlerTest extends TestCase
{
    public function test_it_applies_the_literal_preview_plan_atomically_and_preserves_custom_state(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $permissions = new InMemoryPermissionRepository($unitOfWork);
        $roles = new InMemoryRoleRepository($unitOfWork);
        $users = new InMemoryUserRepository($unitOfWork);
        $createdAt = new DateTimeImmutable('2026-08-01T00:00:00+00:00');
        $permissions->add(Permission::defineManaged(
            $this->permissionId(101),
            PermissionName::fromString('VIEW_USERS'),
            PermissionTier::ADMIN_SAFE,
            $createdAt
        ));
        $permissions->add(Permission::defineManaged(
            $this->permissionId(102),
            PermissionName::fromString('OLD_MANAGE_USERS'),
            PermissionTier::ADMIN_SAFE,
            $createdAt
        ));
        $permissions->add(Permission::defineManaged(
            $this->permissionId(104),
            PermissionName::fromString('OBSOLETE'),
            PermissionTier::ADMIN_SAFE,
            $createdAt
        ));
        $permissions->add(Permission::define(
            $this->permissionId(190),
            PermissionName::fromString('CUSTOM_PERMISSION'),
            $createdAt
        ));
        $roles->add(Role::defineManaged(
            $this->roleId(201),
            RoleName::fromString('ROLE_VIEWER'),
            [$this->permissionId(101)],
            $createdAt
        ));
        $roles->add(Role::defineManaged(
            $this->roleId(202),
            RoleName::fromString('ROLE_EDITOR'),
            [$this->permissionId(101), $this->permissionId(104)],
            $createdAt
        ));
        $roles->add(Role::defineManaged(
            $this->roleId(204),
            RoleName::fromString('ROLE_OBSOLETE'),
            [$this->permissionId(104)],
            $createdAt
        ));
        $roles->add(Role::define(
            $this->roleId(290),
            RoleName::fromString('ROLE_CUSTOM'),
            [$this->permissionId(190)],
            $createdAt
        ));
        $command = $this->command();
        $preview = new PreviewManagedPolicyHandler(
            new ManagedPolicyPlanner($permissions, $roles, $users)
        )->handle(QueryMessage::create(
            new PreviewManagedPolicy(
                $command->getPermissions(),
                $command->getRoles(),
                $command->getReferencedPermissionIds()
            )
        ));
        $expectedPlan = [
            'permissions' => [
                $this->permissionPlan(103, 'ARCHIVE_USERS', 'ADMIN_SAFE', 'CREATE'),
                $this->permissionPlan(102, 'MANAGE_USERS', 'SUPER_ADMIN_ONLY', 'RECONCILE'),
                $this->permissionPlan(104, 'OBSOLETE', 'ADMIN_SAFE', 'REMOVE'),
                $this->permissionPlan(101, 'VIEW_USERS', 'ADMIN_SAFE', 'UNCHANGED'),
            ],
            'roles' => [
                $this->rolePlan(203, 'ROLE_ADMIN', [102, 103], 'CREATE'),
                $this->rolePlan(202, 'ROLE_EDITOR', [102, 103], 'RECONCILE'),
                $this->rolePlan(204, 'ROLE_OBSOLETE', [104], 'REMOVE'),
                $this->rolePlan(201, 'ROLE_VIEWER', [101], 'UNCHANGED'),
            ],
        ];
        self::assertSame($expectedPlan, $preview->toArray());
        $events = new InMemoryEventDispatcher(static function () use ($unitOfWork): void {
            self::assertTrue($unitOfWork->transactionCompleted);
            self::assertFalse($unitOfWork->transactionActive);
        });
        $handler = new ReconcileManagedPolicyHandler(
            $permissions,
            $roles,
            new ManagedPolicyPlanner($permissions, $roles, $users),
            $unitOfWork,
            $events,
            new FixedClock(new DateTimeImmutable('2026-08-23T12:00:00+00:00'))
        );

        $handler->handle(CommandMessage::create($command));

        self::assertSame(ReconcileManagedPolicy::class, ReconcileManagedPolicyHandler::commandRegistration());
        self::assertSame(1, $unitOfWork->transactions);
        self::assertCount(1, $events->events());
        self::assertInstanceOf(ManagedPolicyReconciled::class, $events->events()[0]);
        self::assertSame($expectedPlan, $events->events()[0]->getPlan());
        self::assertSame(
            '2026-08-23T12:00:00+00:00',
            $events->events()[0]->getOccurredAt()->format(DATE_ATOM)
        );
        self::assertEquals(
            $events->events()[0],
            ManagedPolicyReconciled::fromArray($events->events()[0]->toArray())
        );
        self::assertEquals($command, ReconcileManagedPolicy::fromArray($command->toArray()));
        $managedPermission = $permissions->getById($this->permissionId(102));
        self::assertInstanceOf(Permission::class, $managedPermission);
        self::assertSame('MANAGE_USERS', $managedPermission->getName()->toString());
        self::assertSame(PermissionTier::SUPER_ADMIN_ONLY, $managedPermission->getTier());
        self::assertNull($permissions->getById($this->permissionId(104)));
        self::assertSame(
            'CUSTOM_PERMISSION',
            $permissions->getById($this->permissionId(190))?->getName()->toString()
        );
        self::assertNull($roles->getById($this->roleId(204)));
        self::assertSame(
            'ROLE_CUSTOM',
            $roles->getById($this->roleId(290))?->getName()->toString()
        );
        self::assertSame(
            [$this->permissionId(102)->toString(), $this->permissionId(103)->toString()],
            array_map(
                static fn(PermissionId $id): string => $id->toString(),
                $roles->getById($this->roleId(202))?->getPermissionIds() ?? []
            )
        );
        self::assertSame(4, $permissions->getAll(new Pagination())->totalRecords());
        self::assertSame(4, $roles->getAll(new Pagination())->totalRecords());
    }

    public function test_it_rejects_code_and_live_role_references_without_persisting_changes(): void
    {
        $createdAt = new DateTimeImmutable('2026-08-01T00:00:00+00:00');
        $rejections = 0;
        foreach ([true, false] as $codeReference) {
            $unitOfWork = new InMemoryUnitOfWork();
            $permissions = new InMemoryPermissionRepository($unitOfWork);
            $roles = new InMemoryRoleRepository($unitOfWork);
            $users = new InMemoryUserRepository($unitOfWork);
            $permissions->add(Permission::defineManaged(
                $this->permissionId(104),
                PermissionName::fromString('OBSOLETE'),
                PermissionTier::ADMIN_SAFE,
                $createdAt
            ));
            if (!$codeReference) {
                $roles->add(Role::define(
                    $this->roleId(290),
                    RoleName::fromString('ROLE_CUSTOM'),
                    [$this->permissionId(104)],
                    $createdAt
                ));
            }

            $events = new InMemoryEventDispatcher();
            $handler = new ReconcileManagedPolicyHandler(
                $permissions,
                $roles,
                new ManagedPolicyPlanner($permissions, $roles, $users),
                $unitOfWork,
                $events,
                new FixedClock($createdAt)
            );
            $command = new ReconcileManagedPolicy([], [], $codeReference ? [$this->permissionId(104)] : []);

            try {
                $handler->handle(CommandMessage::create($command));
                self::fail('Referenced managed permissions must not be removed.');
            } catch (ManagedPolicyDefinitionException) {
                ++$rejections;
            }

            self::assertInstanceOf(Permission::class, $permissions->getById($this->permissionId(104)));
            self::assertCount(1, $events->events());
            self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
        }

        self::assertSame(2, $rejections);
    }

    public function test_preview_and_apply_both_preflight_reject_an_assigned_managed_role_removal(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $permissions = new InMemoryPermissionRepository($unitOfWork);
        $roles = new InMemoryRoleRepository($unitOfWork);
        $role = Role::defineManaged(
            $this->roleId(204),
            RoleName::fromString('ROLE_ASSIGNED'),
            [],
            new DateTimeImmutable('2026-08-01T00:00:00+00:00')
        );
        $roles->add($role);
        $users = new InMemoryUserRepository($unitOfWork);
        $users->add(UserFixture::withRoleAssignments([$role->getId()], 1));

        $query = new PreviewManagedPolicy([], [], []);
        $command = new ReconcileManagedPolicy([], [], []);
        $events = new InMemoryEventDispatcher();
        $rejections = 0;

        try {
            new PreviewManagedPolicyHandler(
                new ManagedPolicyPlanner($permissions, $roles, $users)
            )->handle(QueryMessage::create($query));
            self::fail('Preview must reject removal of an assigned managed Role.');
        } catch (ManagedPolicyDefinitionException $managedPolicyDefinitionException) {
            self::assertSame(
                'Managed role "ROLE_ASSIGNED" cannot be removed because a user references it.',
                $managedPolicyDefinitionException->getMessage()
            );
            ++$rejections;
        }

        $handler = new ReconcileManagedPolicyHandler(
            $permissions,
            $roles,
            new ManagedPolicyPlanner($permissions, $roles, $users),
            $unitOfWork,
            $events,
            new FixedClock(new DateTimeImmutable('2026-08-23T12:00:00+00:00'))
        );
        try {
            $handler->handle(CommandMessage::create($command));
            self::fail('Apply must reject the same assigned managed Role snapshot during planning.');
        } catch (ManagedPolicyDefinitionException $managedPolicyDefinitionException) {
            self::assertSame(
                'Managed role "ROLE_ASSIGNED" cannot be removed because a user references it.',
                $managedPolicyDefinitionException->getMessage()
            );
            ++$rejections;
        }

        self::assertSame(2, $rejections);
        self::assertSame($role, $roles->getById($role->getId()));
        self::assertSame(1, $unitOfWork->transactions);
        self::assertFalse($unitOfWork->transactionCompleted);
        self::assertCount(1, $events->events());
        self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
        self::assertSame($command, $events->events()[0]->getCommand());
    }

    public function test_it_rolls_back_prior_writes_and_rethrows_the_same_later_failure(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $permissions = new InMemoryPermissionRepository($unitOfWork);
        $roles = $this->createStub(RoleRepository::class);
        $roles->method('getManaged')->willReturn([]);
        $roles->method('getById')->willReturn(null);
        $roles->method('getByName')->willReturn(null);
        $failure = new RuntimeException('role persistence unavailable');
        $roles->method('add')->willThrowException($failure);
        $events = new InMemoryEventDispatcher();
        $handler = new ReconcileManagedPolicyHandler(
            $permissions,
            $roles,
            new ManagedPolicyPlanner(
                $permissions,
                $roles,
                new InMemoryUserRepository($unitOfWork)
            ),
            $unitOfWork,
            $events,
            new FixedClock(new DateTimeImmutable('2026-08-23T12:00:00+00:00'))
        );

        try {
            $handler->handle(CommandMessage::create($this->command()));
            self::fail('The role write failure must escape.');
        } catch (RuntimeException $runtimeException) {
            self::assertSame($failure, $runtimeException);
        }

        self::assertSame(1, $unitOfWork->transactions);
        self::assertSame(0, $permissions->getAll(new Pagination())->totalRecords());
        self::assertFalse($unitOfWork->transactionCompleted);
        self::assertCount(1, $events->events());
        self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
    }

    public function test_command_and_event_serialization_reject_ambiguous_or_missing_data(): void
    {
        $rejections = 0;
        try {
            new ReconcileManagedPolicy([], [], [$this->permissionId(101), $this->permissionId(101)]);
            self::fail('Duplicate consumer code references must be rejected.');
        } catch (ManagedPolicyDefinitionException) {
            ++$rejections;
        }

        $event = new ManagedPolicyReconciled(
            ['permissions' => [], 'roles' => []],
            new DateTimeImmutable('2026-08-23T12:00:00+00:00')
        );
        foreach (['plan', 'occurred_at'] as $key) {
            $incomplete = $event->toArray();
            unset($incomplete[$key]);

            try {
                ManagedPolicyReconciled::fromArray($incomplete);
                self::fail('Missing event data must be rejected.');
            } catch (DomainException) {
                ++$rejections;
            }
        }

        try {
            ManagedPolicyReconciled::fromArray([
                'plan' => 'invalid',
                'occurred_at' => '2026-08-23T12:00:00+00:00',
            ]);
            self::fail('A non-array event plan must be rejected.');
        } catch (DomainException) {
            ++$rejections;
        }

        self::assertSame(4, $rejections);
    }

    public function test_reconciliation_event_requires_the_exact_serialized_plan_schema(): void
    {
        $occurredAt = new DateTimeImmutable('2026-08-23T12:00:00+00:00');
        $permission = [
            'id' => $this->permissionId(101)->toString(),
            'name' => 'VIEW_USERS',
            'tier' => 'ADMIN_SAFE',
            'action' => 'CREATE',
        ];
        $role = [
            'id' => $this->roleId(201)->toString(),
            'name' => 'ROLE_ADMIN',
            'permission_ids' => [$this->permissionId(101)->toString()],
            'action' => 'RECONCILE',
        ];
        $validPlan = ['permissions' => [$permission], 'roles' => [$role]];
        $event = new ManagedPolicyReconciled($validPlan, $occurredAt);

        self::assertSame($validPlan, $event->getPlan());
        self::assertEquals($event, ManagedPolicyReconciled::fromArray($event->toArray()));

        $invalidPlans = [
            [],
            ['permissions' => []],
            ['roles' => []],
            ['permissions' => 'invalid', 'roles' => []],
            ['permissions' => [], 'roles' => 'invalid'],
            ['permissions' => ['item' => $permission], 'roles' => []],
            ['permissions' => [], 'roles' => ['item' => $role]],
            ['permissions' => [], 'roles' => [], 'unexpected' => []],
            ['permissions' => [], 'unexpected' => []],
            ['permissions' => ['invalid'], 'roles' => []],
            ['permissions' => [[
                'id' => $permission['id'],
                'name' => $permission['name'],
                'tier' => $permission['tier'],
            ]], 'roles' => []],
            ['permissions' => [[...$permission, 'unexpected' => true]], 'roles' => []],
            ['permissions' => [[...$permission, 'id' => 'invalid']], 'roles' => []],
            ['permissions' => [[...$permission, 'name' => 'invalid']], 'roles' => []],
            ['permissions' => [[...$permission, 'tier' => 'INVALID']], 'roles' => []],
            ['permissions' => [[...$permission, 'action' => 'INVALID']], 'roles' => []],
            ['permissions' => [[...$permission, 'action' => 1]], 'roles' => []],
            ['permissions' => [], 'roles' => ['invalid']],
            ['permissions' => [], 'roles' => [[
                'id' => $role['id'],
                'name' => $role['name'],
                'permission_ids' => $role['permission_ids'],
            ]]],
            ['permissions' => [], 'roles' => [[...$role, 'unexpected' => true]]],
            ['permissions' => [], 'roles' => [[...$role, 'id' => 'invalid']]],
            ['permissions' => [], 'roles' => [[...$role, 'name' => 'invalid']]],
            ['permissions' => [], 'roles' => [[...$role, 'permission_ids' => ['id' => $permission['id']]]]],
            ['permissions' => [], 'roles' => [[...$role, 'permission_ids' => [1]]]],
            ['permissions' => [], 'roles' => [[...$role, 'permission_ids' => ['invalid']]]],
            ['permissions' => [], 'roles' => [[...$role, 'action' => 'INVALID']]],
            ['permissions' => [], 'roles' => [[...$role, 'action' => 1]]],
        ];
        $rejections = 0;

        foreach ($invalidPlans as $invalidPlan) {
            foreach (['constructor', 'factory'] as $entryPoint) {
                try {
                    if ($entryPoint === 'constructor') {
                        new ManagedPolicyReconciled($invalidPlan, $occurredAt);
                    } else {
                        ManagedPolicyReconciled::fromArray([
                            'plan' => $invalidPlan,
                            'occurred_at' => $occurredAt->format(DATE_ATOM),
                        ]);
                    }

                    self::fail('A malformed reconciliation plan must be rejected.');
                } catch (DomainException) {
                    ++$rejections;
                }
            }
        }

        self::assertSame(count($invalidPlans) * 2, $rejections);
    }

    public function test_it_rejects_claiming_custom_records_as_managed(): void
    {
        $createdAt = new DateTimeImmutable('2026-08-01T00:00:00+00:00');
        $rejections = 0;
        foreach (['permission', 'role'] as $type) {
            $permissions = new InMemoryPermissionRepository();
            $roles = new InMemoryRoleRepository();
            if ($type === 'permission') {
                $permissions->add(Permission::define(
                    $this->permissionId(101),
                    PermissionName::fromString('VIEW_USERS'),
                    $createdAt
                ));
            } else {
                $roles->add(Role::define(
                    $this->roleId(201),
                    RoleName::fromString('ROLE_VIEWER'),
                    [],
                    $createdAt
                ));
            }

            $planner = new ManagedPolicyPlanner($permissions, $roles, new InMemoryUserRepository());
            try {
                $planner->plan(
                    $type === 'permission' ? [$this->permission(101, 'VIEW_USERS', PermissionTier::ADMIN_SAFE)] : [],
                    $type === 'role' ? [$this->role(201, 'ROLE_VIEWER', [])] : [],
                    []
                );
                self::fail('Custom records must not be claimed by managed policy.');
            } catch (ManagedPolicyDefinitionException) {
                ++$rejections;
            }
        }

        self::assertSame(2, $rejections);
    }

    public function test_it_fails_closed_when_persisted_state_changes_after_preflight(): void
    {
        $occurredAt = new DateTimeImmutable('2026-08-23T12:00:00+00:00');
        $permission = Permission::defineManaged(
            $this->permissionId(101),
            PermissionName::fromString('VIEW_USERS'),
            PermissionTier::ADMIN_SAFE,
            $occurredAt
        );
        $role = Role::defineManaged(
            $this->roleId(201),
            RoleName::fromString('ROLE_VIEWER'),
            [],
            $occurredAt
        );
        $failures = 0;
        foreach (
            [
                'permission_reconcile',
                'role_reconcile',
                'permission_remove',
                'permission_remove_loss',
                'role_remove',
                'role_remove_loss',
            ] as $scenario
        ) {
            $permissionRepository = $this->createStub(PermissionRepository::class);
            $roleRepository = $this->createStub(RoleRepository::class);
            $command = new ReconcileManagedPolicy([], [], []);

            if ($scenario === 'permission_reconcile') {
                $permissionRepository->method('getManaged')->willReturn([]);
                $roleRepository->method('getManaged')->willReturn([]);
                $permissionRepository->method('getById')->willReturn($permission, null);
                $permissionRepository->method('getByName')->willReturn(null);
                $command = new ReconcileManagedPolicy(
                    [$this->permission(101, 'RENAMED_PERMISSION', PermissionTier::ADMIN_SAFE)],
                    [],
                    []
                );
            } elseif ($scenario === 'role_reconcile') {
                $permissionRepository->method('getManaged')->willReturn([]);
                $roleRepository->method('getManaged')->willReturn([]);
                $roleRepository->method('getById')->willReturn($role, null);
                $roleRepository->method('getByName')->willReturn(null);
                $command = new ReconcileManagedPolicy([], [$this->role(201, 'ROLE_RENAMED', [])], []);
            } elseif ($scenario === 'permission_remove') {
                $permissionRepository->method('getManaged')->willReturn([$permission]);
                $roleRepository->method('getManaged')->willReturn([]);
                $permissionRepository->method('getById')->willReturn(null);
                $roleRepository->method('getContainingPermission')->willReturn([]);
            } elseif ($scenario === 'permission_remove_loss') {
                $permissionRepository->method('getManaged')->willReturn([$permission]);
                $permissionRepository->method('getById')->willReturn($permission);
                $permissionRepository->method('remove')->willReturn(false);
                $roleRepository->method('getManaged')->willReturn([]);
                $roleRepository->method('getContainingPermission')->willReturn([]);
            } elseif ($scenario === 'role_remove') {
                $permissionRepository->method('getManaged')->willReturn([]);
                $roleRepository->method('getManaged')->willReturn([$role]);
                $roleRepository->method('getById')->willReturn(null);
            } else {
                $permissionRepository->method('getManaged')->willReturn([]);
                $roleRepository->method('getManaged')->willReturn([$role]);
                $roleRepository->method('getById')->willReturn($role);
                $roleRepository->method('remove')->willReturn(false);
            }

            $events = new InMemoryEventDispatcher();
            $handler = new ReconcileManagedPolicyHandler(
                $permissionRepository,
                $roleRepository,
                new ManagedPolicyPlanner(
                    $permissionRepository,
                    $roleRepository,
                    new InMemoryUserRepository()
                ),
                new InMemoryUnitOfWork(),
                $events,
                new FixedClock($occurredAt)
            );

            try {
                $handler->handle(CommandMessage::create($command));
                self::fail(sprintf('The %s post-preflight persistence race must fail closed.', $scenario));
            } catch (LogicException) {
                ++$failures;
            }

            self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
        }

        self::assertSame(6, $failures);
    }

    public function test_final_permission_removal_rejects_a_role_reference_added_after_preflight_and_rolls_back(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $roles = new InMemoryRoleRepository($unitOfWork);
        $permission = Permission::defineManaged(
            $this->permissionId(104),
            PermissionName::fromString('OBSOLETE'),
            PermissionTier::ADMIN_SAFE,
            new DateTimeImmutable('2026-08-01T00:00:00+00:00')
        );
        $permissions = null;
        $permissions = new InMemoryPermissionRepository(
            $unitOfWork,
            beforeRemove: static function () use (&$permissions, $permission, $roles, $unitOfWork): void {
                self::assertInstanceOf(InMemoryPermissionRepository::class, $permissions);
                self::assertTrue($unitOfWork->authorizationReferenceState()->isReferenceFenceHeld());
                $roles->add(Role::define(
                    RoleId::fromString('018f0000-0000-7000-8000-000000000290'),
                    RoleName::fromString('ROLE_LATE_REFERENCE'),
                    [$permission->getId()],
                    new DateTimeImmutable('2026-08-23T12:00:00+00:00')
                ));
            }
        );
        $permissions->add($permission);

        $events = new InMemoryEventDispatcher();
        $failure = null;
        $handler = new ReconcileManagedPolicyHandler(
            $permissions,
            $roles,
            new ManagedPolicyPlanner(
                $permissions,
                $roles,
                new InMemoryUserRepository($unitOfWork)
            ),
            $unitOfWork,
            $events,
            new FixedClock(new DateTimeImmutable('2026-08-23T12:00:00+00:00'))
        );

        try {
            $handler->handle(CommandMessage::create(new ReconcileManagedPolicy([], [], [])));
            self::fail('A newly live Role reference must block final Permission removal.');
        } catch (LogicException $logicException) {
            $failure = $logicException;
        }

        self::assertSame(
            'Managed permission changed or became referenced after preflight.',
            $failure->getMessage()
        );
        self::assertSame($permission, $permissions->getById($permission->getId()));
        self::assertNull($roles->getById(RoleId::fromString('018f0000-0000-7000-8000-000000000290')));
        self::assertFalse($unitOfWork->transactionCompleted);
        self::assertCount(1, $events->events());
        self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
        self::assertSame($failure->getMessage(), $events->events()[0]->getErrorMessage());
    }

    private function command(): ReconcileManagedPolicy
    {
        return new ReconcileManagedPolicy(
            [
                $this->permission(103, 'ARCHIVE_USERS', PermissionTier::ADMIN_SAFE),
                $this->permission(102, 'MANAGE_USERS', PermissionTier::SUPER_ADMIN_ONLY),
                $this->permission(101, 'VIEW_USERS', PermissionTier::ADMIN_SAFE),
            ],
            [
                $this->role(203, 'ROLE_ADMIN', [102, 103]),
                $this->role(202, 'ROLE_EDITOR', [102, 103]),
                $this->role(201, 'ROLE_VIEWER', [101]),
            ],
            [$this->permissionId(101)]
        );
    }

    private function permission(int $suffix, string $name, PermissionTier $tier): ManagedPermissionDefinition
    {
        return new ManagedPermissionDefinition(
            $this->permissionId($suffix),
            PermissionName::fromString($name),
            $tier
        );
    }

    /** @phpstan-param list<int> $permissionSuffixes */
    private function role(int $suffix, string $name, array $permissionSuffixes): ManagedRoleDefinition
    {
        return new ManagedRoleDefinition(
            $this->roleId($suffix),
            RoleName::fromString($name),
            array_map(
                $this->permissionId(...),
                $permissionSuffixes
            )
        );
    }

    private function permissionId(int $suffix): PermissionId
    {
        return PermissionId::fromString(sprintf('018f0000-0000-7000-8000-%012d', $suffix));
    }

    /**
     * @return array{id: string, name: string, tier: string, action: string}
     */
    private function permissionPlan(int $suffix, string $name, string $tier, string $action): array
    {
        return [
            'id' => $this->permissionId($suffix)->toString(),
            'name' => $name,
            'tier' => $tier,
            'action' => $action,
        ];
    }

    /**
     * @phpstan-param list<int> $permissionSuffixes
     *
     * @return array{id: string, name: string, permission_ids: list<string>, action: string}
     */
    private function rolePlan(int $suffix, string $name, array $permissionSuffixes, string $action): array
    {
        return [
            'id' => $this->roleId($suffix)->toString(),
            'name' => $name,
            'permission_ids' => array_map(
                fn(int $permissionSuffix): string => $this->permissionId($permissionSuffix)->toString(),
                $permissionSuffixes
            ),
            'action' => $action,
        ];
    }

    private function roleId(int $suffix): RoleId
    {
        return RoleId::fromString(sprintf('018f0000-0000-7000-9000-%012d', $suffix));
    }
}
