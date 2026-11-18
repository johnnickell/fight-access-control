<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\ManagedPolicy\QueryHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\ManagedPolicy\QueryHandler\PreviewManagedPolicyHandler;
use Fight\AccessControl\Application\AccessControl\ManagedPolicy\Service\ManagedPolicyPlanner;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentRepository;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\ManagedPermissionDefinition;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\ManagedPolicy;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\ManagedRoleDefinition;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\Query\PreviewManagedPolicy;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionRepository;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionTier;
use Fight\AccessControl\Domain\AccessControl\Permission\Query\GetPermissionById;
use Fight\AccessControl\Domain\AccessControl\Role\Exception\ManagedRoleException;
use Fight\AccessControl\Domain\AccessControl\Role\Role;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\Role\RoleName;
use Fight\AccessControl\Domain\AccessControl\Role\RoleRepository;
use Fight\AccessControl\Domain\AccessControl\User\UserRepository;
use Fight\Common\Domain\Messaging\Query\QueryMessage;
use Fight\Test\AccessControl\Domain\AccessControl\Role\ImpersonatingRole;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use UnexpectedValueException;

#[CoversClass(ManagedPolicyPlanner::class)]
#[CoversClass(PreviewManagedPolicyHandler::class)]
final class ManagedPolicyReadContractTest extends TestCase
{
    public function test_wrong_query_payload_is_an_internal_contract_fault(): void
    {
        $handler = new PreviewManagedPolicyHandler(new ManagedPolicyPlanner(
            $this->createStub(PermissionRepository::class),
            $this->createStub(RoleRepository::class),
            $this->createStub(UserRepository::class),
            $this->createStub(AgentRepository::class)
        ));
        $this->expectException(UnexpectedValueException::class);
        $handler->handle(QueryMessage::create(new GetPermissionById(PermissionId::generate())));
    }

    public function test_reference_batches_cannot_spoof_reserved_role_identity_to_approve_promotion(): void
    {
        $now = new DateTimeImmutable('2026-01-01');
        $id = PermissionId::generate();
        $name = PermissionName::fromString('READ_CASES');
        $permission = Permission::defineManaged($id, $name, PermissionTier::ADMIN_SAFE, $now);
        $spoof = ImpersonatingRole::defineManaged(
            RoleId::generate(),
            RoleName::fromString('ROLE_EDITOR'),
            [$id],
            $now
        );
        $permissions = $this->createStub(PermissionRepository::class);
        $permissions->method('getById')->willReturn($permission);
        $roles = $this->createStub(RoleRepository::class);
        $roles->method('getContainingPermission')->willReturn([$spoof]);
        $roles->method('getByName')->willReturn($spoof);
        $planner = new ManagedPolicyPlanner(
            $permissions,
            $roles,
            $this->createStub(UserRepository::class),
            $this->createStub(AgentRepository::class)
        );
        $this->expectException(ManagedRoleException::class);
        $planner->plan(new ManagedPolicy([
            new ManagedPermissionDefinition($id, $name, PermissionTier::SUPER_ADMIN_ONLY)
        ], []));
    }

    /** @return iterable<string, array{string}> */
    public static function faults(): iterable
    {
        foreach (
            [
            'permission-id', 'permission-name', 'role-id', 'role-name',
            'managed-permission-custom', 'managed-permission-duplicate', 'managed-permission-name',
            'managed-permission-element', 'managed-permission-keys',
            'managed-role-custom', 'managed-role-duplicate', 'managed-role-name',
            'managed-role-element', 'managed-role-keys',
            'containing-unrelated', 'containing-duplicate', 'containing-name', 'containing-element', 'containing-keys',
            'promotion-unrelated'
            ] as $fault
        ) {
            yield $fault => [$fault];
        }
    }

    #[DataProvider('faults')]
    public function test_invalid_reads_cannot_produce_a_plan_or_rule_refusal(string $fault): void
    {
        $now = new DateTimeImmutable('2026-01-01');
        $id = PermissionId::generate();
        $name = PermissionName::fromString('READ_CASES');
        $permission = Permission::defineManaged($id, $name, PermissionTier::ADMIN_SAFE, $now);
        $other = Permission::defineManaged(PermissionId::generate(), $name, PermissionTier::ADMIN_SAFE, $now);
        $roleId = RoleId::generate();
        $roleName = RoleName::fromString('ROLE_READER');
        $role = Role::defineManaged($roleId, $roleName, [$id], $now);
        $otherRole = Role::defineManaged(RoleId::generate(), $roleName, [$id], $now);
        $unrelated = Role::defineManaged(RoleId::generate(), RoleName::fromString('ROLE_OTHER'), [], $now);
        $permissions = $this->createStub(PermissionRepository::class);
        $roles = $this->createStub(RoleRepository::class);
        $permissions->method('getManaged')->willReturn(match ($fault) {
            'managed-permission-custom' => [Permission::define($id, $name, $now)],
            'managed-permission-duplicate' => [$permission, $permission],
            'managed-permission-name' => [$permission, $other],
            'managed-permission-element' => [new stdClass()],
            'managed-permission-keys' => ['bad' => $permission],
            'containing-unrelated', 'containing-duplicate', 'containing-name',
            'containing-element', 'containing-keys' => [$permission],
            default => [],
        });
        $roles->method('getManaged')->willReturn(match ($fault) {
            'managed-role-custom' => [Role::define($roleId, $roleName, [], $now)],
            'managed-role-duplicate' => [$role, $role],
            'managed-role-name' => [$role, $otherRole],
            'managed-role-element' => [new stdClass()],
            'managed-role-keys' => ['bad' => $role],
            default => [],
        });
        $roles->method('getContainingPermission')->willReturn(match ($fault) {
            'containing-unrelated', 'promotion-unrelated' => [$unrelated],
            'containing-duplicate' => [$role, $role],
            'containing-name' => [$role, $otherRole],
            'containing-element' => [new stdClass()],
            'containing-keys' => ['bad' => $role],
            default => [],
        });
        $permissions->method('getById')->willReturn(match ($fault) {
            'permission-id' => $other,
            'promotion-unrelated' => $permission,
            default => null,
        });
        $byName = null;
        if ($fault === 'permission-name') {
            $byName = Permission::defineManaged(
                $id,
                PermissionName::fromString('OTHER'),
                PermissionTier::ADMIN_SAFE,
                $now
            );
        }

        $permissions->method('getByName')->willReturn($byName);
        $roles->method('getById')->willReturn($fault === 'role-id' ? $otherRole : null);
        $roles->method('getByName')->willReturnCallback(static fn(RoleName $requested): ?Role =>
            $fault === 'role-name' && $requested->equals($roleName) ? $unrelated : null);
        $desiredPermissions = [];
        if (in_array($fault, ['permission-id', 'permission-name', 'promotion-unrelated'], true)) {
            $desiredPermissions = [new ManagedPermissionDefinition($id, $name, PermissionTier::SUPER_ADMIN_ONLY)];
        }

        $desiredRoles = [];
        if (in_array($fault, ['role-id', 'role-name'], true)) {
            $desiredRoles = [new ManagedRoleDefinition($roleId, $roleName, [])];
        }

        $handler = new PreviewManagedPolicyHandler(new ManagedPolicyPlanner(
            $permissions,
            $roles,
            $this->createStub(UserRepository::class),
            $this->createStub(AgentRepository::class)
        ));

        $this->expectException(UnexpectedValueException::class);
        $handler->handle(QueryMessage::create(new PreviewManagedPolicy(new ManagedPolicy(
            $desiredPermissions,
            $desiredRoles
        ))));
    }
}
