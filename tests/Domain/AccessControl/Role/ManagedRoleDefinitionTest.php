<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\Role;

use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Role\Exception\ManagedRoleDefinitionException;
use Fight\AccessControl\Domain\AccessControl\Role\ManagedRoleDefinition;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\Role\RoleName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ManagedRoleDefinition::class)]
#[CoversClass(ManagedRoleDefinitionException::class)]
final class ManagedRoleDefinitionTest extends TestCase
{
    public function test_it_round_trips_exact_permission_membership(): void
    {
        $definition = new ManagedRoleDefinition(
            RoleId::fromString('018f0000-0000-7000-8000-000000000201'),
            RoleName::fromString('ROLE_ADMIN'),
            [
                PermissionId::fromString('018f0000-0000-7000-8000-000000000102'),
                PermissionId::fromString('018f0000-0000-7000-8000-000000000101'),
            ]
        );

        self::assertSame('018f0000-0000-7000-8000-000000000201', $definition->getId()->toString());
        self::assertSame('ROLE_ADMIN', $definition->getName()->toString());
        self::assertSame(
            [
                '018f0000-0000-7000-8000-000000000102',
                '018f0000-0000-7000-8000-000000000101',
            ],
            array_map(
                static fn(PermissionId $permissionId): string => $permissionId->toString(),
                $definition->getPermissionIds()
            )
        );
        self::assertSame(
            [
                'id' => '018f0000-0000-7000-8000-000000000201',
                'name' => 'ROLE_ADMIN',
                'permission_ids' => [
                    '018f0000-0000-7000-8000-000000000102',
                    '018f0000-0000-7000-8000-000000000101',
                ],
            ],
            $definition->toArray()
        );
        self::assertEquals($definition, ManagedRoleDefinition::fromArray($definition->toArray()));
    }

    public function test_it_rejects_duplicate_exact_membership(): void
    {
        $this->expectException(ManagedRoleDefinitionException::class);
        $this->expectExceptionMessage(
            'Managed role "ROLE_ADMIN" contains duplicate permission "018f0000-0000-7000-8000-000000000101".'
        );

        new ManagedRoleDefinition(
            RoleId::fromString('018f0000-0000-7000-8000-000000000201'),
            RoleName::fromString('ROLE_ADMIN'),
            [
                PermissionId::fromString('018f0000-0000-7000-8000-000000000101'),
                PermissionId::fromString('018f0000-0000-7000-8000-000000000101'),
            ]
        );
    }

    public function test_it_rejects_missing_or_non_array_serialized_membership(): void
    {
        $complete = [
            'id' => '018f0000-0000-7000-8000-000000000201',
            'name' => 'ROLE_ADMIN',
            'permission_ids' => [],
        ];
        $rejections = 0;

        foreach (['id', 'name', 'permission_ids'] as $field) {
            $incomplete = $complete;
            unset($incomplete[$field]);

            try {
                ManagedRoleDefinition::fromArray($incomplete);
                self::fail('A missing managed role field must be rejected.');
            } catch (ManagedRoleDefinitionException) {
                ++$rejections;
            }
        }

        try {
            ManagedRoleDefinition::fromArray([
                'id' => '018f0000-0000-7000-8000-000000000201',
                'name' => 'ROLE_ADMIN',
                'permission_ids' => 'not-an-array',
            ]);
            self::fail('Non-array membership must be rejected.');
        } catch (ManagedRoleDefinitionException) {
            ++$rejections;
        }

        self::assertSame(4, $rejections);
    }
}
