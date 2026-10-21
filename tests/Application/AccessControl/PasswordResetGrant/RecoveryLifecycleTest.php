<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\PasswordResetGrant;

use Fight\AccessControl\Application\AccessControl\CredentialDelivery\Service\CredentialDeliveryOutcome;
use Fight\AccessControl\Application\AccessControl\PasswordResetGrant\CommandHandler\DeliverPasswordResetHandler;
use Fight\AccessControl\Application\AccessControl\PasswordResetGrant\CommandHandler\RequestPasswordResetHandler;
use Fight\AccessControl\Application\AccessControl\PasswordResetGrant\Service\PasswordResetRevocationService;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\AssignRoleToUserHandler;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\DeleteUserHandler;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\DisableUserHandler;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\RemoveRoleFromUserHandler;
use Fight\AccessControl\Application\AccessControl\User\Security\AuthenticationService;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryStatus;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\Exception\PasswordResetDeliveryException;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\Exception\PasswordResetRejectedException;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrant;
use Fight\AccessControl\Domain\AccessControl\Role\Role;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\Role\RoleName;
use Fight\AccessControl\Domain\AccessControl\User\Command\AssignRoleToUser;
use Fight\AccessControl\Domain\AccessControl\User\Command\RemoveRoleFromUser;
use Fight\AccessControl\Domain\AccessControl\User\RecoveryAuthority;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Test\AccessControl\Application\AccessControl\Role\Repository\InMemoryRoleRepository;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

#[CoversClass(AuthenticationService::class)]
#[CoversClass(DeleteUserHandler::class)]
#[CoversClass(DisableUserHandler::class)]
#[CoversClass(DeliverPasswordResetHandler::class)]
#[CoversClass(RequestPasswordResetHandler::class)]
#[CoversClass(PasswordResetRevocationService::class)]
#[CoversClass(PasswordResetGrant::class)]
#[CoversClass(RecoveryAuthority::class)]
#[CoversClass(User::class)]
final class RecoveryLifecycleTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function securityTransitions(): iterable
    {
        yield 'password' => ['password'];
        yield 'email' => ['email'];
        yield 'disable' => ['disable'];
        yield 'delete' => ['delete'];
    }

    #[DataProvider('securityTransitions')]
    public function test_security_transition_commits_current_recovery_state(string $transition): void
    {
        $fixture = new RecoveryFixture();
        $fixture->request();

        $issued = $fixture->grant();
        $before = $fixture->user()->getRecoveryAuthority();
        $this->transition($fixture, $transition);

        self::assertFalse($before->equals($fixture->user()->getRecoveryAuthority()));
        self::assertTrue($fixture->grant()->isRevoked());
        self::assertNull($fixture->grant()->getDelivery()->getEncryptedMaterial());
        self::assertSame([], $fixture->grants->findDue($fixture->now, 10));
        self::assertSame($issued->getId(), $fixture->grant()->getId());
        self::assertFalse($issued->isAuthorizedBy($fixture->user()));
        if ($transition === 'disable') {
            $fixture->enable();
        } elseif ($transition === 'delete') {
            $fixture->restore();
        }

        self::assertSame(UserState::ACTIVE, $fixture->user()->getState());
        $this->assertResetRejected($fixture);
        self::assertTrue($fixture->grant()->isRevoked());
        self::assertCount(1, $fixture->grants->all());
        $fixture->request('fresh-recovery-fixture');
        self::assertTrue($fixture->grant()->isAuthorizedBy($fixture->user()));
        $fixture->service()->resetPassword(
            $fixture->userId,
            'fresh-recovery-fixture',
            'a sufficiently long recovered password'
        );
        self::assertTrue($fixture->grant()->isConsumed());
    }

    public function test_role_assignment_changes_preserve_recovery(): void
    {
        $fixture = new RecoveryFixture();
        $fixture->request();

        $grant = $fixture->grant();
        $roles = new InMemoryRoleRepository($fixture->unitOfWork);
        $role = Role::define(RoleId::generate(), RoleName::fromString('ROLE_MEMBER'), [], $fixture->now);
        $roles->add($role);
        new AssignRoleToUserHandler(
            $fixture->users,
            $roles,
            new FixedClock($fixture->now),
            $fixture->unitOfWork,
            $fixture->events
        )->handle(CommandMessage::create(new AssignRoleToUser($fixture->userId, $fixture->userId, $role->getId())));
        self::assertTrue($fixture->user()->hasRole($role->getId()));
        self::assertTrue($grant->isAuthorizedBy($fixture->user()));
        new RemoveRoleFromUserHandler(
            $fixture->users,
            $roles,
            new FixedClock($fixture->now),
            $fixture->unitOfWork,
            $fixture->events
        )->handle(CommandMessage::create(new RemoveRoleFromUser($fixture->userId, $fixture->userId, $role->getId())));
        self::assertFalse($fixture->user()->hasRole($role->getId()));
        self::assertEquals($grant, $fixture->grant());
        $fixture->service()->resetPassword(
            $fixture->userId,
            'recovery-fixture',
            'a sufficiently long recovered password'
        );
        self::assertTrue($fixture->grant()->isConsumed());
    }

    public function test_equal_security_values_still_advance_current_authority(): void
    {
        $fixture = new RecoveryFixture();
        $fixture->request();

        $initial = $fixture->grant()->getAuthority();
        $fixture->service()->changePassword($fixture->userId, 'correct-secret', 'correct-secret');
        self::assertFalse($initial->equals($fixture->user()->getRecoveryAuthority()));
        $fixture->request('next-fixture');
        $next = $fixture->grant()->getAuthority();
        $fixture->requestEmail();
        $fixture->service()->confirmEmail($fixture->userId, 'email-fixture');
        $fixture->requestEmail('recovery@example.test', 'next-email-fixture');
        $fixture->service()->confirmEmail($fixture->userId, 'next-email-fixture');
        self::assertSame('recovery@example.test', $fixture->user()->getEmail()->canonical());
        self::assertFalse($next->equals($fixture->user()->getRecoveryAuthority()));
        self::assertSame(2, $fixture->user()->getCanonicalEmailRevision());
        self::assertTrue($fixture->grant()->isRevoked());
    }

    public function test_ordinary_authentication_and_pending_address_work_preserve_recovery(): void
    {
        $activities = ['login', 'rehash', 'refresh', 'logout', 'email request', 'email cancel', 'email expiry'];
        foreach ($activities as $activity) {
            $fixture = new RecoveryFixture();
            $fixture->request();
            $grant = $fixture->grant();
            if (in_array($activity, ['login', 'rehash', 'refresh', 'logout'], true)) {
                $service = $fixture->service($activity === 'rehash');
                $tokens = $service->login($fixture->user()->getEmail()->toString(), 'correct-secret');
                if ($activity === 'refresh') {
                    $service->refresh($tokens->getRefreshCredential()->toString());
                } elseif ($activity === 'logout') {
                    $service->logout($tokens->getRefreshCredential()->toString());
                }
            } else {
                $fixture->requestEmail();
                if ($activity === 'email cancel') {
                    $fixture->cancelEmail();
                } elseif ($activity === 'email expiry') {
                    $fixture->expireEmail();
                }
            }

            self::assertEquals($grant, $fixture->grant());
            self::assertTrue($grant->isAuthorizedBy($fixture->user()));
            $fixture->service()->resetPassword(
                $fixture->userId,
                'recovery-fixture',
                'a sufficiently long recovery password'
            );
            self::assertTrue($fixture->grant()->isConsumed());
            self::assertNull($fixture->grant()->getDelivery()->getEncryptedMaterial());
            self::assertSame(2, $fixture->user()->getAuthenticationVersion());
            $this->assertResetRejected($fixture);
        }
    }

    public function test_delivery_result_does_not_supply_redemption_authority(): void
    {
        foreach (CredentialDeliveryOutcome::cases() as $outcome) {
            $fixture = new RecoveryFixture();
            $fixture->request();
            $fixture->deliver(static function () use ($fixture): void {
                self::assertFalse($fixture->unitOfWork->transactionActive);
            }, $outcome);
            self::assertTrue($fixture->grant()->isIssued());
            $fixture->service()->resetPassword(
                $fixture->userId,
                'recovery-fixture',
                'a sufficiently long recovery password'
            );
            self::assertTrue($fixture->grant()->isConsumed());
            self::assertSame(2, $fixture->user()->getAuthenticationVersion());
        }

        $fixture = new RecoveryFixture();
        $fixture->request();
        $fixture->service()->resetPassword(
            $fixture->userId,
            'recovery-fixture',
            'a sufficiently long recovery password'
        );
        self::assertTrue($fixture->grant()->isConsumed());
    }

    public function test_current_worker_admission_rejects_ineligible_or_incoherent_authority(): void
    {
        foreach (['password', 'email', 'disable', 'delete'] as $transition) {
            $fixture = new RecoveryFixture();
            $fixture->request();
            $issued = $fixture->grant();
            $this->transition($fixture, $transition);
            $auditCount = count($fixture->audit->all());
            foreach ([false, true] as $issuedSnapshot) {
                if ($issuedSnapshot) {
                    // Model an independently hydrated generation against current User state.
                    $fixture->grantState->grants = [$issued];
                }

                $called = false;
                $caught = null;
                try {
                    $fixture->deliver(static function () use (&$called): void {
                        $called = true;
                    });
                } catch (PasswordResetDeliveryException $exception) {
                    $caught = $exception;
                }

                self::assertNotNull($caught);
                self::assertFalse($called);
                self::assertSame($auditCount, count($fixture->audit->all()));
                self::assertSame([], $fixture->grants->findDue($fixture->now, 10));
                $this->assertResetRejected($fixture);
            }
        }
    }

    public function test_exact_expiry_is_not_redemption_authority(): void
    {
        $fixture = new RecoveryFixture();
        $fixture->request();

        $before = $fixture->user();
        $fixture->now = $fixture->grant()->getExpiresAt();
        $this->assertResetRejected($fixture);
        self::assertEquals($before, $fixture->user());
        self::assertTrue($fixture->grant()->isIssued());
        self::assertCount(1, $fixture->audit->all());
    }

    #[DataProvider('securityTransitions')]
    public function test_late_audit_failure_rolls_back_coupled_recovery_and_user_state(string $transition): void
    {
        $fixture = new RecoveryFixture();
        if ($transition === 'email') {
            $fixture->requestEmail();
        }

        $fixture->request();
        $beforeUser = clone $fixture->user();
        $beforeGrant = $fixture->grant();
        $failing = $fixture->connection(auditFails: true);
        if ($transition === 'email') {
            self::assertTrue($failing->emailGrants->add($fixture->emailGrants->getLatestByUserId($fixture->userId)));
        }

        $caught = null;
        try {
            $this->transition($failing, $transition);
        } catch (Throwable $throwable) {
            $caught = $throwable;
        }

        self::assertNotNull($caught);
        self::assertEquals($beforeUser, $fixture->user());
        self::assertEquals($beforeGrant, $fixture->grant());
        self::assertSame([], $failing->audit->all());
        self::assertCount(1, $failing->events->events());
    }

    public function test_failed_cancellation_cannot_commit_lifecycle_or_password_change(): void
    {
        foreach (['password', 'disable', 'delete'] as $transition) {
            $fixture = new RecoveryFixture();
            $fixture->request();
            $before = clone $fixture->user();
            $failing = new RecoveryFixture(
                $fixture->userState,
                $fixture->grantState,
                $fixture->sessionState,
                $fixture->userId,
                grantReplaceSucceeds: false
            );
            $caught = null;
            try {
                $this->transition($failing, $transition);
            } catch (Throwable $throwable) {
                $caught = $throwable;
            }

            self::assertNotNull($caught);
            self::assertEquals($before, $fixture->user());
            self::assertTrue($fixture->grant()->isIssued());
            self::assertSame([], $failing->audit->all());
        }
    }

    public function test_delivery_outcome_follows_current_authority_and_generation(): void
    {
        foreach (['password', 'email', 'disable', 'delete', 'replacement', 'redemption'] as $transition) {
            foreach (CredentialDeliveryOutcome::cases() as $outcome) {
                $fixture = new RecoveryFixture();
                $fixture->request();
                $first = $fixture->grant();
                $caught = null;
                try {
                    $fixture->deliver(function () use ($fixture, $transition): void {
                        self::assertFalse($fixture->unitOfWork->transactionActive);
                        if ($transition === 'replacement') {
                            $fixture->request('successor-fixture');
                        } elseif ($transition === 'redemption') {
                            $fixture->service()->resetPassword(
                                $fixture->userId,
                                'recovery-fixture',
                                'a sufficiently long recovery password'
                            );
                        } else {
                            $this->transition($fixture, $transition);
                        }
                    }, $outcome);
                } catch (PasswordResetDeliveryException $exception) {
                    $caught = $exception;
                }

                self::assertNotNull($caught);
                self::assertNotSame(CredentialDeliveryStatus::DELIVERED, $fixture->grant()->getDelivery()->getStatus());
                $old = $fixture->grants->getById($first->getId());
                self::assertFalse($old->isIssued());
                self::assertNull($old->getDelivery()->getEncryptedMaterial());
                if ($transition === 'replacement') {
                    self::assertTrue($fixture->grant()->isIssued());
                    self::assertSame(0, $fixture->grant()->getRevision());
                }
            }
        }
    }

    private function transition(RecoveryFixture $fixture, string $transition): void
    {
        if ($transition === 'password') {
            $fixture->service()->changePassword(
                $fixture->userId,
                'correct-secret',
                'a sufficiently long changed password'
            );
        } elseif ($transition === 'email') {
            if ($fixture->user()->getPendingEmailChange() === null) {
                $fixture->requestEmail();
            }

            $fixture->service()->confirmEmail($fixture->userId, 'email-fixture');
        } elseif ($transition === 'disable') {
            $fixture->disable();
        } elseif ($transition === 'delete') {
            $fixture->delete();
        } else {
            throw new RuntimeException('Unknown contract transition.');
        }
    }

    private function assertResetRejected(RecoveryFixture $fixture): void
    {
        $caught = null;
        try {
            $fixture->service()->resetPassword(
                $fixture->userId,
                'recovery-fixture',
                'a sufficiently long recovery password'
            );
        } catch (PasswordResetRejectedException $passwordResetRejectedException) {
            $caught = $passwordResetRejectedException;
        }

        self::assertNotNull($caught);
    }
}
