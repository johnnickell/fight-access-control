<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\User;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\User\Event\RedactedCommandFailed;
use Fight\AccessControl\Domain\AccessControl\User\PasswordHash;
use Fight\AccessControl\Domain\AccessControl\User\Query\UserView;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

#[CoversClass(User::class)]
#[CoversClass(UserView::class)]
#[CoversClass(RedactedCommandFailed::class)]
final class UserTypeContractTest extends TestCase
{
    /** @phpstan-return iterable<string, array{array<string, mixed>}> */
    public static function invalidHydration(): iterable
    {
        foreach (
            [
            'authenticationVersion', 'authenticationAuthorityRevision', 'authorizationAssignmentRevision',
            'emailChangeReservationRevision', 'canonicalEmailRevision'
            ] as $counter
        ) {
            yield $counter => [[$counter => -1]];
        }

        yield 'zero security version' => [['authenticationVersion' => 0]];
        yield 'wrong Role identity' => [['roleIds' => [UserId::generate()]]];
        yield 'scalar Role' => [['roleIds' => ['not-a-role']]];
        yield 'non-list Roles' => [['roleIds' => ['role' => RoleId::generate()]]];
    }

    /** @phpstan-param array<string, mixed> $overrides */
    #[DataProvider('invalidHydration')]
    public function test_hydration_rejects_invalid_counter_and_role_shapes(array $overrides): void
    {
        $this->expectException(DomainException::class);
        self::hydrate($overrides);
    }

    public function test_complete_hydration_preserves_all_persisted_values_and_deduplicates_roles(): void
    {
        $roleId = RoleId::generate();
        $user = self::hydrate([
            'authenticationVersion'           => 9,
            'authenticationAuthorityRevision' => 17,
            'authorizationAssignmentRevision' => 4,
            'emailChangeReservationRevision'  => 6,
            'canonicalEmailRevision'          => 3,
            'roleIds'                         => [$roleId, RoleId::fromString($roleId->toString())],
            'pendingEmailChange'              => EmailAddress::fromString('pending@example.test')
        ]);

        self::assertSame(9, $user->getAuthenticationVersion());
        self::assertSame(17, $user->getAuthenticationAuthorityRevision());
        self::assertSame(4, $user->getAuthorizationAssignmentRevision());
        self::assertSame(6, $user->getEmailChangeReservationRevision());
        self::assertSame(3, $user->getCanonicalEmailRevision());
        self::assertSame('pending@example.test', $user->getPendingEmailChange()?->canonical());
        self::assertCount(1, $user->getRoleIds());
        self::assertTrue($roleId->equals($user->getRoleIds()[0]));
        self::assertSame(9, $user->getRecoveryAuthority()->getAuthenticationVersion());
        self::assertSame(3, $user->getRecoveryAuthority()->getCanonicalEmailRevision());
        self::assertSame(self::snapshot($user), self::snapshot(clone $user));
    }

    public function test_role_replacement_validates_elements_before_mutating_the_existing_set(): void
    {
        $roleId = RoleId::generate();
        $user = self::hydrate(['roleIds' => [$roleId]]);
        $before = self::snapshot($user);
        $method = new ReflectionMethod(User::class, 'replaceRoleAssignments');

        try {
            $method->invoke($user, [RoleId::generate(), UserId::generate()], new DateTimeImmutable());
            self::fail('A foreign identity was accepted as a Role.');
        } catch (DomainException) {
            self::assertSame($before, self::snapshot($user));
        }
    }

    public function test_role_replacement_accepts_a_typed_non_list_iterable_and_preserves_set_no_ops(): void
    {
        $roleId = RoleId::generate();
        $user = self::hydrate(['roleIds' => [$roleId]]);
        $before = self::snapshot($user);
        $user->replaceRoleAssignments((static function () use ($roleId): iterable {
            yield 'first' => $roleId;
            yield 'duplicate' => RoleId::fromString($roleId->toString());
        })(), new DateTimeImmutable());
        self::assertSame($before, self::snapshot($user));
    }

    public function test_user_views_enforce_their_list_of_role_id_contract(): void
    {
        $now = new DateTimeImmutable('2026-11-05T00:00:00+00:00');
        foreach ([['role' => RoleId::generate()], [UserId::generate()], ['not-a-role']] as $roles) {
            try {
                new ReflectionClass(UserView::class)->newInstanceArgs([
                    UserId::generate(), EmailAddress::fromString('view@example.test'), UserState::ACTIVE,
                    $roles, $now, $now
                ]);
                self::fail('An invalid Role list was accepted by the safe view.');
            } catch (DomainException $exception) {
                self::assertStringContainsString('Role', $exception->getMessage());
            }
        }
    }

    public function test_redacted_context_has_named_keys_without_narrowing_legitimate_dynamic_values(): void
    {
        $context = ['user_id' => 'public-id', 'remember' => true, 'details' => ['count' => 2], 'optional' => null];
        $event = new RedactedCommandFailed('Example::operation', $context, 'Operation failed.');
        self::assertSame($context, $event->getRedactedCommandData());
        self::assertSame($event->toArray(), RedactedCommandFailed::fromArray($event->toArray())->toArray());

        $this->expectException(DomainException::class);
        RedactedCommandFailed::fromArray([
            'command_class' => 'Example::operation',
            'command_data'  => ['unnamed'],
            'error_message' => 'Operation failed.'
        ]);
    }

    /** @phpstan-return iterable<string, array{string, string}> */
    public static function exhaustedTransitions(): iterable
    {
        yield 'activation' => ['activate', 'authenticationAuthorityRevision'];
        yield 'login' => ['login', 'authenticationAuthorityRevision'];
        yield 'rehash login' => ['rehash', 'authenticationAuthorityRevision'];
        foreach (['change', 'reset', 'confirm'] as $transition) {
            yield $transition.' security' => [$transition, 'authenticationVersion'];
            yield $transition.' persistence' => [$transition, 'authenticationAuthorityRevision'];
        }

        yield 'confirmation reservation' => ['confirm', 'emailChangeReservationRevision'];
        yield 'confirmation canonical' => ['confirm', 'canonicalEmailRevision'];
        yield 'request' => ['request', 'emailChangeReservationRevision'];
        yield 'cancel' => ['cancel', 'emailChangeReservationRevision'];
        yield 'expire' => ['expire', 'emailChangeReservationRevision'];
        yield 'correction' => ['correct', 'canonicalEmailRevision'];
        yield 'registration recovery' => ['recover', 'canonicalEmailRevision'];
        yield 'disable' => ['disable', 'authenticationVersion'];
        yield 'delete' => ['delete', 'authenticationVersion'];
        yield 'assign' => ['assign', 'authorizationAssignmentRevision'];
        yield 'remove' => ['remove', 'authorizationAssignmentRevision'];
        yield 'replace' => ['replace', 'authorizationAssignmentRevision'];
    }

    #[DataProvider('exhaustedTransitions')]
    public function test_exhausted_counters_reject_before_any_mutation(string $transition, string $counter): void
    {
        $now = new DateTimeImmutable('2026-11-05T00:00:00+00:00');
        $roleId = RoleId::generate();
        $password = PasswordHash::fromString(password_hash('contract-password', PASSWORD_DEFAULT));
        $pending = in_array($transition, ['activate', 'correct', 'recover'], true);
        $pendingEmailChange = null;
        if (in_array($transition, ['confirm', 'cancel', 'expire'], true)) {
            $pendingEmailChange = EmailAddress::fromString('pending@example.test');
        }

        $user = self::hydrate([
            $counter             => PHP_INT_MAX,
            'state'              => $pending ? UserState::PENDING_ACTIVATION : UserState::ACTIVE,
            'passwordHash'       => $pending ? null : $password,
            'pendingEmailChange' => $pendingEmailChange,
            'roleIds'            => [$roleId]
        ]);
        $before = self::snapshot($user);
        try {
            match ($transition) {
                'activate' => $user->activate($password, $now),
                'login' => $user->recordSuccessfulLogin($now),
                'rehash' => $user->recordSuccessfulLogin($now, $password),
                'change' => $user->changePassword($password, $now),
                'reset' => $user->resetPassword($password, $now),
                'confirm' => $user->confirmEmailChange($now),
                'request' => $user->requestEmailChange(EmailAddress::fromString('new@example.test'), $now),
                'cancel' => $user->cancelEmailChange($now),
                'expire' => $user->expireEmailChange($now),
                'correct' => $user->correctPendingInvitationEmail(EmailAddress::fromString('new@example.test'), $now),
                'recover' => $user->recoverPendingRegistrationEmail(EmailAddress::fromString('new@example.test'), $now),
                'disable' => $user->disable($now),
                'delete' => $user->delete($now),
                'assign' => $user->assignRole(RoleId::generate(), $now),
                'remove' => $user->removeRole($roleId, $now),
                'replace' => $user->replaceRoleAssignments([], $now),
                default => self::fail('Unknown transition fixture.')
            };
            self::fail('An exhausted revision was advanced.');
        } catch (DomainException) {
            self::assertSame($before, self::snapshot($user));
        }
    }

    public function test_no_ops_remain_valid_at_exhausted_counters(): void
    {
        $roleId = RoleId::generate();
        $user = self::hydrate([
            'authorizationAssignmentRevision' => PHP_INT_MAX,
            'canonicalEmailRevision'          => PHP_INT_MAX,
            'roleIds'                         => [$roleId],
            'state'                           => UserState::PENDING_ACTIVATION
        ]);
        $before = self::snapshot($user);
        $now = new DateTimeImmutable();
        self::assertFalse($user->assignRole($roleId, $now));
        self::assertFalse($user->removeRole(RoleId::generate(), $now));
        $user->replaceRoleAssignments([$roleId], $now);
        $user->recoverPendingRegistrationEmail($user->getEmail(), $now);
        self::assertSame($before, self::snapshot($user));
    }

    /** @phpstan-return array<string, mixed> */
    private static function snapshot(User $user): array
    {
        return [
            'id'                      => $user->getId()->toString(),
            'email'                   => $user->getEmail()->toString(),
            'state'                   => $user->getState(),
            'created_at'              => $user->getCreatedAt(),
            'updated_at'              => $user->getUpdatedAt(),
            'password_hash'           => $user->getPasswordHash()?->toString(),
            'authentication_version'  => $user->getAuthenticationVersion(),
            'authentication_revision' => $user->getAuthenticationAuthorityRevision(),
            'role_ids'                => array_map(
                static fn(RoleId $id): string => $id->toString(),
                $user->getRoleIds()
            ),
            'assignment_revision'     => $user->getAuthorizationAssignmentRevision(),
            'pending_email'           => $user->getPendingEmailChange()?->toString(),
            'reservation_revision'    => $user->getEmailChangeReservationRevision(),
            'canonical_revision'      => $user->getCanonicalEmailRevision()
        ];
    }

    /** @phpstan-param array<string, mixed> $overrides */
    private static function hydrate(array $overrides): User
    {
        $class = new ReflectionClass(User::class);
        $user = $class->newInstanceWithoutConstructor();
        $constructor = $class->getConstructor();
        self::assertNotNull($constructor);
        $now = new DateTimeImmutable('2026-11-05T00:00:00+00:00');
        $constructor->invokeArgs($user, array_replace([
            'id'        => UserId::generate(),
            'email'     => EmailAddress::fromString('hydrate@example.test'),
            'state'     => UserState::ACTIVE,
            'createdAt' => $now,
            'updatedAt' => $now
        ], $overrides));

        return $user;
    }
}
