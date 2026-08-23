<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\User;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\User\Exception\UserLifecycleException;
use Fight\AccessControl\Domain\AccessControl\User\PasswordHash;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(User::class)]
#[CoversClass(UserLifecycleException::class)]
final class UserLifecycleTest extends TestCase
{
    public function test_that_disable_suspends_only_an_active_identity(): void
    {
        $user = $this->activeUser();
        $user->disable(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));

        self::assertSame(UserState::DISABLED, $user->getState());

        foreach ([UserState::PENDING_ACTIVATION, UserState::DISABLED, UserState::DELETED] as $state) {
            $candidate = UserFixture::withState('disable-'.$state->value.'@example.test', $state);
            try {
                $candidate->disable(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
                self::fail('A non-active identity was disabled.');
            } catch (UserLifecycleException $userLifecycleException) {
                self::assertSame('Only an active user can be disabled.', $userLifecycleException->getMessage());
            }
        }
    }

    public function test_that_enable_reactivates_only_a_disabled_identity(): void
    {
        $user = $this->activeUser();
        $user->disable(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
        $user->enable(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));

        self::assertSame(UserState::ACTIVE, $user->getState());

        foreach ([UserState::PENDING_ACTIVATION, UserState::ACTIVE, UserState::DELETED] as $state) {
            $candidate = UserFixture::withState('enable-'.$state->value.'@example.test', $state);
            try {
                $candidate->enable(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
                self::fail('A non-disabled identity was enabled.');
            } catch (UserLifecycleException $userLifecycleException) {
                self::assertSame('Only a disabled user can be enabled.', $userLifecycleException->getMessage());
            }
        }
    }

    public function test_that_delete_soft_deletes_an_active_or_disabled_identity(): void
    {
        $active = $this->activeUser();
        $active->delete(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
        self::assertSame(UserState::DELETED, $active->getState());

        $disabled = $this->activeUser();
        $disabled->disable(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
        $disabled->delete(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
        self::assertSame(UserState::DELETED, $disabled->getState());

        foreach ([UserState::PENDING_ACTIVATION, UserState::DELETED] as $state) {
            $candidate = UserFixture::withState('delete-'.$state->value.'@example.test', $state);
            try {
                $candidate->delete(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
                self::fail('An ineligible identity was deleted.');
            } catch (UserLifecycleException $userLifecycleException) {
                self::assertSame(
                    'Only an active or disabled user can be deleted.',
                    $userLifecycleException->getMessage()
                );
            }
        }
    }

    public function test_that_restore_to_active_retains_the_established_password(): void
    {
        $user = $this->activeUser();
        $passwordHash = $user->getPasswordHash();
        $user->delete(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
        $user->restore(UserState::ACTIVE, new DateTimeImmutable('2026-01-01T00:00:00+00:00'));

        self::assertSame(UserState::ACTIVE, $user->getState());
        self::assertSame($passwordHash, $user->getPasswordHash());
    }

    public function test_that_restore_to_pending_activation_clears_the_established_password(): void
    {
        $user = $this->activeUser();
        $user->delete(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
        $user->restore(UserState::PENDING_ACTIVATION, new DateTimeImmutable('2026-01-01T00:00:00+00:00'));

        self::assertSame(UserState::PENDING_ACTIVATION, $user->getState());
        self::assertNull($user->getPasswordHash());
    }

    public function test_that_restore_rejects_unsupported_targets_and_non_deleted_identities(): void
    {
        $user = $this->activeUser();
        $user->delete(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));

        foreach ([UserState::DISABLED, UserState::DELETED] as $target) {
            try {
                $user->restore($target, new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
                self::fail('An unsupported restoration target was accepted.');
            } catch (UserLifecycleException $userLifecycleException) {
                self::assertSame(
                    'A restored user must target active or pending activation.',
                    $userLifecycleException->getMessage()
                );
            }
        }

        $active = $this->activeUser();
        try {
            $active->restore(UserState::ACTIVE, new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
            self::fail('A non-deleted identity was restored.');
        } catch (UserLifecycleException $userLifecycleException) {
            self::assertSame('Only a deleted user can be restored.', $userLifecycleException->getMessage());
        }
    }

    private function activeUser(): User
    {
        $user = User::invite(
            UserId::generate(),
            EmailAddress::fromString('lifecycle@example.test'),
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        );
        $user->activate(
            PasswordHash::fromString(password_hash('a sufficiently long password', PASSWORD_DEFAULT)),
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        );

        return $user;
    }
}
