<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User\CommandHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\ActivationGrant\EventSubscriber\InvitationDeliverySubscriber;
use Fight\AccessControl\Application\AccessControl\ActivationGrant\QueryHandler\FindInvitationDeliveryStatusHandler;
use Fight\AccessControl\Application\AccessControl\CredentialDelivery\Service\CredentialDeliveryInvocation;
use Fight\AccessControl\Application\AccessControl\CredentialDelivery\Service\CredentialDeliveryOutcome;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\CorrectPendingInvitationHandler;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\RecoverPendingRegistrationHandler;
use Fight\AccessControl\Application\AccessControl\User\QueryHandler\GetUserByIdHandler;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrant;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationOrigin;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Command\ResendInvitationDelivery;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Exception\ActivationDeliveryNotRetryableException;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Query\FindInvitationDeliveryStatus;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryStatus;
use Fight\AccessControl\Domain\AccessControl\User\Command\CorrectPendingInvitation;
use Fight\AccessControl\Domain\AccessControl\User\Command\RecoverPendingRegistration;
use Fight\AccessControl\Domain\AccessControl\User\Event\PendingRegistrationRecovered;
use Fight\AccessControl\Domain\AccessControl\User\Exception\DuplicateEmailException;
use Fight\AccessControl\Domain\AccessControl\User\Exception\PendingInvitationCorrectionException;
use Fight\AccessControl\Domain\AccessControl\User\Exception\RegistrationRecoveryException;
use Fight\AccessControl\Domain\AccessControl\User\Query\GetUserById;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Adapter\Messaging\Command\Sync\Routing\CommandRouter;
use Fight\Common\Adapter\Messaging\Command\Sync\RoutingCommandBus;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Fight\Common\Domain\Messaging\Event\EventMessage;
use Fight\Common\Domain\Messaging\Query\QueryMessage;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\Service as ActivationService;
use Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\Service\FixedCredentialGenerator;
use Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\Service\PrefixInvitationDeliveryCipher;
use Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\Service\UncertainActivationUnitOfWork;
use Fight\Test\AccessControl\Application\AccessControl\CredentialDelivery\Service\RecordingCredentialDeliveryProvider;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(RecoverPendingRegistrationHandler::class)]
#[CoversClass(InvitationDeliverySubscriber::class)]
#[CoversClass(ActivationGrant::class)]
final class RegistrationRecoveryJourneyTest extends TestCase
{
    public function test_both_variants_deliver_after_commit_and_activate_same_user_once(): void
    {
        foreach (['corrected@example.test', 'ALICE@example.test'] as $email) {
            $fixture = new RegistrationRecoveryFixture();
            $at = $email === 'ALICE@example.test' ? '2030-01-08T00:00:00Z' : '2030-01-02T00:00:00Z';
            $provider = new RecordingCredentialDeliveryProvider(onDeliver: static function (
                CredentialDeliveryInvocation $invocation
            ) use (
                $fixture,
                $email
): void {
                self::assertFalse($fixture->unitOfWork->transactionActive);
                self::assertCount(1, $fixture->audit->all());
                self::assertCount(2, $fixture->grants->all());
                self::assertSame(ActivationOrigin::REGISTRATION, $invocation->getActivationOrigin());
                self::assertSame('activation', $invocation->getPurpose());
                self::assertSame(strtolower($email), $invocation->getEmail()->canonical());
                self::assertSame('recovered-registration-credential', $invocation->getCredential());
                self::assertSame(UserState::PENDING_ACTIVATION, $fixture->users->all()[0]->getState());
                self::assertEqualsCanonicalizing(
                    $fixture->initialUser->getRoleIds(),
                    $fixture->users->all()[0]->getRoleIds()
                );
            });
            $router = $this->createStub(CommandRouter::class);
            $router->method('match')->willReturn($fixture->worker($provider, $at));
            $subscriber = new InvitationDeliverySubscriber(new RoutingCommandBus($router));
            $events = new InMemoryEventDispatcher(static function ($event) use ($subscriber): void {
                self::assertInstanceOf(PendingRegistrationRecovered::class, $event);
                $subscriber->onPendingRegistrationRecovered(EventMessage::create($event));
            });
            $fixture->handler($at, $events)->handle(CommandMessage::create($fixture->command($email)));
            self::assertCount(1, $provider->invocations());
            self::assertSame(
                $fixture->current()->getDelivery()->getId()->toString(),
                $provider->invocations()[0]->getIdempotencyId()
            );
            self::assertSame(CredentialDeliveryStatus::DELIVERED, $fixture->current()->getDelivery()->getStatus());
            self::assertSame(UserState::PENDING_ACTIVATION, $fixture->users->all()[0]->getState());
            $authentication = $fixture->authentication($at);
            try {
                $authentication->activate(
                    $fixture->initialUser->getId(),
                    'registration-fixture-credential',
                    'long-new-password'
                );
                self::fail('The predecessor token activated the User.');
            } catch (LogicException) {
                self::assertNull($fixture->users->all()[0]->getPasswordHash());
            }

            $tokens = $authentication->activate(
                $fixture->initialUser->getId(),
                $provider->invocations()[0]->getCredential(),
                'long-new-password'
            );
            self::assertSame($fixture->initialUser->getId(), $tokens->getUserId());
            self::assertSame(UserState::ACTIVE, $fixture->users->all()[0]->getState());
            self::assertCount(1, $fixture->users->all());
            self::assertEqualsCanonicalizing(
                $fixture->initialUser->getRoleIds(),
                $fixture->users->all()[0]->getRoleIds()
            );
            try {
                $authentication->activate(
                    $fixture->initialUser->getId(),
                    $provider->invocations()[0]->getCredential(),
                    'another-password'
                );
                self::fail('Activation replay succeeded.');
            } catch (LogicException) {
                self::assertTrue($fixture->current()->isConsumed());
            }
        }
    }

    public function test_terminal_delivery_does_not_prevent_fresh_expired_reissue(): void
    {
        foreach (['delivered', 'permanent', 'expired'] as $state) {
            $fixture = new RegistrationRecoveryFixture();
            if ($state === 'expired') {
                self::assertTrue($fixture->grants->replace(
                    $fixture->initial,
                    $fixture->initial->expireDeliveryAt(new DateTimeImmutable('2030-01-08T00:00:00Z'))
                ));
            } else {
                $outcome = CredentialDeliveryOutcome::DELIVERED;
                if ($state === 'permanent') {
                    $outcome = CredentialDeliveryOutcome::PERMANENT_FAILURE;
                }

                $fixture->worker(new RecordingCredentialDeliveryProvider([$outcome]))->handle(
                    CommandMessage::create($fixture->delivery())
                );
            }

            $beforeAudit = count($fixture->audit->all());
            self::assertTrue($fixture->current()->isIssued());
            self::assertSame(UserState::PENDING_ACTIVATION, $fixture->users->all()[0]->getState());
            $fixture->handler('2030-01-08T00:00:00Z')->handle(CommandMessage::create(
                $fixture->command('alice@example.test')
            ));
            self::assertCount(2, $fixture->grants->all());
            self::assertCount($beforeAudit + 1, $fixture->audit->all());
            self::assertTrue($fixture->current()->isIssued());
            self::assertSame(CredentialDeliveryStatus::PENDING, $fixture->current()->getDelivery()->getStatus());
            self::assertEquals($fixture->initialUser, $fixture->users->all()[0]);
        }
    }

    public function test_both_uncertain_outcomes_and_variants_resolve_from_persisted_state(): void
    {
        foreach (['corrected@example.test', 'alice@example.test'] as $email) {
            foreach ([false, true] as $committed) {
                $fixture = new RegistrationRecoveryFixture();
                $command = $fixture->command($email);
                $at = '2030-01-08T00:00:00Z';
                try {
                    $fixture->handler($at, unitOfWork: new UncertainActivationUnitOfWork(
                        $fixture->unitOfWork,
                        1,
                        $committed
                    ))->handle(CommandMessage::create($command));
                    self::fail('An uncertain commit was reported as confirmed success.');
                } catch (RuntimeException $caught) {
                    self::assertSame('Uncertain transaction result.', $caught->getMessage());
                }

                $fixture->grants = $fixture->grants->restart();
                $view = new GetUserByIdHandler($fixture->users)->handle(QueryMessage::create(new GetUserById(
                    $fixture->initialUser->getId()
                )));
                $status = new FindInvitationDeliveryStatusHandler($fixture->grants)->handle(QueryMessage::create(
                    new FindInvitationDeliveryStatus($fixture->initialUser->getId())
                ));
                self::assertNotNull($view);
                self::assertNotNull($status);
                self::assertEqualsCanonicalizing($fixture->initialUser->getRoleIds(), $view->getRoleIds());
                self::assertCount((int) $committed, $fixture->audit->all());
                self::assertCount(1 + (int) $committed, $fixture->grants->all());
                self::assertInstanceOf(CommandFailedEvent::class, $fixture->events->events()[0]);
                $safe = serialize([$view->toArray(), $status->toArray(), $fixture->events->events()[0]->toArray()]);
                self::assertStringNotContainsString('credential', $safe);
                self::assertStringNotContainsString('ciphertext', $safe);
                if (!$committed) {
                    self::assertEquals($fixture->initial->getId(), $fixture->current()->getId());
                    self::assertTrue($fixture->current()->isIssued());
                    self::assertSame($fixture->initial->getCredentialHash(), $fixture->current()->getCredentialHash());
                    self::assertSame(0, $fixture->current()->getRevision());
                    self::assertEquals($fixture->initialUser, $fixture->users->all()[0]);
                    $fixture->handler($at)->handle(CommandMessage::create($command));
                } else {
                    self::assertFalse($fixture->initial->getDelivery()->getId()->equals(
                        $status->getActivationDeliveryId()
                    ));
                    $this->assertReplayRejected($fixture, $command, $at);
                }

                $this->assertRestartDelivery($fixture, $at);
            }
        }
    }

    public function test_response_loss_and_double_publication_failure_keep_durable_successor(): void
    {
        foreach (['corrected@example.test', 'alice@example.test'] as $email) {
            foreach ([false, true] as $publicationFailure) {
                $fixture = new RegistrationRecoveryFixture();
                $original = new RuntimeException('private recovered-registration-credential ciphertext:diagnostic');
                $attempts = [];
                $events = new InMemoryEventDispatcher(static function ($event) use (
                    &$attempts,
                    $original,
                    $publicationFailure
                ): void {
                    $attempts[] = $event;
                    if ($publicationFailure) {
                        if ($event instanceof PendingRegistrationRecovered) {
                            throw $original;
                        }

                        throw new RuntimeException('secondary notification failure');
                    }
                });
                $command = $fixture->command($email);
                $at = '2030-01-08T00:00:00Z';
                try {
                    $fixture->handler($at, $events)->handle(CommandMessage::create($command));
                    self::assertFalse($publicationFailure);
                } catch (RuntimeException $caught) {
                    self::assertTrue($publicationFailure);
                    self::assertSame($original, $caught);
                }

                self::assertCount($publicationFailure ? 2 : 1, $attempts);
                self::assertInstanceOf(PendingRegistrationRecovered::class, $attempts[0]);
                if ($publicationFailure) {
                    self::assertInstanceOf(CommandFailedEvent::class, $attempts[1]);
                    self::assertSame($command, $attempts[1]->getCommand());
                    self::assertSame('Pending registration recovery failed.', $attempts[1]->getErrorMessage());
                }

                self::assertStringNotContainsString('recovered-registration-credential', serialize($attempts));
                self::assertCount(1, $fixture->audit->all());
                $fixture->grants = $fixture->grants->restart();
                $this->assertReplayRejected($fixture, $command, $at);
                $this->assertRestartDelivery($fixture, $at);
            }
        }
    }

    public function test_provider_fault_retry_restart_and_resend_keep_registration_origin_and_per_generation_id(): void
    {
        $fixture = new RegistrationRecoveryFixture();
        $fixture->handler()->handle(CommandMessage::create($fixture->command()));
        $successor = $fixture->current();
        $provider = new RecordingCredentialDeliveryProvider([new RuntimeException('sensitive provider diagnostic')]);
        $fixture->worker($provider)->handle(CommandMessage::create($fixture->delivery()));
        self::assertSame(CredentialDeliveryStatus::RETRY_PENDING, $fixture->current()->getDelivery()->getStatus());
        $fixture->grants = $fixture->grants->restart();
        $due = $fixture->grants->findDue(new DateTimeImmutable('2030-01-02T00:01:00Z'), 50);
        self::assertCount(1, $due);
        self::assertEquals($successor->getDelivery()->getId(), $due[0]->getDeliveryId());
        $fixture->worker($provider, '2030-01-02T00:01:00Z')->handle(CommandMessage::create($fixture->delivery()));
        self::assertCount(2, $provider->invocations());
        foreach ($provider->invocations() as $invocation) {
            self::assertSame(ActivationOrigin::REGISTRATION, $invocation->getActivationOrigin());
            self::assertSame($successor->getDelivery()->getId()->toString(), $invocation->getIdempotencyId());
        }

        $fixture->resend()->handle(CommandMessage::create(new ResendInvitationDelivery(
            'administrator',
            $fixture->initialUser->getId()
        )));
        self::assertSame(ActivationOrigin::REGISTRATION, $fixture->current()->getOrigin());
        self::assertFalse($successor->getDelivery()->getId()->equals($fixture->current()->getDelivery()->getId()));
        self::assertCount(1, $fixture->users->all());
        self::assertEqualsCanonicalizing($fixture->initialUser->getRoleIds(), $fixture->users->all()[0]->getRoleIds());
    }

    public function test_private_correction_and_duplicate_registration_cannot_recover_or_reset_existing_roles(): void
    {
        $fixture = new RegistrationRecoveryFixture();
        $private = new CorrectPendingInvitationHandler(
            $fixture->users,
            $fixture->grants,
            new ActivationService\FixedInvitationAdministrationAuthorization(true),
            $fixture->audit,
            $fixture->unitOfWork,
            new FixedCredentialGenerator('private-correction'),
            new PrefixInvitationDeliveryCipher(),
            new FixedClock('2030-01-02T00:00:00Z'),
            new InMemoryEventDispatcher()
        );
        try {
            $private->handle(CommandMessage::create(new CorrectPendingInvitation(
                $fixture->actorId,
                $fixture->initialUser->getId(),
                EmailAddress::fromString('corrected@example.test')
            )));
            self::fail('Private correction accepted a registration.');
        } catch (PendingInvitationCorrectionException) {
            self::assertEquals($fixture->initial, $fixture->current());
        }

        try {
            $fixture->registration->handler(['ROLE_READER'])->handle(CommandMessage::create(
                $fixture->registration->command('ALICE@example.test')
            ));
            self::fail('Duplicate registration reclaimed an existing identity.');
        } catch (DuplicateEmailException) {
            self::assertCount(1, $fixture->users->all());
            self::assertEqualsCanonicalizing(
                $fixture->initialUser->getRoleIds(),
                $fixture->users->all()[0]->getRoleIds()
            );
        }

        $fixture->handler()->handle(CommandMessage::create($fixture->command()));
        self::assertCount(2, $fixture->users->all()[0]->getRoleIds());
        self::assertEqualsCanonicalizing($fixture->initialUser->getRoleIds(), $fixture->users->all()[0]->getRoleIds());
    }

    public function test_existing_protected_reads_are_fresh_and_read_only_and_supply_expected_generation(): void
    {
        $fixture = new RegistrationRecoveryFixture();
        $statusHandler = new FindInvitationDeliveryStatusHandler($fixture->grants);
        $userHandler = new GetUserByIdHandler($fixture->users);
        $before = $fixture->unitOfWork->transactions;
        $statusQuery = QueryMessage::create(new FindInvitationDeliveryStatus($fixture->initialUser->getId()));
        $userQuery = QueryMessage::create(new GetUserById($fixture->initialUser->getId()));
        $status = $statusHandler->handle($statusQuery);
        $user = $userHandler->handle($userQuery);
        self::assertNotNull($status);
        self::assertNotNull($user);
        self::assertEquals($fixture->initial->getDelivery()->getId(), $status->getActivationDeliveryId());
        self::assertSame($before, $fixture->unitOfWork->transactions);
        self::assertSame([], $fixture->events->events());
        self::assertSame([], $fixture->audit->all());
        $fixture->handler()->handle(CommandMessage::create($fixture->command(
            expected: $status->getActivationDeliveryId()
        )));
        $before = $fixture->unitOfWork->transactions;
        $nextStatus = $statusHandler->handle($statusQuery);
        $nextUser = $userHandler->handle($userQuery);
        self::assertNotNull($nextStatus);
        self::assertNotNull($nextUser);
        self::assertFalse($status->getActivationDeliveryId()->equals($nextStatus->getActivationDeliveryId()));
        self::assertEquals($fixture->current()->getDelivery()->getId(), $nextStatus->getActivationDeliveryId());
        self::assertSame('corrected@example.test', $nextUser->getEmail()->canonical());
        self::assertSame('alice@example.test', $user->getEmail()->canonical());
        self::assertSame($before, $fixture->unitOfWork->transactions);
        self::assertCount(1, $fixture->events->events());
        self::assertCount(1, $fixture->audit->all());
    }

    private function assertReplayRejected(
        RegistrationRecoveryFixture $fixture,
        RecoverPendingRegistration $command,
        string $at
    ): void {
        $before = $fixture->current();
        try {
            $fixture->handler($at)->handle(CommandMessage::create($command));
            self::fail('Replaying a stale request issued a second success.');
        } catch (RegistrationRecoveryException) {
            self::assertSame($before, $fixture->current());
            self::assertCount(2, $fixture->grants->all());
            self::assertCount(1, $fixture->audit->all());
            foreach ($fixture->events->events() as $event) {
                self::assertInstanceOf(CommandFailedEvent::class, $event);
            }
        }
    }

    private function assertRestartDelivery(RegistrationRecoveryFixture $fixture, string $at): void
    {
        $due = $fixture->grants->findDue(new DateTimeImmutable($at), 50);
        self::assertCount(1, $due);
        self::assertEquals($fixture->current()->getDelivery()->getId(), $due[0]->getDeliveryId());
        $provider = new RecordingCredentialDeliveryProvider();
        try {
            $fixture->worker($provider, $at)->handle(CommandMessage::create($fixture->delivery($fixture->initial)));
            self::fail('Obsolete delivery invoked as current work.');
        } catch (ActivationDeliveryNotRetryableException) {
            self::assertCount(0, $provider->invocations());
        }

        $fixture->worker($provider, $at)->handle(CommandMessage::create($fixture->delivery()));
        self::assertCount(1, $provider->invocations());
        self::assertSame(ActivationOrigin::REGISTRATION, $provider->invocations()[0]->getActivationOrigin());
        self::assertSame($due[0]->getDeliveryId()->toString(), $provider->invocations()[0]->getIdempotencyId());
        self::assertSame(CredentialDeliveryStatus::DELIVERED, $fixture->current()->getDelivery()->getStatus());
    }
}
