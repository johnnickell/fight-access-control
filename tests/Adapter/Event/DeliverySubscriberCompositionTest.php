<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Event;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\ActivationGrant\EventSubscriber\InvitationDeliverySubscriber;
use Fight\AccessControl\Application\AccessControl\EmailChangeGrant\EventSubscriber\EmailChangeDeliverySubscriber;
use Fight\AccessControl\Application\AccessControl\PasswordResetGrant\EventSubscriber\PasswordResetDeliverySubscriber;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\InvitePendingUserHandler;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationDeliveryId;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Command\DeliverUserInvitation;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Event\InvitationDeliveryResent;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Event\InvitationDeliveryRetryRequested;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Command\DeliverEmailChange;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeDeliveryId;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Event\EmailChangeRequested;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\Command\DeliverPasswordReset;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\Event\PasswordResetRequested;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetDeliveryId;
use Fight\AccessControl\Domain\AccessControl\User\Command\InvitePendingUser;
use Fight\AccessControl\Domain\AccessControl\User\Event\PendingRegistrationRecovered;
use Fight\AccessControl\Domain\AccessControl\User\Event\UserInvited;
use Fight\AccessControl\Domain\AccessControl\User\Event\UserRegistered;
use Fight\AccessControl\Domain\AccessControl\User\Event\UserRestored;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Adapter\Messaging\Command\Sync\Routing\InMemoryCommandRouter;
use Fight\Common\Adapter\Messaging\Command\Sync\RoutingCommandBus;
use Fight\Common\Adapter\Messaging\Event\Sync\SimpleEventDispatcher;
use Fight\Common\Application\Messaging\Command\CommandHandler;
use Fight\Common\Application\Messaging\Event\EventDispatchFailed;
use Fight\Common\Domain\Exception\LookupException;
use Fight\Common\Domain\Messaging\Command\Command;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\Event;
use Fight\Common\Domain\Messaging\Event\EventMessage;
use Fight\Common\Domain\Messaging\Meta;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\Repository\InMemoryActivationGrantRepository;
use Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\Service\FixedCredentialGenerator;
use Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\Service\PrefixInvitationDeliveryCipher;
use Fight\Test\AccessControl\Application\AccessControl\Audit\Repository\InMemoryAuditEvidenceRepository;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryUserRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(InvitationDeliverySubscriber::class)]
#[CoversClass(PasswordResetDeliverySubscriber::class)]
#[CoversClass(EmailChangeDeliverySubscriber::class)]
#[CoversClass(InvitePendingUserHandler::class)]
final class DeliverySubscriberCompositionTest extends TestCase
{
    /** @return iterable<string, array{Event, Command|null}> */
    public static function routes(): iterable
    {
        $actor = UserId::generate();
        $user = UserId::generate();
        $activation = ActivationDeliveryId::generate();
        $reset = PasswordResetDeliveryId::generate();
        $emailChange = EmailChangeDeliveryId::generate();
        $email = EmailAddress::fromString('delivery@example.test');
        $now = new DateTimeImmutable('2030-01-01T00:00:00Z');
        $invitation = new DeliverUserInvitation('Admin-42', $user, $activation);
        yield 'invited' => [new UserInvited('Admin-42', $user, $activation, $email, $now), $invitation];
        yield 'resent' => [new InvitationDeliveryResent('Admin-42', $user, $activation), $invitation];
        yield 'retry' => [new InvitationDeliveryRetryRequested('Admin-42', $user, $activation), $invitation];
        yield 'registered' => [
            new UserRegistered($user, $activation, $email, $now),
            new DeliverUserInvitation('registration', $user, $activation)
        ];
        yield 'recovered' => [
            new PendingRegistrationRecovered($actor, $user, ActivationDeliveryId::generate(), $activation, $email),
            new DeliverUserInvitation($actor->toString(), $user, $activation)
        ];
        yield 'restored-pending' => [
            new UserRestored($actor, $user, UserState::PENDING_ACTIVATION, $activation),
            new DeliverUserInvitation($actor->toString(), $user, $activation)
        ];
        yield 'restored-active' => [new UserRestored($actor, $user, UserState::ACTIVE), null];
        yield 'restored-without-delivery' => [new UserRestored($actor, $user, UserState::PENDING_ACTIVATION), null];
        yield 'reset' => [
            new PasswordResetRequested($user, $reset, $now),
            new DeliverPasswordReset('anonymous', $user, $reset)
        ];
        yield 'email-change' => [
            new EmailChangeRequested($actor, $user, $emailChange, $email, $now),
            new DeliverEmailChange($actor, $user, $emailChange)
        ];
    }

    #[DataProvider('routes')]
    public function test_real_registration_routes_exact_safe_payloads_and_owns_new_command_metadata(
        Event $event,
        ?Command $expected
    ): void {
        $router = new InMemoryCommandRouter();
        $handler = $this->createMock(CommandHandler::class);
        $message = EventMessage::create($event)->withMeta(Meta::create(['trace' => 'event-only']));
        $original = $message->toArray();
        $handler->expects($expected === null ? self::never() : self::once())->method('handle')->willReturnCallback(
            static function (CommandMessage $commandMessage) use ($expected, $message): void {
                self::assertNotNull($expected);
                self::assertSame($expected::class, $commandMessage->payload()::class);
                self::assertSame($expected->toArray(), $commandMessage->payload()->toArray());
                self::assertSame([], $commandMessage->meta()->toArray());
                self::assertFalse($message->id()->equals($commandMessage->id()));
                self::assertSame('command', $commandMessage->type()->value);
            }
        );
        foreach ([DeliverUserInvitation::class, DeliverPasswordReset::class, DeliverEmailChange::class] as $type) {
            $router->registerHandler($type, $handler);
        }

        $bus = new RoutingCommandBus($router);
        $dispatcher = new SimpleEventDispatcher();
        $dispatcher->register(new InvitationDeliverySubscriber($bus));
        $dispatcher->register(new PasswordResetDeliverySubscriber($bus));
        $dispatcher->register(new EmailChangeDeliverySubscriber($bus));
        $dispatcher->dispatch(EventMessage::arrayDeserialize($message->toArray()));
        self::assertSame($original, $message->toArray());
    }

    public function test_unregistered_events_and_unregistered_subscribers_do_not_schedule_other_families(): void
    {
        $handler = $this->createMock(CommandHandler::class);
        $handler->expects(self::never())->method('handle');
        $router = new InMemoryCommandRouter();
        $router->registerHandler(DeliverUserInvitation::class, $handler);

        $bus = new RoutingCommandBus($router);
        $dispatcher = new SimpleEventDispatcher();
        $subscriber = new InvitationDeliverySubscriber($bus);
        $dispatcher->register($subscriber);
        $dispatcher->trigger(new PasswordResetRequested(
            UserId::generate(),
            PasswordResetDeliveryId::generate(),
            new DateTimeImmutable('2030-01-01T00:00:00Z')
        ));
        $dispatcher->unregister($subscriber);
        $dispatcher->trigger(new InvitationDeliveryRetryRequested(
            'Admin-42',
            UserId::generate(),
            ActivationDeliveryId::generate()
        ));
    }

    public function test_missing_worker_registration_fails_after_original_invitation_commit(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $users = new InMemoryUserRepository($unitOfWork);
        $grants = new InMemoryActivationGrantRepository($unitOfWork);
        $audit = new InMemoryAuditEvidenceRepository($unitOfWork);
        $router = new InMemoryCommandRouter();
        $bus = new RoutingCommandBus($router);
        $dispatcher = new SimpleEventDispatcher();
        $dispatcher->register(new InvitationDeliverySubscriber($bus));

        $handler = new InvitePendingUserHandler(
            $users,
            $grants,
            $audit,
            $unitOfWork,
            new FixedCredentialGenerator('synthetic-invitation'),
            new PrefixInvitationDeliveryCipher(),
            new FixedClock('2030-01-01T00:00:00Z'),
            $dispatcher
        );
        $router->registerHandler($handler::commandRegistration(), $handler);
        $userId = UserId::generate();
        try {
            $bus->execute(new InvitePendingUser('Admin-42', $userId, EmailAddress::fromString('issued@example.test')));
            self::fail('An unconfigured delivery worker cannot appear successful.');
        } catch (EventDispatchFailed $eventDispatchFailed) {
            self::assertCount(1, $eventDispatchFailed->failures());
            self::assertInstanceOf(LookupException::class, $eventDispatchFailed->failures()[0]->throwable());
        }

        self::assertFalse($unitOfWork->transactionActive);
        self::assertSame(1, $unitOfWork->transactions);
        self::assertNotNull($users->getById($userId));
        self::assertCount(1, $audit->all());
        self::assertCount(1, $grants->all());
        self::assertTrue($grants->all()[0]->isIssued());
        self::assertCount(1, $grants->findDue(new DateTimeImmutable('2030-01-01T00:00:00Z'), 10));
    }

    public function test_command_fault_is_retained_in_actual_dispatch_failure_without_automatic_replay(): void
    {
        $primary = new RuntimeException('Worker unavailable.');
        $handler = $this->createMock(CommandHandler::class);
        $handler->expects(self::once())->method('handle')->willThrowException($primary);
        $router = new InMemoryCommandRouter();
        $router->registerHandler(DeliverUserInvitation::class, $handler);

        $dispatcher = new SimpleEventDispatcher();
        $dispatcher->register(new InvitationDeliverySubscriber(new RoutingCommandBus($router)));
        try {
            $dispatcher->trigger(new InvitationDeliveryRetryRequested(
                'Admin-42',
                UserId::generate(),
                ActivationDeliveryId::generate()
            ));
            self::fail('The synchronous dispatcher must report the worker failure.');
        } catch (EventDispatchFailed $eventDispatchFailed) {
            self::assertCount(1, $eventDispatchFailed->failures());
            self::assertSame($primary, $eventDispatchFailed->failures()[0]->throwable());
        }
    }
}
