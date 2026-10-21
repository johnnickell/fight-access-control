<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\Role;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Role\Exception\RoleConflictException;
use Fight\AccessControl\Domain\AccessControl\Role\Exception\RoleRevisionException;
use Fight\AccessControl\Domain\AccessControl\Role\Role;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\Role\RoleName;
use Fight\AccessControl\Domain\AccessControl\Role\RoleRevision;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(RoleRevision::class)]
#[CoversClass(Role::class)]
#[CoversClass(RoleConflictException::class)]
final class RoleRevisionTest extends TestCase
{
    public function test_incarnation_round_trip_and_same_clock_name_and_membership_aba_never_revive_intent(): void
    {
        $now = new DateTimeImmutable('2030-01-01T00:00:00Z');
        $role = Role::define(RoleId::generate(), RoleName::fromString('ROLE_A'), [], $now);
        $initial = $role->getRevision();
        $restored = RoleRevision::fromString($initial->toString());
        self::assertTrue($initial->equals($restored));
        self::assertStringEndsWith(':1', $initial->toString());
        self::assertFalse($initial->equals(RoleRevision::initial()));
        $renamed = $role->renameCustom(RoleName::fromString('ROLE_B'), $now);
        $back = $renamed->renameCustom($role->getName(), $now);
        $permission = PermissionId::generate();
        $granted = $back->grantPermissionToCustom($permission, $now);
        $revoked = $granted->revokePermissionFromCustom($permission, $now);
        foreach ([$role, $renamed, $back, $granted, $revoked] as $index => $state) {
            self::assertSame($now, $state->getUpdatedAt());
            self::assertStringEndsWith(':'.($index + 1), $state->getRevision()->toString());
            self::assertSame(substr($initial->toString(), 0, 37), substr($state->getRevision()->toString(), 0, 37));
        }

        self::assertSame($role, $role->renameCustom($role->getName(), $now->modify('+1 second')));
        self::assertSame($granted, $granted->grantPermissionToCustom($permission, $now->modify('+1 second')));
        self::assertSame($revoked, $revoked->revokePermissionFromCustom($permission, $now->modify('+1 second')));
        $role->assertRevision($restored);
        $recreated = Role::define($role->getId(), $role->getName(), [], $now);
        self::assertFalse($initial->equals($recreated->getRevision()));
        foreach ([$back, $revoked, $recreated] as $stale) {
            try {
                $stale->assertRevision($initial);
                self::fail('Old intent was revived.');
            } catch (RoleConflictException $caught) {
                self::assertSame(
                    'The Role state is no longer current. Refresh and reconfirm the operation.',
                    $caught->getMessage()
                );
            }
        }
    }

    public function test_managed_changes_advance_once_but_equal_membership_sets_are_no_ops(): void
    {
        $now = new DateTimeImmutable('2030-01-01T00:00:00Z');
        $first = PermissionId::generate();
        $second = PermissionId::generate();
        $role = Role::defineManaged(RoleId::generate(), RoleName::fromString('ROLE_A'), [$first, $second], $now);
        self::assertSame($role, $role->reconcileManaged($role->getName(), [$second, $first, $first], $now));
        $renamed = $role->reconcileManaged(RoleName::fromString('ROLE_B'), [$first, $second], $now);
        $changed = $renamed->reconcileManaged($renamed->getName(), [$second], $now);
        $back = $changed->reconcileManaged($role->getName(), [$first, $second], $now);
        self::assertTrue($role->getRevision()->next()->equals($renamed->getRevision()));
        self::assertTrue($renamed->getRevision()->next()->equals($changed->getRevision()));
        self::assertTrue($changed->getRevision()->next()->equals($back->getRevision()));
        self::assertFalse($role->getRevision()->equals($back->getRevision()));
    }

    public function test_hydration_preserves_authority_and_exhaustion_rejects_changes_atomically(): void
    {
        $now = new DateTimeImmutable('2030-01-01T00:00:00Z');
        $permission = PermissionId::generate();
        foreach ([false, true] as $managed) {
            $revision = RoleRevision::fromString('11111111-1111-4111-8111-111111111111:'.RoleRevision::MAX_SEQUENCE);
            $role = Role::reconstitute(
                RoleId::generate(),
                RoleName::fromString('ROLE_A'),
                [$permission],
                $managed,
                $now,
                $now,
                $revision
            );
            self::assertSame($revision, $role->getRevision());
            self::assertTrue($managed === $role->isManaged());
            $operations = [fn(): Role => $role->reconcileManaged(RoleName::fromString('ROLE_B'), [], $now)];
            if (!$managed) {
                self::assertSame($role, $role->renameCustom($role->getName(), $now));
                $operations = [
                    fn(): Role => $role->renameCustom(RoleName::fromString('ROLE_B'), $now),
                    fn(): Role => $role->grantPermissionToCustom(PermissionId::generate(), $now),
                    fn(): Role => $role->revokePermissionFromCustom($permission, $now)
                ];
            } else {
                self::assertSame($role, $role->reconcileManaged($role->getName(), [$permission], $now));
            }

            foreach ($operations as $operation) {
                try {
                    $operation();
                    self::fail('Exhaustion must reject without wraparound.');
                } catch (RoleRevisionException $caught) {
                    self::assertSame('The Role revision is exhausted.', $caught->getMessage());
                    self::assertSame($revision, $role->getRevision());
                    self::assertSame('ROLE_A', $role->getName()->toString());
                    self::assertSame([$permission], $role->getPermissionIds());
                }
            }
        }
    }

    public function test_complete_state_equality_ignores_collection_iteration_but_includes_every_persisted_value(): void
    {
        $now = new DateTimeImmutable('2030-01-01T00:00:00.123456Z');
        $first = PermissionId::generate();
        $second = PermissionId::generate();
        $role = Role::define(RoleId::generate(), RoleName::fromString('ROLE_A'), [$first, $second], $now);
        $equivalent = Role::reconstitute(
            $role->getId(),
            $role->getName(),
            [PermissionId::fromString($second->toString()), PermissionId::fromString($first->toString())],
            false,
            $now,
            $now,
            RoleRevision::fromString($role->getRevision()->toString())
        );
        $role->getPermissionIds();
        self::assertTrue($role->hasSameState($equivalent));
        self::assertTrue($equivalent->hasSameState($role));
        $values = [$role->getId(), $role->getName(), [$first, $second], false, $now, $now, $role->getRevision()];
        $changes = [
            RoleId::generate(),
            RoleName::fromString('ROLE_B'),
            [$first],
            true,
            $now->modify('+1 microsecond'),
            $now->modify('+1 microsecond'),
            $role->getRevision()->next()
        ];
        foreach ($changes as $key => $change) {
            $changed = $values;
            $changed[$key] = $change;
            self::assertFalse($role->hasSameState(Role::reconstitute(...$changed)));
        }

        $differentMembership = Role::reconstitute(
            $role->getId(),
            $role->getName(),
            [$first, PermissionId::generate()],
            false,
            $now,
            $now,
            $role->getRevision()
        );
        self::assertFalse($role->hasSameState($differentMembership));
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidRevisions(): iterable
    {
        $prefix = '11111111-1111-4111-8111-111111111111:';
        foreach (
            [null, 1, false, [], '', ' ', $prefix.'0', $prefix.'01', $prefix.'-1', $prefix.'1.0',
            $prefix.'1000000000', $prefix.'999999999999999999999', $prefix.'1\n', "{$prefix}1\n",
            '11111111-1111-7111-8111-111111111111:1', '11111111-1111-4111-0111-111111111111:1',
            'AAAAAAAA-1111-4111-8111-111111111111:1'] as $index => $invalid
        ) {
            yield 'invalid '.$index => [$invalid];
        }
    }

    #[DataProvider('invalidRevisions')]
    public function test_invalid_current_state_rejects_without_echoing_input(mixed $invalid): void
    {
        $this->expectException(RoleRevisionException::class);
        $this->expectExceptionMessage('The Role revision is invalid.');
        RoleRevision::fromString($invalid);
    }
}
