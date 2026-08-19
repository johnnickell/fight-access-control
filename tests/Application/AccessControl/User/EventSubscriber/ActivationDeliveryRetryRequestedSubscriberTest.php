<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User\EventSubscriber;

use Fight\AccessControl\Application\AccessControl\User\EventSubscriber\ActivationDeliveryRetryRequestedSubscriber;
use Fight\AccessControl\Domain\AccessControl\User\Command\DeliverUserInvitation;
use Fight\AccessControl\Domain\AccessControl\User\Event\ActivationDeliveryRetryRequested;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Messaging\Event\EventMessage;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryCommandBus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ActivationDeliveryRetryRequestedSubscriber::class)]
final class ActivationDeliveryRetryRequestedSubscriberTest extends TestCase
{
    public function test_that_it_dispatches_invitation_delivery_for_a_retry_request(): void
    {
        $commandBus = new InMemoryCommandBus();
        $subscriber = new ActivationDeliveryRetryRequestedSubscriber($commandBus);
        $userId = UserId::generate();

        $subscriber->onActivationDeliveryRetryRequested(EventMessage::create(
            new ActivationDeliveryRetryRequested('Admin-42', $userId)
        ));

        self::assertSame(
            [ActivationDeliveryRetryRequested::class => 'onActivationDeliveryRetryRequested'],
            ActivationDeliveryRetryRequestedSubscriber::eventRegistration()
        );
        self::assertCount(1, $commandBus->executedCommands());
        self::assertInstanceOf(DeliverUserInvitation::class, $commandBus->executedCommands()[0]);
        self::assertSame('Admin-42', $commandBus->executedCommands()[0]->getActorId());
        self::assertSame($userId, $commandBus->executedCommands()[0]->getUserId());
    }
}
