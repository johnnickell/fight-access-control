<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User\CommandHandler;

use DateTimeImmutable;
use Fiber;
use Fight\AccessControl\Application\AccessControl\CredentialDelivery\Service\CredentialDeliveryOutcome;
use Fight\AccessControl\Application\AccessControl\RefreshSession\Service\SessionRevocationService;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\DeleteUserHandler;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\RecoverPendingRegistrationHandler;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\RestoreUserHandler;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrant;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationOrigin;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Command\ResendInvitationDelivery;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Exception\ActivationDeliveryNotRetryableException;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryStatus;
use Fight\AccessControl\Domain\AccessControl\User\Command\DeleteUser;
use Fight\AccessControl\Domain\AccessControl\User\Command\RestoreUser;
use Fight\AccessControl\Domain\AccessControl\User\Exception\RegistrationRecoveryException;
use Fight\AccessControl\Domain\AccessControl\User\Exception\UserLifecycleException;
use Fight\AccessControl\Domain\AccessControl\User\PasswordHash;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\Service\FixedCredentialGenerator;
use Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\Service\PrefixInvitationDeliveryCipher;
use Fight\Test\AccessControl\Application\AccessControl\CredentialDelivery\Service\RecordingCredentialDeliveryProvider;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\RefreshSession\Repository\InMemoryRefreshSessionRepository;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Domain\AccessControl\User\UserFixture;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(RecoverPendingRegistrationHandler::class)]
#[CoversClass(ActivationGrant::class)]
final class RegistrationRecoveryRaceTest extends TestCase
{
    public function test_recovery_and_resend_have_both_winner_orders_without_stale_overwrite(): void
    {
        foreach (['corrected@example.test', 'alice@example.test'] as $email) {
            foreach ([false, true] as $recoveryWins) {
                $fixture = null;
                $fixture = new RegistrationRecoveryFixture(static function () use (&$fixture, $recoveryWins): void {
                    self::assertInstanceOf(RegistrationRecoveryFixture::class, $fixture);
                    if (!$recoveryWins) {
                        $fixture->unitOfWork->suspendBeforeFirstWrite();
                    }
                });
                $other = null;
                $other = $fixture->connection(beforeSuccessor: static function () use (&$other, $recoveryWins): void {
                    self::assertInstanceOf(RegistrationRecoveryFixture::class, $other);
                    if ($recoveryWins) {
                        $other->unitOfWork->suspendBeforeFirstWrite();
                    }
                });
                $recovery = fn() => $fixture->handler('2030-01-08T00:00:00Z')->handle(
                    CommandMessage::create($fixture->command($email))
                );
                $resend = fn() => $other->resend()->handle(CommandMessage::create(new ResendInvitationDelivery(
                    'administrator',
                    $fixture->initialUser->getId()
                )));
                $loser = new Fiber($recoveryWins ? $resend : $recovery);
                $loser->start();
                self::assertTrue($loser->isSuspended());
                ($recoveryWins ? $recovery : $resend)();
                $winner = $fixture->current();
                try {
                    $loser->resume();
                    self::fail('A stale replacement overwrote the winner.');
                } catch (LogicException | RegistrationRecoveryException) {
                    self::assertSame($winner, $fixture->current());
                    self::assertCount(2, $fixture->grants->all());
                    self::assertSame(ActivationOrigin::REGISTRATION, $winner->getOrigin());
                    self::assertSame(
                        $recoveryWins ? strtolower($email) : 'alice@example.test',
                        $fixture->users->all()[0]->getEmail()->canonical()
                    );
                    self::assertCount($recoveryWins ? 1 : 0, $fixture->audit->all());
                    self::assertCount($recoveryWins ? 0 : 1, $other->audit->all());
                    $this->assertRoles($fixture);
                }
            }
        }
    }

    public function test_recovery_variants_and_another_wrong_address_correction_have_both_winner_orders(): void
    {
        foreach (['corrected@example.test', 'alice@example.test'] as $email) {
            foreach ([false, true] as $firstWins) {
                $fixture = new RegistrationRecoveryFixture();
                $paused = null;
                $paused = $fixture->connection(beforeRecovery: static function () use (&$paused): void {
                    self::assertInstanceOf(RegistrationRecoveryFixture::class, $paused);
                    $paused->unitOfWork->suspendBeforeFirstWrite();
                });
                $loserEmail = $firstWins ? 'competing@example.test' : $email;
                $winnerEmail = $firstWins ? $email : 'competing@example.test';
                $loser = new Fiber(fn() => $paused->handler('2030-01-08T00:00:00Z')->handle(
                    CommandMessage::create($paused->command($loserEmail))
                ));
                $loser->start();
                self::assertTrue($loser->isSuspended());
                $fixture->handler('2030-01-08T00:00:00Z')->handle(CommandMessage::create(
                    $fixture->command($winnerEmail)
                ));
                $winner = $fixture->current();
                try {
                    $loser->resume();
                    self::fail('The stale second recovery replaced the winner.');
                } catch (RegistrationRecoveryException) {
                    self::assertSame($winner, $fixture->current());
                    self::assertSame(strtolower($winnerEmail), $fixture->users->all()[0]->getEmail()->canonical());
                    self::assertCount(2, $fixture->grants->all());
                    self::assertCount(1, $fixture->audit->all());
                    self::assertSame([], $paused->audit->all());
                    $this->assertRoles($fixture);
                }
            }
        }
    }

    public function test_recovery_and_activation_have_both_winner_orders_and_expired_same_email_cannot_activate(): void
    {
        foreach ([false, true] as $recoveryWins) {
            $fixture = new RegistrationRecoveryFixture();
            $paused = null;
            $pause = static function () use (&$paused): void {
                self::assertInstanceOf(RegistrationRecoveryFixture::class, $paused);
                $paused->unitOfWork->suspendBeforeFirstWrite();
            };
            $paused = $fixture->connection(
                beforeRecovery: $recoveryWins ? null : $pause,
                beforeGrantReplace: $recoveryWins ? $pause : null
            );
            $loser = new Fiber(function () use ($paused, $recoveryWins): void {
                if ($recoveryWins) {
                    $paused->authentication()->activate(
                        $paused->initialUser->getId(),
                        'registration-fixture-credential',
                        'long-new-password'
                    );
                } else {
                    $paused->handler()->handle(CommandMessage::create($paused->command()));
                }
            });
            $loser->start();
            self::assertTrue($loser->isSuspended());
            if ($recoveryWins) {
                $fixture->handler()->handle(CommandMessage::create($fixture->command()));
            } else {
                $fixture->authentication()->activate(
                    $fixture->initialUser->getId(),
                    'registration-fixture-credential',
                    'long-new-password'
                );
            }

            $winner = $fixture->current();
            try {
                $loser->resume();
                self::fail('The stale activation/recovery won.');
            } catch (LogicException | RegistrationRecoveryException) {
                self::assertSame($winner, $fixture->current());
                self::assertSame(
                    $recoveryWins ? UserState::PENDING_ACTIVATION : UserState::ACTIVE,
                    $fixture->users->all()[0]->getState()
                );
                self::assertSame($recoveryWins ? 2 : 1, count($fixture->grants->all()));
                self::assertSame([], $paused->audit->all());
                $this->assertRoles($fixture);
            }
        }

        $fixture = new RegistrationRecoveryFixture();
        try {
            $fixture->authentication('2030-01-08T00:00:00Z')->activate(
                $fixture->initialUser->getId(),
                'registration-fixture-credential',
                'long-new-password'
            );
            self::fail('Expired activation won before reissue.');
        } catch (LogicException) {
            $fixture->handler('2030-01-08T00:00:00Z')->handle(CommandMessage::create(
                $fixture->command('alice@example.test')
            ));
            self::assertTrue($fixture->current()->isIssued());
            $this->assertRoles($fixture);
        }
    }

    public function test_recovery_and_destination_reservation_have_both_winner_orders_without_unrelated_clear(): void
    {
        foreach ([false, true] as $recoveryWins) {
            $fixture = new RegistrationRecoveryFixture();
            $other = $fixture->connection();
            $reserved = UserFixture::withState('other@example.test', UserState::ACTIVE);
            $fixture->users->add($reserved);
            $reservation = clone $reserved;
            $reservation->requestEmailChange(
                EmailAddress::fromString('corrected@example.test'),
                new DateTimeImmutable('2030-01-02T00:00:00Z')
            );
            if ($recoveryWins) {
                // Independent address writer retained a pre-recovery snapshot.
                $fixture->handler()->handle(CommandMessage::create($fixture->command()));
                self::assertFalse($other->unitOfWork->commitTransactional(
                    fn(): bool => $other->users->replaceEmailChangeReservation($reserved, $reservation)
                ));
                self::assertNull($other->users->getById($reserved->getId())?->getPendingEmailChange());
            } else {
                $paused = null;
                $paused = $fixture->connection(beforeRecovery: static function () use (&$paused): void {
                    self::assertInstanceOf(RegistrationRecoveryFixture::class, $paused);
                    $paused->unitOfWork->suspendBeforeFirstWrite();
                });
                $loser = new Fiber(fn() => $paused->handler()->handle(CommandMessage::create($paused->command())));
                $loser->start();
                self::assertTrue($loser->isSuspended());
                self::assertTrue($other->unitOfWork->commitTransactional(
                    fn(): bool => $other->users->replaceEmailChangeReservation($reserved, $reservation)
                ));
                try {
                    $loser->resume();
                    self::fail('Recovery stole a committed destination reservation.');
                } catch (RegistrationRecoveryException) {
                    self::assertEquals($fixture->initial, $fixture->current());
                    self::assertSame([], $paused->audit->all());
                    self::assertSame(
                        'corrected@example.test',
                        $other->users->getById($reserved->getId())?->getPendingEmailChange()?->canonical()
                    );
                }
            }

            $this->assertRoles($fixture);
        }
    }

    public function test_successful_recovery_fences_all_user_and_grant_writers_through_commit_and_rollback(): void
    {
        foreach ([false, true] as $rollback) {
            foreach (['corrected@example.test', 'alice@example.test'] as $email) {
                $fixture = new RegistrationRecoveryFixture();
                $other = $fixture->connection();
                $fixture->unitOfWork->beforeCommit = function () use ($fixture, $other): void {
                    $other->unitOfWork->commitTransactional(function () use ($fixture, $other): void {
                        $current = $other->users->all()[0];
                        $activate = clone $current;
                        $activate->activate(
                            PasswordHash::fromString(password_hash('test-password', PASSWORD_BCRYPT)),
                            new DateTimeImmutable('2030-01-08Z')
                        );
                        $activate->advanceAuthenticationAuthorityRevision();
                        self::assertFalse($other->users->replaceAuthenticationAuthority($current, $activate));
                        $roles = clone $current;
                        $roles->removeRole(
                            $fixture->registration->reader->getId(),
                            new DateTimeImmutable('2030-01-08Z')
                        );
                        self::assertFalse($other->users->replaceRoleAssignments($current, $roles));
                        $correct = clone $current;
                        $correct->correctPendingInvitationEmail(
                            EmailAddress::fromString('late@example.test'),
                            new DateTimeImmutable('2030-01-08Z')
                        );
                        self::assertFalse($other->users->replacePendingInvitationEmail($current, $correct));
                        $grant = $other->current();
                        self::assertFalse($other->grants->replace(
                            $grant,
                            $grant->revoke(new DateTimeImmutable('2030-01-08Z'))
                        ));
                    });
                };
                $fixture->unitOfWork->failNextCommit = $rollback;
                try {
                    $fixture->handler('2030-01-08T00:00:00Z')->handle(CommandMessage::create(
                        $fixture->command($email)
                    ));
                    self::assertFalse($rollback);
                } catch (RuntimeException $caught) {
                    self::assertTrue($rollback);
                    self::assertSame('Injected transaction failure.', $caught->getMessage());
                }

                self::assertCount($rollback ? 1 : 2, $fixture->grants->all());
                self::assertCount($rollback ? 0 : 1, $fixture->audit->all());
                $this->assertRoles($fixture);
                if ($rollback) {
                    self::assertEquals($fixture->initial, $fixture->current());
                    self::assertEquals($fixture->initialUser, $fixture->users->all()[0]);
                }

                // Completion, including rollback, releases both fences for later valid work.
                $grant = $other->current();
                self::assertTrue($other->unitOfWork->commitTransactional(fn(): bool => $other->grants->replace(
                    $grant,
                    $grant->revoke(new DateTimeImmutable('2030-01-08Z'))
                )));
            }
        }
    }

    public function test_delayed_provider_delivered_retry_and_permanent_outcomes_in_both_winner_orders(): void
    {
        foreach (CredentialDeliveryOutcome::cases() as $outcome) {
            foreach ([false, true] as $recoveryFirst) {
                $fixture = new RegistrationRecoveryFixture();
                $provider = new RecordingCredentialDeliveryProvider([$outcome], static function () use (
                    $fixture,
                    $recoveryFirst
                ): void {
                    self::assertFalse($fixture->unitOfWork->transactionActive);
                    if ($recoveryFirst) {
                        $fixture->handler()->handle(CommandMessage::create($fixture->command()));
                    }
                });
                try {
                    $fixture->worker($provider)->handle(CommandMessage::create($fixture->delivery($fixture->initial)));
                    self::assertFalse($recoveryFirst);
                } catch (ActivationDeliveryNotRetryableException) {
                    self::assertTrue($recoveryFirst);
                }

                if (!$recoveryFirst) {
                    $fixture->handler()->handle(CommandMessage::create($fixture->command()));
                }

                self::assertCount(1, $provider->invocations());
                self::assertCount(2, $fixture->grants->all());
                self::assertSame(ActivationOrigin::REGISTRATION, $fixture->current()->getOrigin());
                self::assertSame(0, $fixture->current()->getRevision());
                self::assertSame(CredentialDeliveryStatus::PENDING, $fixture->current()->getDelivery()->getStatus());
                self::assertTrue($fixture->grants->all()[0]->isRevoked());
                self::assertNull($fixture->grants->all()[0]->getDelivery()->getEncryptedMaterial());
                $this->assertRoles($fixture);
            }
        }
    }

    public function test_lifecycle_orders_keep_pending_delete_ineligible_and_restoration_private(): void
    {
        foreach ([false, true] as $recoveryFirst) {
            $fixture = new RegistrationRecoveryFixture();
            $sessions = new InMemoryRefreshSessionRepository($fixture->unitOfWork);
            $delete = new DeleteUserHandler(
                $fixture->users,
                new SessionRevocationService($sessions),
                new FixedClock('2030-01-02T00:00:00Z'),
                $fixture->audit,
                $fixture->unitOfWork,
                new InMemoryEventDispatcher()
            );
            if ($recoveryFirst) {
                $fixture->handler()->handle(CommandMessage::create($fixture->command()));
            }

            try {
                $delete->handle(CommandMessage::create(new DeleteUser(
                    $fixture->actorId,
                    $fixture->initialUser->getId()
                )));
                self::fail('Pending deletion became a new allowed lifecycle transition.');
            } catch (UserLifecycleException) {
                self::assertSame(UserState::PENDING_ACTIVATION, $fixture->users->all()[0]->getState());
            }

            if (!$recoveryFirst) {
                // Pause stale recovery, then a legitimate activation/delete/restore journey wins.
                $paused = null;
                $paused = $fixture->connection(beforeRecovery: static function () use (&$paused): void {
                    self::assertInstanceOf(RegistrationRecoveryFixture::class, $paused);
                    $paused->unitOfWork->suspendBeforeFirstWrite();
                });
                $loser = new Fiber(fn() => $paused->handler()->handle(CommandMessage::create($paused->command())));
                $loser->start();
                self::assertTrue($loser->isSuspended());
                $fixture->authentication()->activate(
                    $fixture->initialUser->getId(),
                    'registration-fixture-credential',
                    'long-new-password'
                );
            } else {
                $fixture->authentication()->activate(
                    $fixture->initialUser->getId(),
                    'recovered-registration-credential',
                    'long-new-password'
                );
            }

            $delete->handle(CommandMessage::create(new DeleteUser($fixture->actorId, $fixture->initialUser->getId())));
            new RestoreUserHandler(
                $fixture->users,
                $fixture->grants,
                new FixedCredentialGenerator('restored-private-credential'),
                new PrefixInvitationDeliveryCipher(),
                new FixedClock('2030-01-02T00:00:02Z'),
                $fixture->audit,
                $fixture->unitOfWork,
                new InMemoryEventDispatcher()
            )->handle(CommandMessage::create(new RestoreUser(
                $fixture->actorId,
                $fixture->initialUser->getId(),
                UserState::PENDING_ACTIVATION
            )));
            $winner = $fixture->current();
            if (!$recoveryFirst) {
                try {
                    $loser->resume();
                    self::fail('Stale recovery overwrote a restored private invitation.');
                } catch (RegistrationRecoveryException) {
                    self::assertSame($winner, $fixture->current());
                    self::assertSame([], $paused->audit->all());
                }
            }

            self::assertSame(ActivationOrigin::PRIVATE_INVITATION, $winner->getOrigin());
            self::assertSame(UserState::PENDING_ACTIVATION, $fixture->users->all()[0]->getState());
            $this->assertRoles($fixture);
            try {
                $fixture->handler()->handle(CommandMessage::create($fixture->command(
                    expected: $winner->getDelivery()->getId()
                )));
                self::fail('Restored private work became registration recovery.');
            } catch (RegistrationRecoveryException) {
                self::assertSame($winner, $fixture->current());
            }
        }
    }

    private function assertRoles(RegistrationRecoveryFixture $fixture): void
    {
        self::assertEqualsCanonicalizing($fixture->initialUser->getRoleIds(), $fixture->users->all()[0]->getRoleIds());
        self::assertSame(
            $fixture->initialUser->getAuthorizationAssignmentRevision(),
            $fixture->users->all()[0]->getAuthorizationAssignmentRevision()
        );
    }
}
