<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\User;

use Fight\AccessControl\Domain\AccessControl\RefreshSession\Command\RevokeSession;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\Event\CurrentSessionLoggedOut;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\Event\RefreshSessionRevoked;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\Query\ListActiveSessions;
use Fight\AccessControl\Domain\AccessControl\User\Command\AssignRoleToUser;
use Fight\AccessControl\Domain\AccessControl\User\Command\CorrectPendingInvitation;
use Fight\AccessControl\Domain\AccessControl\User\Command\DeleteUser;
use Fight\AccessControl\Domain\AccessControl\User\Command\DisableUser;
use Fight\AccessControl\Domain\AccessControl\User\Command\EnableUser;
use Fight\AccessControl\Domain\AccessControl\User\Command\InvitePendingUser;
use Fight\AccessControl\Domain\AccessControl\User\Command\RecoverPendingRegistration;
use Fight\AccessControl\Domain\AccessControl\User\Command\RegisterUser;
use Fight\AccessControl\Domain\AccessControl\User\Command\RemoveRoleFromUser;
use Fight\AccessControl\Domain\AccessControl\User\Command\RestoreUser;
use Fight\AccessControl\Domain\AccessControl\User\Event\EmailChangeConfirmed;
use Fight\AccessControl\Domain\AccessControl\User\Event\PasswordChanged;
use Fight\AccessControl\Domain\AccessControl\User\Event\PasswordResetCompleted;
use Fight\AccessControl\Domain\AccessControl\User\Event\PendingInvitationCorrected;
use Fight\AccessControl\Domain\AccessControl\User\Event\PendingRegistrationRecovered;
use Fight\AccessControl\Domain\AccessControl\User\Event\RedactedCommandFailed;
use Fight\AccessControl\Domain\AccessControl\User\Event\RoleAssignedToUser;
use Fight\AccessControl\Domain\AccessControl\User\Event\RoleRemovedFromUser;
use Fight\AccessControl\Domain\AccessControl\User\Event\UserActivated;
use Fight\AccessControl\Domain\AccessControl\User\Event\UserDeleted;
use Fight\AccessControl\Domain\AccessControl\User\Event\UserDisabled;
use Fight\AccessControl\Domain\AccessControl\User\Event\UserEnabled;
use Fight\AccessControl\Domain\AccessControl\User\Event\UserInvited;
use Fight\AccessControl\Domain\AccessControl\User\Event\UserLoggedIn;
use Fight\AccessControl\Domain\AccessControl\User\Event\UserRegistered;
use Fight\AccessControl\Domain\AccessControl\User\Event\UserRestored;
use Fight\AccessControl\Domain\AccessControl\User\Query\GetUserById;
use Fight\AccessControl\Domain\AccessControl\User\Query\ListUsers;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\Command;
use Fight\Common\Domain\Messaging\Event\Event;
use Fight\Common\Domain\Messaging\Query\Query;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stringable;

#[CoversClass(RevokeSession::class)]
#[CoversClass(CurrentSessionLoggedOut::class)]
#[CoversClass(RefreshSessionRevoked::class)]
#[CoversClass(ListActiveSessions::class)]
#[CoversClass(AssignRoleToUser::class)]
#[CoversClass(CorrectPendingInvitation::class)]
#[CoversClass(DeleteUser::class)]
#[CoversClass(DisableUser::class)]
#[CoversClass(EnableUser::class)]
#[CoversClass(InvitePendingUser::class)]
#[CoversClass(RecoverPendingRegistration::class)]
#[CoversClass(RegisterUser::class)]
#[CoversClass(RemoveRoleFromUser::class)]
#[CoversClass(RestoreUser::class)]
#[CoversClass(EmailChangeConfirmed::class)]
#[CoversClass(PasswordChanged::class)]
#[CoversClass(PasswordResetCompleted::class)]
#[CoversClass(PendingInvitationCorrected::class)]
#[CoversClass(PendingRegistrationRecovered::class)]
#[CoversClass(RedactedCommandFailed::class)]
#[CoversClass(RoleAssignedToUser::class)]
#[CoversClass(RoleRemovedFromUser::class)]
#[CoversClass(UserActivated::class)]
#[CoversClass(UserDeleted::class)]
#[CoversClass(UserDisabled::class)]
#[CoversClass(UserEnabled::class)]
#[CoversClass(UserInvited::class)]
#[CoversClass(UserLoggedIn::class)]
#[CoversClass(UserRegistered::class)]
#[CoversClass(UserRestored::class)]
#[CoversClass(GetUserById::class)]
#[CoversClass(ListUsers::class)]
final class UserSessionMessageContractTest extends TestCase
{
    /**
     * @phpstan-return iterable<string, array{class-string<Command|Query|Event>, array<string, mixed>}>
     */
    public static function messages(): iterable
    {
        $id = '0199d106-4c00-7000-8000-000000000001';
        $actor = ['actor_id' => $id];
        $invitationActor = ['actor_kind' => 'user'] + $actor;
        $user = ['user_id' => $id];
        $email = ['email' => 'contract@example.test'];
        $delivery = ['activation_delivery_id' => $id];
        $invited = $invitationActor + $user + $delivery + $email;
        $role = $actor + ['target_user_id' => $id, 'role_id' => $id];
        $session = $user + ['refresh_session_id' => $id];
        $time = '2026-11-05T12:34:56+00:00';
        $pagination = ['page' => 2, 'per_page' => 10, 'orderings' => ['created_at' => 'DESC']];
        $recovered = $actor + $user + ['previous_activation_delivery_id' => $id] + $delivery + $email;
        $registered = $user + $delivery + $email + ['issued_at' => '2026-11-05T12:34:56.123456+00:00'];
        $restorationPayload = $actor + $user + ['restoration_state' => 'active', 'activation_delivery_id' => null];
        $revocation = $actor + ['current_session_id' => $id, 'target_session_id' => $id, 'reason' => null];
        $payloads = [
            AssignRoleToUser::class             => $role,
            RemoveRoleFromUser::class           => $role,
            CorrectPendingInvitation::class     => $actor + $user + $email,
            InvitePendingUser::class            => $invitationActor + $user + $email,
            RecoverPendingRegistration::class   => $actor + $user + ['expected_activation_delivery_id' => $id] + $email,
            RegisterUser::class                 => $user + $email,
            DeleteUser::class                   => $actor + $user,
            DisableUser::class                  => $actor + $user,
            EnableUser::class                   => $actor + $user,
            RestoreUser::class                  => $actor + $user + ['restoration_state' => 'active'],
            EmailChangeConfirmed::class         => $user + ['confirmed_at' => $time],
            PasswordChanged::class              => $user + ['changed_at' => $time],
            PasswordResetCompleted::class       => $user + ['completed_at' => $time],
            PendingInvitationCorrected::class   => $actor + $user + $email + $delivery,
            PendingRegistrationRecovered::class => $recovered,
            RedactedCommandFailed::class        => [
                'command_class' => 'UserAuthentication::login',
                'command_data'  => ['remember' => true, 'email' => 'contract@example.test'],
                'error_message' => 'Login failed.'
            ],
            RoleAssignedToUser::class           => $role + ['assigned_at' => $time],
            RoleRemovedFromUser::class          => $role + ['removed_at' => $time],
            UserActivated::class                => $session + ['activated_at' => $time],
            UserLoggedIn::class                 => $session + ['logged_in_at' => $time],
            UserDeleted::class                  => $actor + $user,
            UserDisabled::class                 => $actor + $user,
            UserEnabled::class                  => $actor + $user,
            UserInvited::class                  => $invited + ['issued_at' => $time],
            UserRegistered::class               => $registered,
            UserRestored::class                 => $restorationPayload,
            GetUserById::class                  => $user,
            ListUsers::class                    => $pagination,
            RevokeSession::class                => $revocation,
            CurrentSessionLoggedOut::class      => ['refresh_session_id' => $id],
            RefreshSessionRevoked::class        => $actor + $session + ['revoked_at' => $time],
            ListActiveSessions::class           => $actor + $user + ['current_session_id' => $id] + $pagination
        ];
        foreach ($payloads as $class => $payload) {
            yield $class => [$class, $payload];
        }

        $reasoned = $payloads[RevokeSession::class];
        $reasoned['reason'] = 'Requested by the owner';
        yield 'non-null revocation reason' => [RevokeSession::class, $reasoned];
        $restored = $payloads[UserRestored::class];
        $restored['restoration_state'] = 'pending_activation';
        $restored['activation_delivery_id'] = $id;
        yield 'pending restoration' => [UserRestored::class, $restored];
    }

    /**
     * @phpstan-param class-string<Command|Query|Event> $class
     * @phpstan-param array<string, mixed> $payload
     */
    #[DataProvider('messages')]
    public function test_canonical_round_trips(string $class, array $payload): void
    {
        $message = $class::fromArray($payload);

        self::assertSame($payload, $message->toArray());
        self::assertEquals($message, $class::fromArray($message->toArray()));
    }

    /**
     * @phpstan-return iterable<string, array{class-string<Command|Query|Event>, array<string, mixed>}>
     */
    public static function missingFields(): iterable
    {
        foreach (self::messages() as $name => [$class, $payload]) {
            foreach (array_keys($payload) as $key) {
                $missing = $payload;
                unset($missing[$key]);
                yield $name.' '.$key => [$class, $missing];
            }
        }
    }

    /**
     * @phpstan-param class-string<Command|Query|Event> $class
     * @phpstan-param array<string, mixed> $payload
     */
    #[DataProvider('missingFields')]
    public function test_missing_required_fields_are_rejected(string $class, array $payload): void
    {
        $this->expectException(DomainException::class);
        $class::fromArray($payload);
    }

    /**
     * @phpstan-return iterable<string, array{class-string<Command|Query|Event>, array<string, mixed>}>
     */
    public static function mismatchedFields(): iterable
    {
        foreach (self::messages() as $name => [$class, $payload]) {
            foreach ($payload as $key => $value) {
                $invalidValues = [false, 42, 1.5, [], null];
                if (is_int($value)) {
                    $invalidValues = [false, '2', 1.5, [], null];
                } elseif (is_array($value)) {
                    $invalidValues = [false, 42, 1.5, 'not-an-array', null];
                } elseif ($value === null) {
                    $invalidValues = [false, 42, 1.5, []];
                }

                if ($key === 'reason' || $key === 'activation_delivery_id' && $class === UserRestored::class) {
                    $invalidValues = [false, 42, 1.5, []];
                }

                if (is_string($value)) {
                    $invalidValues[] = new readonly class ($value) implements Stringable {
                        public function __construct(private string $value)
                        {
                        }

                        public function __toString(): string
                        {
                            return $this->value;
                        }
                    };
                }

                foreach ($invalidValues as $index => $invalid) {
                    $bad = $payload;
                    $bad[$key] = $invalid;
                    yield $name.' '.$key.' '.$index => [$class, $bad];
                }
            }
        }
    }

    /**
     * @phpstan-param class-string<Command|Query|Event> $class
     * @phpstan-param array<string, mixed> $payload
     */
    #[DataProvider('mismatchedFields')]
    public function test_type_mismatches_are_rejected_without_coercion(string $class, array $payload): void
    {
        $this->expectException(DomainException::class);
        $class::fromArray($payload);
    }

    /**
     * @phpstan-return iterable<string, array{class-string<Command|Query|Event>, array<string, mixed>}>
     */
    public static function invalidOrderingMaps(): iterable
    {
        foreach (self::messages() as $name => [$class, $payload]) {
            if (!array_key_exists('orderings', $payload)) {
                continue;
            }

            foreach ([['ASC'], ['created_at' => 1], ['created_at' => null], ['created_at' => []]] as $index => $map) {
                $bad = $payload;
                $bad['orderings'] = $map;
                yield $name.' ordering '.$index => [$class, $bad];
            }
        }
    }

    /**
     * @phpstan-param class-string<Command|Query|Event> $class
     * @phpstan-param array<string, mixed> $payload
     */
    #[DataProvider('invalidOrderingMaps')]
    public function test_orderings_are_named_string_maps(string $class, array $payload): void
    {
        $this->expectException(DomainException::class);
        $class::fromArray($payload);
    }

    /**
     * @phpstan-return iterable<string, array{class-string<Command|Query|Event>, array<string, mixed>}>
     */
    public static function invalidInstants(): iterable
    {
        foreach (self::messages() as $name => [$class, $payload]) {
            foreach (array_keys($payload) as $key) {
                if (!str_ends_with($key, '_at')) {
                    continue;
                }

                foreach (['', 'now', 'tomorrow', '2026-02-30T12:34:56+00:00', '2026-11-05'] as $invalid) {
                    $bad = $payload;
                    $bad[$key] = $invalid;
                    yield $name.' '.$key.' '.$invalid => [$class, $bad];
                }
            }
        }
    }

    /**
     * @phpstan-param class-string<Command|Query|Event> $class
     * @phpstan-param array<string, mixed> $payload
     */
    #[DataProvider('invalidInstants')]
    public function test_event_instants_do_not_infer_a_clock_or_normalize_bad_dates(string $class, array $payload): void
    {
        $this->expectException(DomainException::class);
        $class::fromArray($payload);
    }
}
