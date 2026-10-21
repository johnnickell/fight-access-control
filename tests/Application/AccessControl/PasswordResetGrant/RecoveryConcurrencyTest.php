<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\PasswordResetGrant;

use Fiber;
use Fight\AccessControl\Application\AccessControl\PasswordResetGrant\CommandHandler\DeliverPasswordResetHandler;
use Fight\AccessControl\Application\AccessControl\PasswordResetGrant\CommandHandler\RequestPasswordResetHandler;
use Fight\AccessControl\Application\AccessControl\PasswordResetGrant\Service\PasswordResetRevocationService;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\DeleteUserHandler;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\DisableUserHandler;
use Fight\AccessControl\Application\AccessControl\User\Security\AuthenticationService;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrant;
use Fight\AccessControl\Domain\AccessControl\User\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Throwable;

#[CoversClass(AuthenticationService::class)]
#[CoversClass(DeleteUserHandler::class)]
#[CoversClass(DisableUserHandler::class)]
#[CoversClass(DeliverPasswordResetHandler::class)]
#[CoversClass(RequestPasswordResetHandler::class)]
#[CoversClass(PasswordResetRevocationService::class)]
#[CoversClass(PasswordResetGrant::class)]
#[CoversClass(User::class)]
final class RecoveryConcurrencyTest extends TestCase
{
    public function test_admission_rechecks_snapshot_after_another_connection_commits(): void
    {
        foreach (['request', 'redemption', 'delivery'] as $operation) {
            foreach (['password', 'email', 'disable', 'delete'] as $change) {
                $fixture = new RecoveryFixture();
                $fixture->request();
                if ($change === 'email') {
                    $fixture->requestEmail();
                }

                $paused = null;
                $paused = $fixture->connection(beforeAdmission: static function () use (&$paused): void {
                    $paused->unitOfWork->suspendBeforeFirstWrite();
                });
                $fiber = new Fiber(fn() => $this->operate($paused, $operation));
                $fiber->start();
                self::assertTrue($fiber->isSuspended());
                $this->change($fixture, $change);
                $expectedUser = clone $fixture->user();
                $expectedGrant = $fixture->grant();
                $caught = null;
                try {
                    $fiber->resume();
                } catch (Throwable $throwable) {
                    $caught = $throwable;
                }

                self::assertNotNull($caught);
                self::assertEquals($expectedUser, $fixture->user());
                self::assertEquals($expectedGrant, $fixture->grant());
                self::assertSame([], $paused->audit->all());
                self::assertCount(1, $paused->events->events());
                self::assertFalse($fixture->userState->isAuthenticationAuthorityFenceHeld($fixture->userId));
            }
        }
    }

    public function test_completed_recovery_operation_is_followed_by_current_security_transition(): void
    {
        foreach (['request', 'redemption', 'delivery'] as $operation) {
            foreach (['password', 'email', 'disable', 'delete'] as $change) {
                $fixture = new RecoveryFixture();
                $fixture->request();
                if ($change === 'email') {
                    $fixture->requestEmail();
                }

                $this->operate($fixture, $operation);
                $this->change(
                    $fixture,
                    $change,
                    $operation === 'redemption' ? 'a sufficiently long recovered password' : 'correct-secret'
                );
                self::assertFalse($fixture->grant()->isIssued());
                self::assertNull($fixture->grant()->getDelivery()->getEncryptedMaterial());
                self::assertSame([], $fixture->grants->findDue($fixture->now, 10));
                self::assertFalse($fixture->unitOfWork->transactionActive);
            }
        }
    }

    public function test_shared_fence_is_held_until_commit_or_rollback_for_all_recovery_writers(): void
    {
        foreach (['request', 'redemption', 'delivery', 'password', 'disable', 'delete'] as $owner) {
            foreach ([false, true] as $rollback) {
                $fixture = new RecoveryFixture();
                $fixture->request();
                $beforeUser = clone $fixture->user();
                $beforeGrant = $fixture->grant();
                $competitor = $fixture->connection();
                $probed = false;
                $fixture->unitOfWork->beforeCommit = function () use ($fixture, $competitor, &$probed): void {
                    if ($probed) {
                        return;
                    }

                    $probed = true;
                    self::assertTrue($fixture->userState->isAuthenticationAuthorityFenceHeld($fixture->userId));
                    foreach (['request', 'redemption', 'delivery', 'password', 'disable', 'delete'] as $operation) {
                        $caught = null;
                        try {
                            if (in_array($operation, ['password', 'disable', 'delete'], true)) {
                                $this->change($competitor, $operation);
                            } else {
                                $this->operate($competitor, $operation);
                            }
                        } catch (Throwable $throwable) {
                            $caught = $throwable;
                        }

                        // Inactive reset requests retain their generic no-work outcome.
                        if ($operation !== 'request' || $fixture->user()->getState()->value === 'active') {
                            self::assertNotNull($caught);
                        }
                    }

                    $competitor->unitOfWork->commitTransactional(function () use ($competitor): void {
                        $grant = $competitor->grant();
                        self::assertFalse($competitor->grants->replace($grant, $grant->invalidateDelivery()));
                    });
                    self::assertSame([], $competitor->audit->all());
                };
                $fixture->unitOfWork->failNextCommit = $rollback;
                $caught = null;
                try {
                    if (in_array($owner, ['password', 'disable', 'delete'], true)) {
                        $this->change($fixture, $owner);
                    } else {
                        $this->operate($fixture, $owner);
                    }
                } catch (Throwable $throwable) {
                    $caught = $throwable;
                }

                self::assertTrue($probed);
                self::assertSame($rollback, $caught instanceof Throwable);
                self::assertFalse($fixture->userState->isAuthenticationAuthorityFenceHeld($fixture->userId));
                if ($rollback) {
                    self::assertEquals($beforeUser, $fixture->user());
                    self::assertEquals($beforeGrant, $fixture->grant());
                }

                self::assertGreaterThan(0, $fixture->userState->getBlockedAuthenticationAuthorityFenceAttempts());
            }
        }
    }

    public function test_replacement_and_redemption_reconcile_from_persisted_winner(): void
    {
        foreach ([false, true] as $replacementFirst) {
            $fixture = new RecoveryFixture();
            $fixture->request();
            $other = $fixture->connection();
            if ($replacementFirst) {
                $other->request('successor-fixture');
                $caught = null;
                try {
                    $this->operate($fixture, 'redemption');
                } catch (Throwable $throwable) {
                    $caught = $throwable;
                }

                self::assertNotNull($caught);
                self::assertTrue($fixture->grant()->isIssued());
                $other->service()->resetPassword(
                    $other->userId,
                    'successor-fixture',
                    'a sufficiently long recovered password'
                );
            } else {
                $this->operate($fixture, 'redemption');
                $other->request('successor-fixture');
                self::assertSame(2, $other->grant()->getAuthority()->getAuthenticationVersion());
            }

            self::assertCount(2, $fixture->grants->all());
            self::assertFalse($fixture->grants->all()[0]->isIssued());
            self::assertNull($fixture->grants->all()[0]->getDelivery()->getEncryptedMaterial());
        }
    }

    private function operate(RecoveryFixture $fixture, string $operation): void
    {
        if ($operation === 'request') {
            $fixture->request('next-recovery-fixture');
        } elseif ($operation === 'redemption') {
            $fixture->service()->resetPassword(
                $fixture->userId,
                'recovery-fixture',
                'a sufficiently long recovered password'
            );
        } else {
            $fixture->deliver();
        }
    }

    private function change(RecoveryFixture $fixture, string $change, string $password = 'correct-secret'): void
    {
        if ($change === 'password') {
            $fixture->service()->changePassword($fixture->userId, $password, 'a sufficiently long changed password');
        } elseif ($change === 'email') {
            $fixture->service()->confirmEmail($fixture->userId, 'email-fixture');
        } elseif ($change === 'disable') {
            $fixture->disable();
        } else {
            $fixture->delete();
        }
    }
}
