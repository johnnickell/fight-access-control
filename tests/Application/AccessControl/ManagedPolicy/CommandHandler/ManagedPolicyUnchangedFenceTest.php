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
use Fight\AccessControl\Domain\AccessControl\Role\Exception\ManagedRoleException;
use Fight\AccessControl\Domain\AccessControl\Role\Role;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\Role\RoleName;
use Fight\AccessControl\Domain\AccessControl\Role\RoleRepository;
use Fight\AccessControl\Domain\AccessControl\User\UserRepository;
use Fight\Common\Application\Error\Exception\StateConflict;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;
use UnexpectedValueException;

#[CoversClass(ReconcileManagedPolicyHandler::class)]
final class ManagedPolicyUnchangedFenceTest extends TestCase
{
    /** @return iterable<string, array{bool, string}> */
    public static function families(): iterable
    {
        foreach ([false, true] as $isRole) {
            foreach (['fence', 'missing', 'identity', 'changed', 'custom', 'success'] as $mode) {
                yield ($isRole ? 'role' : 'permission').'-'.$mode => [$isRole, $mode];
            }
        }
    }

    #[DataProvider('families')]
    public function test_unchanged_definitions_retain_exact_state_and_final_fences(bool $isRole, string $mode): void
    {
        $now = new DateTimeImmutable('2026-01-01');
        $permission = Permission::defineManaged(
            PermissionId::generate(),
            PermissionName::fromString('READ_CASES'),
            PermissionTier::ADMIN_SAFE,
            $now
        );
        $role = Role::defineManaged(RoleId::generate(), RoleName::fromString('ROLE_READER'), [], $now);
        $permissions = $this->createMock(PermissionRepository::class);
        $roles = $this->createMock(RoleRepository::class);
        $permissionResult = match ($mode) {
            'missing' => null,
            'identity' => Permission::defineManaged(
                PermissionId::generate(),
                $permission->getName(), $permission->getTier(), $now
            ),
            'changed' => $permission->reconcileManaged(
                PermissionName::fromString('CHANGED'),
                $permission->getTier(), $now
            ),
            'custom' => Permission::define($permission->getId(), $permission->getName(), $now),
            default => $permission
        };
        $roleResult = match ($mode) {
            'missing' => null,
            'identity' => Role::defineManaged(RoleId::generate(), $role->getName(), [], $now),
            'changed' => $role->reconcileManaged(RoleName::fromString('ROLE_CHANGED'), [], $now),
            'custom' => Role::define($role->getId(), $role->getName(), [], $now),
            default => $role
        };
        $permissions->method('getById')->willReturn($permission, $permissionResult);
        $roles->method('getById')->willReturn($role, $roleResult);
        $permissions->method('getManaged')->willReturn([]);
        $roles->method('getManaged')->willReturn([]);
        ($isRole ? $permissions : $roles)->expects(self::never())->method('replace');
        $target = $isRole ? $roles : $permissions;
        $expected = $isRole ? $role : $permission;
        if (in_array($mode, ['fence', 'success'], true)) {
            $target->expects(self::once())->method('replace')->with(
                self::identicalTo($expected),
                self::identicalTo($expected)
            )->willReturn($mode === 'success');
        } else {
            $target->expects(self::never())->method('replace');
        }

        $policy = new ManagedPolicy(
            $isRole ? [] : [new ManagedPermissionDefinition(
                $permission->getId(),
                $permission->getName(),
                $permission->getTier()
            )],
            $isRole ? [new ManagedRoleDefinition($role->getId(), $role->getName(), [])] : []
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
        $failure = null;
        try {
            $handler->handle(CommandMessage::create(new ReconcileManagedPolicy($policy)));
        } catch (Throwable $throwable) {
            $failure = $throwable;
        }

        self::assertSame($mode === 'success', $unitOfWork->transactionCompleted);
        self::assertCount(1, $events->events());
        if ($mode === 'success') {
            self::assertNull($failure);
            self::assertSame($now, $expected->getUpdatedAt());
        } else {
            $exception = match (true) {
                $mode === 'identity' => UnexpectedValueException::class,
                $isRole && $mode === 'custom' => ManagedRoleException::class,
                default => StateConflict::class
            };
            self::assertInstanceOf($exception, $failure);
            self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
        }
    }
}
