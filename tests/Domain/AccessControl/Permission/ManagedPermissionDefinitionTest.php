<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\Permission;

use Fight\AccessControl\Domain\AccessControl\Permission\Exception\ManagedPolicyDefinitionException;
use Fight\AccessControl\Domain\AccessControl\Permission\ManagedPermissionDefinition;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionTier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ManagedPermissionDefinition::class)]
#[CoversClass(PermissionTier::class)]
final class ManagedPermissionDefinitionTest extends TestCase
{
    public function test_it_carries_a_stable_identity_canonical_name_and_required_tier(): void
    {
        $definition = new ManagedPermissionDefinition(
            PermissionId::fromString('018f0000-0000-7000-8000-000000000101'),
            PermissionName::fromString('MANAGE_USERS'),
            PermissionTier::SUPER_ADMIN_ONLY
        );

        self::assertSame('018f0000-0000-7000-8000-000000000101', $definition->getId()->toString());
        self::assertSame('MANAGE_USERS', $definition->getName()->toString());
        self::assertSame('SUPER_ADMIN_ONLY', $definition->getTier()->value);
        self::assertSame(
            [
                'id' => '018f0000-0000-7000-8000-000000000101',
                'name' => 'MANAGE_USERS',
                'tier' => 'SUPER_ADMIN_ONLY',
            ],
            $definition->toArray()
        );
        self::assertEquals($definition, ManagedPermissionDefinition::fromArray($definition->toArray()));
    }

    public function test_it_supports_only_the_two_approved_permission_tiers(): void
    {
        self::assertSame(
            ['ADMIN_SAFE', 'SUPER_ADMIN_ONLY'],
            array_map(static fn(PermissionTier $tier): string => $tier->value, PermissionTier::cases())
        );
    }

    public function test_it_rejects_each_missing_serialized_field(): void
    {
        $complete = [
            'id' => '018f0000-0000-7000-8000-000000000101',
            'name' => 'MANAGE_USERS',
            'tier' => 'ADMIN_SAFE',
        ];
        $rejections = 0;

        foreach (['id', 'name', 'tier'] as $field) {
            $incomplete = $complete;
            unset($incomplete[$field]);

            try {
                ManagedPermissionDefinition::fromArray($incomplete);
                self::fail('A missing managed permission field must be rejected.');
            } catch (ManagedPolicyDefinitionException) {
                ++$rejections;
            }
        }

        self::assertSame(3, $rejections);
    }
}
