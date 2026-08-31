<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\ManagedPolicy;

use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\Command\ReconcileManagedPolicy;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\Exception\ManagedPolicyDefinitionException;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\ManagedPermissionDefinition;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\ManagedPolicy;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\ManagedRoleDefinition;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\Query\PreviewManagedPolicy;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionTier;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\Role\RoleName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ManagedPolicy::class)]
#[CoversClass(ManagedPermissionDefinition::class)]
#[CoversClass(ManagedPolicyDefinitionException::class)]
#[CoversClass(ManagedRoleDefinition::class)]
#[CoversClass(PreviewManagedPolicy::class)]
#[CoversClass(ReconcileManagedPolicy::class)]
final class ManagedPolicyTest extends TestCase
{
    public function test_messages_consume_one_complete_policy_without_containing_each_other(): void
    {
        $permissionId = PermissionId::fromString('018f0000-0000-7000-8000-000000000101');
        $policy = new ManagedPolicy(
            [
                new ManagedPermissionDefinition(
                    $permissionId,
                    PermissionName::fromString('MANAGE_USERS'),
                    PermissionTier::SUPER_ADMIN_ONLY
                ),
            ],
            [
                new ManagedRoleDefinition(
                    RoleId::fromString('018f0000-0000-7000-8000-000000000201'),
                    RoleName::fromString('ROLE_ADMIN'),
                    [$permissionId]
                ),
            ],
            [$permissionId]
        );

        $preview = new PreviewManagedPolicy($policy);
        $reconcile = new ReconcileManagedPolicy($policy);

        self::assertSame($policy, $preview->getPolicy());
        self::assertSame($policy, $reconcile->getPolicy());
        self::assertSame($policy->toArray(), $preview->toArray());
        self::assertSame($policy->toArray(), $reconcile->toArray());
        self::assertEquals($preview, PreviewManagedPolicy::fromArray($preview->toArray()));
        self::assertEquals($reconcile, ReconcileManagedPolicy::fromArray($reconcile->toArray()));
    }

    public function test_policy_owns_definitions_references_and_canonical_serialization(): void
    {
        $permissionId = PermissionId::fromString('018f0000-0000-7000-8000-000000000101');
        $permission = new ManagedPermissionDefinition(
            $permissionId,
            PermissionName::fromString('MANAGE_USERS'),
            PermissionTier::SUPER_ADMIN_ONLY
        );
        $role = new ManagedRoleDefinition(
            RoleId::fromString('018f0000-0000-7000-8000-000000000201'),
            RoleName::fromString('ROLE_ADMIN'),
            [$permissionId]
        );
        $policy = new ManagedPolicy([$permission], [$role], [$permissionId]);

        self::assertSame([$permission], $policy->getPermissions());
        self::assertSame([$role], $policy->getRoles());
        self::assertSame([$permissionId], $policy->getReferencedPermissionIds());
        self::assertSame($permissionId, $permission->getId());
        self::assertSame('MANAGE_USERS', $permission->getName()->toString());
        self::assertSame(PermissionTier::SUPER_ADMIN_ONLY, $permission->getTier());
        self::assertSame('ROLE_ADMIN', $role->getName()->toString());
        self::assertSame([$permissionId], $role->getPermissionIds());
        self::assertEquals($permission, ManagedPermissionDefinition::fromArray($permission->toArray()));
        self::assertEquals($role, ManagedRoleDefinition::fromArray($role->toArray()));
        self::assertEquals($policy, ManagedPolicy::fromArray($policy->toArray()));
    }

    public function test_policy_rejects_each_missing_or_non_array_serialized_collection(): void
    {
        $complete = [
            'permissions' => [],
            'roles' => [],
            'referenced_permission_ids' => [],
        ];
        $rejections = 0;

        foreach (array_keys($complete) as $field) {
            $incomplete = $complete;
            unset($incomplete[$field]);

            try {
                ManagedPolicy::fromArray($incomplete);
                self::fail('A missing managed policy collection must be rejected.');
            } catch (ManagedPolicyDefinitionException) {
                ++$rejections;
            }

            $invalid = $complete;
            $invalid[$field] = 'not-an-array';

            try {
                ManagedPolicy::fromArray($invalid);
                self::fail('A non-array managed policy collection must be rejected.');
            } catch (ManagedPolicyDefinitionException) {
                ++$rejections;
            }
        }

        self::assertSame(6, $rejections);
    }

    public function test_policy_rejects_duplicate_definitions_unknown_membership_and_duplicate_references(): void
    {
        $permissionId = PermissionId::fromString('018f0000-0000-7000-8000-000000000101');
        $permission = new ManagedPermissionDefinition(
            $permissionId,
            PermissionName::fromString('MANAGE_USERS'),
            PermissionTier::ADMIN_SAFE
        );
        $otherPermission = new ManagedPermissionDefinition(
            PermissionId::fromString('018f0000-0000-7000-8000-000000000102'),
            PermissionName::fromString('VIEW_USERS'),
            PermissionTier::ADMIN_SAFE
        );
        $role = new ManagedRoleDefinition(
            RoleId::fromString('018f0000-0000-7000-8000-000000000201'),
            RoleName::fromString('ROLE_ADMIN'),
            [$permissionId]
        );
        $otherRole = new ManagedRoleDefinition(
            RoleId::fromString('018f0000-0000-7000-8000-000000000202'),
            RoleName::fromString('ROLE_EDITOR'),
            [$permissionId]
        );
        $rejections = 0;

        foreach (
            [
                [[$permission, $permission], [], []],
                [
                    [
                        $permission,
                        new ManagedPermissionDefinition(
                            $otherPermission->getId(),
                            $permission->getName(),
                            PermissionTier::ADMIN_SAFE
                        ),
                    ],
                    [],
                    [],
                ],
                [[$permission], [$role, $role], []],
                [
                    [$permission],
                    [new ManagedRoleDefinition($otherRole->getId(), $role->getName(), [$permissionId]), $role],
                    [],
                ],
                [[$otherPermission], [$role], []],
                [[$permission], [$role], [$permissionId, $permissionId]],
            ] as [$permissions, $roles, $references]
        ) {
            try {
                new ManagedPolicy($permissions, $roles, $references);
                self::fail('An internally inconsistent managed policy must be rejected.');
            } catch (ManagedPolicyDefinitionException) {
                ++$rejections;
            }
        }

        self::assertSame(6, $rejections);
    }

    public function test_definitions_reject_incomplete_or_duplicate_membership_data(): void
    {
        $permissionData = [
            'id' => '018f0000-0000-7000-8000-000000000101',
            'name' => 'MANAGE_USERS',
            'tier' => 'ADMIN_SAFE',
        ];
        $roleData = [
            'id' => '018f0000-0000-7000-8000-000000000201',
            'name' => 'ROLE_ADMIN',
            'permission_ids' => [],
        ];
        $rejections = 0;

        foreach (array_keys($permissionData) as $field) {
            $incomplete = $permissionData;
            unset($incomplete[$field]);

            try {
                ManagedPermissionDefinition::fromArray($incomplete);
                self::fail('An incomplete managed permission must be rejected.');
            } catch (ManagedPolicyDefinitionException) {
                ++$rejections;
            }
        }

        foreach (array_keys($roleData) as $field) {
            $incomplete = $roleData;
            unset($incomplete[$field]);

            try {
                ManagedRoleDefinition::fromArray($incomplete);
                self::fail('An incomplete managed role must be rejected.');
            } catch (ManagedPolicyDefinitionException) {
                ++$rejections;
            }
        }

        try {
            ManagedRoleDefinition::fromArray([...$roleData, 'permission_ids' => 'not-an-array']);
            self::fail('Non-array managed role membership must be rejected.');
        } catch (ManagedPolicyDefinitionException) {
            ++$rejections;
        }

        try {
            $permissionId = PermissionId::fromString($permissionData['id']);
            new ManagedRoleDefinition(
                RoleId::fromString($roleData['id']),
                RoleName::fromString($roleData['name']),
                [$permissionId, $permissionId]
            );
            self::fail('Duplicate managed role membership must be rejected.');
        } catch (ManagedPolicyDefinitionException) {
            ++$rejections;
        }

        self::assertSame(8, $rejections);
    }

    public function test_definition_deserialization_translates_each_invalid_value_to_a_policy_failure(): void
    {
        $invalidDefinitions = [
            static fn(): ManagedPermissionDefinition => ManagedPermissionDefinition::fromArray([
                'id' => 'not-a-permission-id',
                'name' => 'MANAGE_USERS',
                'tier' => 'ADMIN_SAFE',
            ]),
            static fn(): ManagedPermissionDefinition => ManagedPermissionDefinition::fromArray([
                'id' => '018f0000-0000-7000-8000-000000000101',
                'name' => 'manage_users',
                'tier' => 'ADMIN_SAFE',
            ]),
            static fn(): ManagedPermissionDefinition => ManagedPermissionDefinition::fromArray([
                'id' => '018f0000-0000-7000-8000-000000000101',
                'name' => 'MANAGE_USERS',
                'tier' => 'NOT_A_TIER',
            ]),
            static fn(): ManagedRoleDefinition => ManagedRoleDefinition::fromArray([
                'id' => 'not-a-role-id',
                'name' => 'ROLE_ADMIN',
                'permission_ids' => [],
            ]),
            static fn(): ManagedRoleDefinition => ManagedRoleDefinition::fromArray([
                'id' => '018f0000-0000-7000-8000-000000000201',
                'name' => 'role_admin',
                'permission_ids' => [],
            ]),
            static fn(): ManagedRoleDefinition => ManagedRoleDefinition::fromArray([
                'id' => '018f0000-0000-7000-8000-000000000201',
                'name' => 'ROLE_ADMIN',
                'permission_ids' => ['not-a-permission-id'],
            ]),
        ];
        $rejections = 0;

        foreach ($invalidDefinitions as $invalidDefinition) {
            try {
                $invalidDefinition();
                self::fail('An invalid serialized definition value must be rejected.');
            } catch (ManagedPolicyDefinitionException $managedPolicyDefinitionException) {
                self::assertStringContainsString('Invalid managed ', $managedPolicyDefinitionException->getMessage());
                self::assertNotNull($managedPolicyDefinitionException->getPrevious());
                ++$rejections;
            }
        }

        self::assertSame(6, $rejections);
    }
}
