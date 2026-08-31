<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\EventSubscriber;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\ActivationGrant\EventSubscriber\InvitationDeliverySubscriber;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationDeliveryId;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Command\DeliverUserInvitation;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Event\InvitationDeliveryResent;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Event\InvitationDeliveryRetryRequested;
use Fight\AccessControl\Domain\AccessControl\User\Event\UserInvited;
use Fight\AccessControl\Domain\AccessControl\User\Event\UserRestored;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Domain\Messaging\Event\EventMessage;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryCommandBus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(InvitationDeliverySubscriber::class)]
final class InvitationDeliverySubscriberTest extends TestCase
{
    public function test_that_it_dispatches_invitation_delivery_for_a_retry_request(): void
    {
        $commandBus = new InMemoryCommandBus();
        $subscriber = new InvitationDeliverySubscriber($commandBus);
        $userId = UserId::generate();

        $subscriber->onInvitationDeliveryRetryRequested(EventMessage::create(
            new InvitationDeliveryRetryRequested('Admin-42', $userId, ActivationDeliveryId::generate())
        ));

        self::assertSame(
            [
                InvitationDeliveryResent::class => 'onInvitationDeliveryResent',
                InvitationDeliveryRetryRequested::class => 'onInvitationDeliveryRetryRequested',
                UserInvited::class => 'onUserInvited',
                UserRestored::class => 'onUserRestored',
            ],
            InvitationDeliverySubscriber::eventRegistration()
        );
        self::assertCount(1, $commandBus->executedCommands());
        self::assertInstanceOf(DeliverUserInvitation::class, $commandBus->executedCommands()[0]);
        self::assertSame('Admin-42', $commandBus->executedCommands()[0]->getActorId());
        self::assertSame($userId, $commandBus->executedCommands()[0]->getUserId());
    }

    public function test_that_it_dispatches_invitation_delivery_for_a_resend(): void
    {
        $commandBus = new InMemoryCommandBus();
        $subscriber = new InvitationDeliverySubscriber($commandBus);
        $userId = UserId::generate();

        $subscriber->onInvitationDeliveryResent(EventMessage::create(
            new InvitationDeliveryResent('Admin-42', $userId, ActivationDeliveryId::generate())
        ));

        self::assertCount(1, $commandBus->executedCommands());
        self::assertInstanceOf(DeliverUserInvitation::class, $commandBus->executedCommands()[0]);
        self::assertSame('Admin-42', $commandBus->executedCommands()[0]->getActorId());
        self::assertSame($userId, $commandBus->executedCommands()[0]->getUserId());
    }

    public function test_that_it_dispatches_initial_delivery_for_a_new_invitation(): void
    {
        $commandBus = new InMemoryCommandBus();
        $subscriber = new InvitationDeliverySubscriber($commandBus);
        $userId = UserId::generate();

        $subscriber->onUserInvited(EventMessage::create(new UserInvited(
            'Admin-42',
            $userId,
            ActivationDeliveryId::generate(),
            EmailAddress::fromString('alice@example.test'),
            new DateTimeImmutable('2026-08-19T12:00:00+00:00')
        )));

        self::assertCount(1, $commandBus->executedCommands());
        self::assertInstanceOf(DeliverUserInvitation::class, $commandBus->executedCommands()[0]);
        self::assertSame('Admin-42', $commandBus->executedCommands()[0]->getActorId());
        self::assertSame($userId, $commandBus->executedCommands()[0]->getUserId());
    }

    public function test_that_it_dispatches_delivery_for_a_pending_activation_restoration(): void
    {
        $commandBus = new InMemoryCommandBus();
        $subscriber = new InvitationDeliverySubscriber($commandBus);
        $actorId = UserId::generate();
        $userId = UserId::generate();
        $activationDeliveryId = ActivationDeliveryId::generate();

        $subscriber->onUserRestored(EventMessage::create(new UserRestored(
            $actorId,
            $userId,
            UserState::PENDING_ACTIVATION,
            $activationDeliveryId
        )));

        self::assertCount(1, $commandBus->executedCommands());
        self::assertInstanceOf(DeliverUserInvitation::class, $commandBus->executedCommands()[0]);
        self::assertSame($actorId->toString(), $commandBus->executedCommands()[0]->getActorId());
        self::assertSame($userId, $commandBus->executedCommands()[0]->getUserId());
        self::assertSame(
            $activationDeliveryId,
            $commandBus->executedCommands()[0]->getActivationDeliveryId()
        );
    }

    public function test_that_it_does_not_dispatch_delivery_for_an_active_restoration(): void
    {
        $commandBus = new InMemoryCommandBus();
        $subscriber = new InvitationDeliverySubscriber($commandBus);
        $actorId = UserId::generate();
        $userId = UserId::generate();

        $subscriber->onUserRestored(EventMessage::create(new UserRestored(
            $actorId,
            $userId,
            UserState::ACTIVE
        )));

        self::assertSame([], $commandBus->executedCommands());
    }
}
