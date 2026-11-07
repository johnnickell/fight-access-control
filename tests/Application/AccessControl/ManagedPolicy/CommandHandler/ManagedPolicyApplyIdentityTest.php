<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\ManagedPolicy\CommandHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\ManagedPolicy\CommandHandler\ReconcileManagedPolicyHandler;
use Fight\AccessControl\Application\AccessControl\ManagedPolicy\Service\ManagedPolicyPlanner;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentRepository;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\Command\ReconcileManagedPolicy;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\ManagedPermissionDefinition;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\ManagedPolicy;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\ManagedRoleDefinition;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionRepository;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionTier;
use Fight\AccessControl\Domain\AccessControl\Role\Role;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\Role\RoleName;
use Fight\AccessControl\Domain\AccessControl\Role\RoleRepository;
use Fight\AccessControl\Domain\AccessControl\User\UserRepository;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

#[CoversClass(ReconcileManagedPolicyHandler::class)]
final class ManagedPolicyApplyIdentityTest extends TestCase
{
    /** @return iterable<string, array{bool, bool, bool}> */
    public static function actions(): iterable
    {
        yield 'reconcile-permission' => [false, false, false];
        yield 'remove-permission' => [false, true, false];
        yield 'reconcile-role' => [true, false, false];
        yield 'remove-role' => [true, true, false];
        yield 'remove-custom-permission' => [false, true, true];
        yield 'remove-custom-role' => [true, true, true];
    }

    #[DataProvider('actions')]
    public function test_apply_rejects_wrong_identity_or_ownership_after_planning(
        bool $isRole,
        bool $remove,
        bool $custom
    ): void {
        $now = new DateTimeImmutable('2026-01-01');
        $permission = Permission::defineManaged(
            PermissionId::generate(),
            PermissionName::fromString('READ_CASES'),
            PermissionTier::ADMIN_SAFE,
            $now
        );
        $wrongPermission = Permission::defineManaged(
            PermissionId::generate(),
            PermissionName::fromString('OTHER'),
            PermissionTier::ADMIN_SAFE,
            $now
        );
        $role = Role::defineManaged(RoleId::generate(), RoleName::fromString('ROLE_READER'), [], $now);
        $wrongRole = Role::defineManaged(RoleId::generate(), RoleName::fromString('ROLE_OTHER'), [], $now);
        if ($custom) {
            $wrongPermission = Permission::define($permission->getId(), $permission->getName(), $now);
            $wrongRole = Role::define($role->getId(), $role->getName(), [], $now);
        }

        $permissions = $this->createMock(PermissionRepository::class);
        $roles = $this->createMock(RoleRepository::class);
        foreach ([$permissions, $roles] as $repository) {
            $repository->expects(self::never())->method('add');
            $repository->expects(self::never())->method('replace');
            $repository->expects(self::never())->method('remove');
        }

        $permissions->method('getManaged')->willReturn(!$isRole && $remove ? [$permission] : []);
        $roles->method('getManaged')->willReturn($isRole && $remove ? [$role] : []);
        $permissionReads = $remove ? [$wrongPermission] : [$permission, $wrongPermission];
        $permissions->method('getById')->willReturn(...$permissionReads);
        $roles->method('getById')->willReturn(...($remove ? [$wrongRole] : [$role, $wrongRole]));
        $policy = new ManagedPolicy(
            !$isRole && !$remove ? [new ManagedPermissionDefinition(
                $permission->getId(),
                PermissionName::fromString('RENAMED'),
                PermissionTier::ADMIN_SAFE
            )] : [],
            $isRole && !$remove ? [new ManagedRoleDefinition(
                $role->getId(),
                RoleName::fromString('ROLE_RENAMED'),
                []
            )] : []
        );
        $unitOfWork = new InMemoryUnitOfWork();
        $events = new InMemoryEventDispatcher();
        $handler = new ReconcileManagedPolicyHandler(
            $permissions,
            $roles,
            new ManagedPolicyPlanner(
                $permissions,
                $roles,
                $this->createStub(UserRepository::class),
                $this->createStub(AgentRepository::class)
            ),
            $unitOfWork,
            $events,
            new FixedClock($now)
        );
        try {
            $handler->handle(CommandMessage::create(new ReconcileManagedPolicy($policy)));
            self::fail('A wrong identity after planning must reject before repository writes.');
        } catch (UnexpectedValueException) {
            self::assertFalse($unitOfWork->transactionCompleted);
            self::assertCount(1, $events->events());
            self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
        }
    }
}
