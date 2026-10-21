<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Role\Repository;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Role\Role;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\Role\RoleName;
use Fight\AccessControl\Domain\AccessControl\Role\RoleRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Consumer bindings run these assertions unchanged against their actual transaction and reopened storage */
#[CoversClass(Role::class)]
abstract class RoleRevisionConformance extends TestCase
{
    public function test_restart_preserves_revision_and_complete_state_cas_rejects_same_clock_name_aba(): void
    {
        $roles = $this->repository();
        $now = new DateTimeImmutable('2030-01-01T00:00:00.123456Z');
        $original = Role::define(RoleId::generate(), RoleName::fromString('ROLE_A'), [], $now);
        $this->transaction(fn() => $roles->add($original));
        $roles = $this->restart($roles);
        $restored = $roles->getById($original->getId());
        self::assertInstanceOf(Role::class, $restored);
        self::assertNotSame($original, $restored);
        $this->assertRoleState($original, $restored);
        $this->transaction(function () use ($roles, $original, $now): void {
            self::assertTrue($roles->validateCurrent($original));
            $changed = $original->renameCustom(RoleName::fromString('ROLE_B'), $now);
            self::assertTrue($roles->replace($original, $changed));
            self::assertTrue($roles->replace($changed, $changed->renameCustom($original->getName(), $now)));
            self::assertFalse($roles->validateCurrent($original));
            self::assertFalse($roles->replace($original, $changed));
            self::assertFalse($roles->remove($original));
        });
        $current = $this->restart($roles)->getById($original->getId());
        self::assertInstanceOf(Role::class, $current);
        self::assertEquals($original->getName(), $current->getName());
        self::assertEquals($original->getUpdatedAt(), $current->getUpdatedAt());
        self::assertTrue($original->getRevision()->next()->next()->equals($current->getRevision()));
    }

    public function test_membership_aba_and_recreation_cannot_reuse_old_authority(): void
    {
        $roles = $this->repository();
        $now = new DateTimeImmutable('2030-01-01T00:00:00Z');
        $permission = $this->permission();
        $role = Role::define(RoleId::generate(), RoleName::fromString('ROLE_A'), [], $now);
        $granted = $role->grantPermissionToCustom($permission, $now);
        $this->transaction(function () use ($roles, $role, $granted): void {
            $roles->add($role);
            self::assertTrue($roles->replace($role, $granted));
        });
        $roles = $this->restart($roles);
        $restored = $roles->getById($role->getId());
        self::assertInstanceOf(Role::class, $restored);
        self::assertNotSame($granted, $restored);
        $this->assertRoleState($granted, $restored);
        self::assertTrue($restored->hasPermission($permission));
        $this->transaction(function () use ($roles, $role, $restored, $permission, $now): void {
            self::assertSame($restored, $restored->grantPermissionToCustom($permission, $now));
            $back = $restored->revokePermissionFromCustom($permission, $now);
            self::assertTrue($roles->replace($restored, $back));
            self::assertFalse($roles->validateCurrent($role));
            self::assertFalse($roles->remove($role));
            self::assertTrue($roles->remove($back));
            $recreated = Role::define($role->getId(), $role->getName(), [], $now);
            $roles->add($recreated);
            self::assertFalse($back->getRevision()->equals($recreated->getRevision()));
            self::assertFalse($role->getRevision()->equals($recreated->getRevision()));
            self::assertFalse($roles->validateCurrent($back));
            self::assertFalse($roles->remove($back));
            self::assertFalse($roles->replace($back, $restored));
        });
        $current = $this->restart($roles)->getById($role->getId());
        self::assertInstanceOf(Role::class, $current);
        self::assertSame([], $current->getPermissionIds());
        self::assertFalse($role->getRevision()->equals($current->getRevision()));
    }

    public function test_failed_transaction_restores_revision_and_state_including_after_restart(): void
    {
        $roles = $this->repository();
        $now = new DateTimeImmutable('2030-01-01T00:00:00Z');
        $original = Role::define(RoleId::generate(), RoleName::fromString('ROLE_A'), [], $now);
        $this->transaction(fn() => $roles->add($original));
        $failure = new RuntimeException('Synthetic rollback.');
        try {
            $this->transaction(function () use ($roles, $original, $now, $failure): void {
                self::assertTrue($roles->replace(
                    $original,
                    $original->renameCustom(RoleName::fromString('ROLE_B'), $now)
                ));
                throw $failure;
            });
            self::fail('The transaction must throw.');
        } catch (RuntimeException $runtimeException) {
            self::assertSame($failure, $runtimeException);
        }

        $restored = $this->restart($roles)->getById($original->getId());
        self::assertInstanceOf(Role::class, $restored);
        $this->assertRoleState($original, $restored);
    }

    abstract protected function repository(): RoleRepository;

    /** Reopens persisted state and reconstructs values rather than retaining aggregate objects */
    abstract protected function restart(RoleRepository $repository): RoleRepository;

    /** Persists an authoritative ADMIN_SAFE Permission on the same composition */
    abstract protected function permission(): PermissionId;

    /** @param callable(): void $operation */
    abstract protected function transaction(callable $operation): void;

    private function assertRoleState(Role $expected, Role $actual): void
    {
        self::assertTrue($expected->getId()->equals($actual->getId()));
        self::assertTrue($expected->getName()->equals($actual->getName()));
        self::assertSame($expected->isManaged(), $actual->isManaged());
        self::assertEquals($expected->getCreatedAt(), $actual->getCreatedAt());
        self::assertEquals($expected->getUpdatedAt(), $actual->getUpdatedAt());
        self::assertSame($expected->getRevision()->toString(), $actual->getRevision()->toString());
        self::assertEqualsCanonicalizing(
            array_map(static fn(PermissionId $id): string => $id->toString(), $expected->getPermissionIds()),
            array_map(static fn(PermissionId $id): string => $id->toString(), $actual->getPermissionIds())
        );
    }
}
