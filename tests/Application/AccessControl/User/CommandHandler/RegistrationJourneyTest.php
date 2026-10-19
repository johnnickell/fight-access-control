<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User\CommandHandler;

use DateInterval;
use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\ActivationGrant\CommandHandler\DeliverUserInvitationHandler;
use Fight\AccessControl\Application\AccessControl\ActivationGrant\EventSubscriber\InvitationDeliverySubscriber;
use Fight\AccessControl\Application\AccessControl\ActivationGrant\QueryHandler\FindInvitationDeliveryStatusHandler;
use Fight\AccessControl\Application\AccessControl\CredentialDelivery\QueryHandler\FindDueCredentialDeliveriesHandler;
use Fight\AccessControl\Application\AccessControl\CredentialDelivery\Service\CredentialDeliveryInvocation;
use Fight\AccessControl\Application\AccessControl\RefreshSession\Service\SessionRevocationService;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\RegisterUserHandler;
use Fight\AccessControl\Application\AccessControl\User\QueryHandler\GetUserByIdHandler;
use Fight\AccessControl\Application\AccessControl\User\Security\AuthenticationService;
use Fight\AccessControl\Application\AccessControl\User\Security\AuthenticationTokenPolicy;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationOrigin;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Command\DeliverUserInvitation;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Query\FindInvitationDeliveryStatus;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryStatus;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\DueCredentialDelivery;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\Query\FindDueCredentialDeliveries;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshCredential;
use Fight\AccessControl\Domain\AccessControl\User\Event\UserRegistered;
use Fight\AccessControl\Domain\AccessControl\User\Exception\DuplicateEmailException;
use Fight\AccessControl\Domain\AccessControl\User\PasswordHash;
use Fight\AccessControl\Domain\AccessControl\User\Query\GetUserById;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Adapter\Messaging\Command\Sync\Routing\CommandRouter;
use Fight\Common\Adapter\Messaging\Command\Sync\RoutingCommandBus;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Fight\Common\Domain\Messaging\Event\EventMessage;
use Fight\Common\Domain\Messaging\Query\QueryMessage;
use Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\Repository\InMemoryActivationGrantRepository;
use Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\Service\PrefixInvitationDeliveryCipher;
use Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\Service\UncertainActivationUnitOfWork;
use Fight\Test\AccessControl\Application\AccessControl\CredentialDelivery\Service\RecordingCredentialDeliveryProvider;
use Fight\Test\AccessControl\Application\AccessControl\EmailChangeGrant\Repository\InMemoryEmailChangeGrantRepository;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\PasswordResetGrant\Repository\InMemoryPasswordResetGrants;
use Fight\Test\AccessControl\Application\AccessControl\RefreshSession\Repository\InMemoryRefreshSessionRepository;
use Fight\Test\AccessControl\Application\AccessControl\RefreshSession\Service\FixedRefreshCredentialGenerator;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryUserRepository;
use Fight\Test\AccessControl\Application\AccessControl\User\Security\RecordingTokenEncoder;
use Fight\Test\AccessControl\Application\AccessControl\User\Security\TestPasswordSecurity;
use Fight\Test\AccessControl\Application\AccessControl\User\Service\FixedLoginThrottle;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(DeliverUserInvitationHandler::class)]
#[CoversClass(RegisterUserHandler::class)]
#[CoversClass(InvitationDeliverySubscriber::class)]
final class RegistrationJourneyTest extends TestCase
{
    public function test_registration_subscriber_provider_and_single_use_activation_keep_the_same_user_and_roles(): void
    {
        $subscriber = null;
        $fixture = new RegistrationFixture(events: new InMemoryEventDispatcher(
            static function ($event) use (&$subscriber): void {
                self::assertInstanceOf(UserRegistered::class, $event);
                self::assertInstanceOf(InvitationDeliverySubscriber::class, $subscriber);
                $subscriber->onUserRegistered(EventMessage::create($event));
            }
        ));
        $command = $fixture->command();
        $provider = new RecordingCredentialDeliveryProvider(onDeliver: static function (
            CredentialDeliveryInvocation $invocation
        ) use (
            $fixture,
            $command
        ): void {
            self::assertFalse($fixture->unitOfWork->transactionActive);
            self::assertSame(ActivationOrigin::REGISTRATION, $invocation->getActivationOrigin());
            self::assertSame('activation', $invocation->getPurpose());
            self::assertSame('alice@example.test', $invocation->getEmail()->canonical());
            self::assertSame('registration-fixture-credential', $invocation->getCredential());
            self::assertSame(
                UserState::PENDING_ACTIVATION,
                $fixture->users->getById($command->getUserId())?->getState()
            );
            self::assertCount(2, $fixture->users->all()[0]->getRoleIds());
        });
        $router = $this->createStub(CommandRouter::class);
        $router->method('match')->willReturn($this->worker($fixture, $provider));
        $subscriber = new InvitationDeliverySubscriber(new RoutingCommandBus($router));
        $fixture->handler()->handle(CommandMessage::create($command));
        self::assertCount(1, $provider->invocations());
        self::assertSame(
            $fixture->grants->all()[0]->getDelivery()->getId()->toString(),
            $provider->invocations()[0]->getIdempotencyId()
        );
        self::assertSame(UserState::PENDING_ACTIVATION, $fixture->users->all()[0]->getState());
        self::assertSame(CredentialDeliveryStatus::DELIVERED, $fixture->grants->all()[0]->getDelivery()->getStatus());

        $service = $this->authentication($fixture);
        $tokens = $service->activate(
            $command->getUserId(),
            $provider->invocations()[0]->getCredential(),
            'new-password'
        );
        self::assertSame($command->getUserId(), $tokens->getUserId());
        self::assertCount(1, $fixture->users->all());
        self::assertSame(UserState::ACTIVE, $fixture->users->all()[0]->getState());
        self::assertEqualsCanonicalizing(
            [$fixture->member->getId(), $fixture->reader->getId()],
            $fixture->users->all()[0]->getRoleIds()
        );
        self::assertFalse($fixture->grants->all()[0]->isIssued());
        $this->expectException(LogicException::class);
        $service->activate($command->getUserId(), $provider->invocations()[0]->getCredential(), 'another-password');
    }

    public function test_both_uncertain_creation_outcomes_are_distinguished_by_protected_reads_and_due_work(): void
    {
        foreach ([false, true] as $committed) {
            $fixture = new RegistrationFixture();
            $command = $fixture->command();
            try {
                $fixture->handler(unitOfWork: new UncertainActivationUnitOfWork(
                    $fixture->unitOfWork,
                    1,
                    $committed
                ))->handle(CommandMessage::create($command));
                self::fail('Uncertain commit was reported as success.');
            } catch (RuntimeException $caught) {
                self::assertSame('Uncertain transaction result.', $caught->getMessage());
            }

            $fixture->grants = $fixture->grants->restart();
            $restartedUsers = new InMemoryUserRepository($fixture->unitOfWork, state: $fixture->userState);
            $view = new GetUserByIdHandler($restartedUsers)->handle(QueryMessage::create(new GetUserById(
                $command->getUserId()
            )));
            $status = new FindInvitationDeliveryStatusHandler($fixture->grants)->handle(QueryMessage::create(
                new FindInvitationDeliveryStatus($command->getUserId())
            ));
            self::assertCount((int) $committed, $fixture->users->all());
            self::assertCount((int) $committed, $fixture->grants->all());
            self::assertCount((int) $committed, $fixture->audit->all());
            self::assertInstanceOf(CommandFailedEvent::class, $fixture->events->events()[0]);
            self::assertSame('User registration failed.', $fixture->events->events()[0]->getErrorMessage());
            if (!$committed) {
                self::assertNull($view);
                self::assertNull($status);
                self::assertSame([], $this->discover($fixture));
                continue;
            }

            self::assertNotNull($view);
            self::assertNotNull($status);
            self::assertSame(UserState::PENDING_ACTIVATION, $view->getState());
            self::assertCount(2, $view->getRoleIds());
            self::assertStringNotContainsString('credential', serialize($view->toArray()));
            self::assertStringNotContainsString('registration-fixture', serialize($status->toArray()));
            $this->recover($fixture);
        }
    }

    public function test_both_publications_can_fail_without_losing_committed_delivery_or_original_failure(): void
    {
        $original = new RuntimeException('private registration-fixture-credential subscriber diagnostic');
        $attempts = [];
        $fixture = new RegistrationFixture(events: new InMemoryEventDispatcher(
            static function ($event) use (&$attempts, $original): void {
                $attempts[] = $event;
                if ($event instanceof UserRegistered) {
                    throw $original;
                }

                throw new RuntimeException('second publisher failure');
            }
        ));
        $command = $fixture->command();
        try {
            $fixture->handler()->handle(CommandMessage::create($command));
            self::fail('The post-commit failure disappeared.');
        } catch (RuntimeException $runtimeException) {
            self::assertSame($original, $runtimeException);
        }

        self::assertCount(2, $attempts);
        self::assertInstanceOf(UserRegistered::class, $attempts[0]);
        self::assertInstanceOf(CommandFailedEvent::class, $attempts[1]);
        self::assertSame($command, $attempts[1]->getCommand());
        self::assertSame('User registration failed.', $attempts[1]->getErrorMessage());
        self::assertStringNotContainsString('registration-fixture-credential', serialize($attempts[1]->toArray()));
        self::assertCount(1, $fixture->users->all());
        self::assertCount(1, $fixture->audit->all());
        self::assertCount(2, $fixture->users->all()[0]->getRoleIds());
        $fixture->grants = $fixture->grants->restart();
        $this->recover($fixture);
    }

    public function test_subscriber_worker_storage_failure_keeps_evidence_safe_and_registration_recoverable(): void
    {
        $this->assertWorkerStorageFailure(false);
    }

    public function test_subscriber_worker_notification_failure_preserves_the_original_storage_throwable(): void
    {
        $this->assertWorkerStorageFailure(true);
    }

    public function test_lost_response_does_not_make_duplicate_registration_a_retry_or_replay_fact(): void
    {
        $fixture = new RegistrationFixture();
        $command = $fixture->command();
        $fixture->handler()->handle(CommandMessage::create($command));
        $deliveryId = $fixture->grants->all()[0]->getDelivery()->getId();
        // Caller response and transient subscribers are lost; only persisted work is reused.
        $fixture->grants = $fixture->grants->restart();
        try {
            $fixture->handler()->handle(CommandMessage::create($command));
            self::fail('A duplicate became an implicit recovery command.');
        } catch (DuplicateEmailException) {
        }

        self::assertCount(1, $fixture->users->all());
        self::assertCount(1, $fixture->grants->all());
        self::assertCount(1, $fixture->audit->all());
        self::assertCount(2, $fixture->events->events());
        self::assertInstanceOf(UserRegistered::class, $fixture->events->events()[0]);
        self::assertInstanceOf(CommandFailedEvent::class, $fixture->events->events()[1]);
        self::assertEquals($deliveryId, $fixture->grants->all()[0]->getDelivery()->getId());
        $this->recover($fixture);
    }

    public function test_provider_failure_after_registration_is_retryable_on_restart_without_reissuing_identity(): void
    {
        $fixture = new RegistrationFixture();
        $fixture->handler()->handle(CommandMessage::create($fixture->command()));
        $provider = new RecordingCredentialDeliveryProvider([new RuntimeException('private provider fault')]);
        $this->worker($fixture, $provider)->handle(CommandMessage::create($this->deliveryCommand($fixture)));
        self::assertSame(
            CredentialDeliveryStatus::RETRY_PENDING,
            $fixture->grants->all()[0]->getDelivery()->getStatus()
        );
        $id = $fixture->grants->all()[0]->getDelivery()->getId()->toString();
        $fixture->grants = $fixture->grants->restart();
        self::assertCount(1, $this->discover($fixture, '2030-01-01T00:01:00Z'));
        $this->worker($fixture, $provider, '2030-01-01T00:01:00Z')->handle(CommandMessage::create(
            $this->deliveryCommand($fixture)
        ));
        self::assertCount(2, $provider->invocations());
        foreach ($provider->invocations() as $invocation) {
            self::assertSame(ActivationOrigin::REGISTRATION, $invocation->getActivationOrigin());
            self::assertSame($id, $invocation->getIdempotencyId());
        }

        self::assertCount(1, $fixture->users->all());
        self::assertCount(2, $fixture->users->all()[0]->getRoleIds());
        self::assertSame(UserState::PENDING_ACTIVATION, $fixture->users->all()[0]->getState());
        self::assertSame(CredentialDeliveryStatus::DELIVERED, $fixture->grants->all()[0]->getDelivery()->getStatus());
    }

    private function assertWorkerStorageFailure(bool $failWorkerNotification): void
    {
        $rowMarker = 'SYNTHETIC-ROW-MATERIAL-NOT-FOR-EVENTS';
        $original = new RuntimeException('row credential: '.$rowMarker);
        $notificationFault = new RuntimeException('secondary worker notification diagnostic');
        $subscriber = null;
        $fixture = new RegistrationFixture(events: new InMemoryEventDispatcher(
            static function ($event) use (&$subscriber): void {
                if ($event instanceof UserRegistered) {
                    self::assertInstanceOf(InvitationDeliverySubscriber::class, $subscriber);
                    $subscriber->onUserRegistered(EventMessage::create($event));
                }
            }
        ));
        $fixture->grants = new InMemoryActivationGrantRepository(
            $fixture->unitOfWork,
            beforeReplace: static fn() => throw $original
        );
        $workerAttempts = [];
        $workerEvents = new InMemoryEventDispatcher(static function ($event) use (
            &$workerAttempts,
            $failWorkerNotification,
            $notificationFault
        ): void {
            $workerAttempts[] = $event;
            if ($failWorkerNotification) {
                throw $notificationFault;
            }
        });
        $provider = new RecordingCredentialDeliveryProvider();
        $router = $this->createStub(CommandRouter::class);
        $router->method('match')->willReturn($this->worker($fixture, $provider, events: $workerEvents));
        $subscriber = new InvitationDeliverySubscriber(new RoutingCommandBus($router));
        $command = $fixture->command();
        try {
            $fixture->handler()->handle(CommandMessage::create($command));
            self::fail('The worker storage failure disappeared.');
        } catch (RuntimeException $runtimeException) {
            self::assertSame($original, $runtimeException);
        }

        self::assertCount(1, $workerAttempts);
        self::assertCount((int) !$failWorkerNotification, $workerEvents->events());
        self::assertCount(1, $fixture->events->events());
        $outerFailure = $fixture->events->events()[0];
        self::assertInstanceOf(CommandFailedEvent::class, $outerFailure);
        self::assertSame($command, $outerFailure->getCommand());
        self::assertSame('User registration failed.', $outerFailure->getErrorMessage());
        self::assertInstanceOf(CommandFailedEvent::class, $workerAttempts[0]);
        $workerCommand = $workerAttempts[0]->getCommand();
        self::assertInstanceOf(DeliverUserInvitation::class, $workerCommand);
        self::assertSame('registration', $workerCommand->getActorId());
        self::assertSame($command->getUserId(), $workerCommand->getUserId());
        self::assertSame('User invitation delivery failed.', $workerAttempts[0]->getErrorMessage());
        foreach ([$outerFailure, ...$workerAttempts] as $failure) {
            $payload = serialize($failure->toArray());
            self::assertStringNotContainsString($rowMarker, $payload);
            self::assertStringNotContainsString('registration-fixture-credential', $payload);
            self::assertStringNotContainsString($notificationFault->getMessage(), $payload);
        }

        self::assertFalse($fixture->unitOfWork->transactionActive);
        self::assertCount(0, $provider->invocations());
        self::assertCount(1, $fixture->users->all());
        self::assertSame($command->getUserId(), $fixture->users->all()[0]->getId());
        self::assertEqualsCanonicalizing(
            [$fixture->member->getId(), $fixture->reader->getId()],
            $fixture->users->all()[0]->getRoleIds()
        );
        self::assertCount(1, $fixture->audit->all());
        self::assertSame('user.registered', $fixture->audit->all()[0]->action());
        self::assertCount(1, $fixture->grants->all());
        $deliveryId = $fixture->grants->all()[0]->getDelivery()->getId();
        self::assertEquals($deliveryId, $workerCommand->getActivationDeliveryId());
        self::assertSame(CredentialDeliveryStatus::PENDING, $fixture->grants->all()[0]->getDelivery()->getStatus());
        $fixture->grants = $fixture->grants->restart();
        self::assertEquals($deliveryId, $this->discover($fixture)[0]->getDeliveryId());
        $this->recover($fixture);
        self::assertCount(1, $fixture->users->all());
        self::assertCount(2, $fixture->users->all()[0]->getRoleIds());
    }

    private function recover(RegistrationFixture $fixture): void
    {
        $due = $this->discover($fixture);
        self::assertCount(1, $due);
        $provider = new RecordingCredentialDeliveryProvider();
        $this->worker($fixture, $provider)->handle(CommandMessage::create(DeliverUserInvitation::fromArray([
            'actor_id'               => 'restarted-worker',
            'user_id'                => $due[0]->getUserId()->toString(),
            'activation_delivery_id' => $due[0]->getDeliveryId()->toString()
        ])));
        self::assertCount(1, $provider->invocations());
        self::assertSame(ActivationOrigin::REGISTRATION, $provider->invocations()[0]->getActivationOrigin());
        self::assertSame($due[0]->getDeliveryId()->toString(), $provider->invocations()[0]->getIdempotencyId());
        self::assertSame(UserState::PENDING_ACTIVATION, $fixture->users->all()[0]->getState());
        self::assertSame(CredentialDeliveryStatus::DELIVERED, $fixture->grants->all()[0]->getDelivery()->getStatus());
        self::assertSame([], $this->discover($fixture));
    }

    /** @return list<DueCredentialDelivery> */
    private function discover(RegistrationFixture $fixture, string $at = '2030-01-01T00:00:00Z'): array
    {
        return new FindDueCredentialDeliveriesHandler(
            $fixture->grants,
            new InMemoryPasswordResetGrants(),
            new InMemoryEmailChangeGrantRepository()
        )->handle(QueryMessage::create(new FindDueCredentialDeliveries(new DateTimeImmutable($at), 50)));
    }

    private function worker(
        RegistrationFixture $fixture,
        RecordingCredentialDeliveryProvider $provider,
        string $at = '2030-01-01T00:00:00Z',
        ?InMemoryEventDispatcher $events = null
    ): DeliverUserInvitationHandler {
        return new DeliverUserInvitationHandler(
            $fixture->grants,
            $fixture->audit,
            $fixture->unitOfWork,
            new PrefixInvitationDeliveryCipher(),
            $provider,
            new FixedClock($at),
            $events ?? new InMemoryEventDispatcher()
        );
    }

    private function deliveryCommand(RegistrationFixture $fixture): DeliverUserInvitation
    {
        return new DeliverUserInvitation(
            'worker',
            $fixture->users->all()[0]->getId(),
            $fixture->grants->all()[0]->getDelivery()->getId()
        );
    }

    private function authentication(RegistrationFixture $fixture): AuthenticationService
    {
        $sessions = new InMemoryRefreshSessionRepository($fixture->unitOfWork);
        $fixture->users->bindRefreshSessionRepository($sessions);
        $passwords = new TestPasswordSecurity();

        return new AuthenticationService(
            $fixture->users,
            $fixture->grants,
            $sessions,
            new SessionRevocationService($sessions),
            $fixture->unitOfWork,
            new FixedClock('2030-01-01T00:00:01Z'),
            new FixedLoginThrottle(true),
            new FixedRefreshCredentialGenerator(RefreshCredential::fromString(str_repeat('a', 64))),
            $passwords,
            $passwords,
            new RecordingTokenEncoder(),
            AuthenticationTokenPolicy::starterDefaults(new DateInterval('PT5S')),
            PasswordHash::fromString($passwords->hash('unused-dummy-password')),
            new InMemoryEventDispatcher(),
            new InMemoryPasswordResetGrants($fixture->unitOfWork),
            $fixture->audit,
            new InMemoryEmailChangeGrantRepository($fixture->unitOfWork)
        );
    }
}
