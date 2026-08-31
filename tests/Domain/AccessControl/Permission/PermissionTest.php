<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\Permission;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\Permission\Exception\ManagedPermissionException;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionTier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Permission::class)]
final class PermissionTest extends TestCase
{
    public function test_it_defines_a_permission_with_stable_identity_and_name(): void
    {
        $id = PermissionId::generate();
        $name = PermissionName::fromString('MANAGE_USERS');

        $permission = Permission::define($id, $name, new DateTimeImmutable('2026-01-01T00:00:00+00:00'));

        self::assertSame($id, $permission->getId());
        self::assertSame($name, $permission->getName());

        self::assertInstanceOf(DateTimeImmutable::class, $permission->getCreatedAt());
        self::assertEquals(new DateTimeImmutable('2026-01-01T00:00:00+00:00'), $permission->getCreatedAt());
        self::assertInstanceOf(DateTimeImmutable::class, $permission->getUpdatedAt());
        self::assertEquals(new DateTimeImmutable('2026-01-01T00:00:00+00:00'), $permission->getUpdatedAt());
    }

    public function test_it_is_extensible_through_its_factory(): void
    {
        $id = PermissionId::generate();
        $name = PermissionName::fromString('VIEW_USERS');
        $permission = ExtensiblePermission::define($id, $name, new DateTimeImmutable('2026-01-01T00:00:00+00:00'));

        self::assertInstanceOf(ExtensiblePermission::class, $permission);
    }

    public function test_it_reconciles_only_managed_permissions(): void
    {
        $createdAt = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $managed = Permission::defineManaged(
            PermissionId::generate(),
            PermissionName::fromString('OLD_NAME'),
            PermissionTier::ADMIN_SAFE,
            $createdAt
        );
        $updatedAt = new DateTimeImmutable('2026-01-02T00:00:00+00:00');
        $replacement = $managed->reconcileManaged(
            PermissionName::fromString('NEW_NAME'),
            PermissionTier::SUPER_ADMIN_ONLY,
            $updatedAt
        );

        self::assertTrue($replacement->isManaged());
        self::assertSame(PermissionTier::SUPER_ADMIN_ONLY, $replacement->getTier());
        self::assertSame('NEW_NAME', $replacement->getName()->toString());
        self::assertSame($createdAt, $replacement->getCreatedAt());
        self::assertSame($updatedAt, $replacement->getUpdatedAt());

        $custom = Permission::define(
            PermissionId::generate(),
            PermissionName::fromString('CUSTOM'),
            $createdAt
        );
        try {
            $custom->getManagedTier();
            self::fail('A custom permission must not expose a managed tier.');
        } catch (ManagedPermissionException) {
            self::assertNull($custom->getTier());
        }

        $this->expectException(ManagedPermissionException::class);
        $custom->reconcileManaged(
            PermissionName::fromString('CLAIMED'),
            PermissionTier::ADMIN_SAFE,
            $updatedAt
        );
    }
}
