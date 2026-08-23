<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\ActivationGrant\EventSubscriber;

use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Command\DeliverUserInvitation;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Event\InvitationDeliveryResent;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Event\InvitationDeliveryRetryRequested;
use Fight\AccessControl\Domain\AccessControl\User\Event\UserInvited;
use Fight\Common\Application\Messaging\Command\CommandBus;
use Fight\Common\Application\Messaging\Event\EventSubscriber;
use Fight\Common\Domain\Messaging\Event\EventMessage;

/**
 * Routes invitation events to invocation-neutral invitation delivery.
 */
final readonly class InvitationDeliverySubscriber implements EventSubscriber
{
    /**
     * Creates the invitation-delivery subscriber.
     */
    public function __construct(private CommandBus $commandBus)
    {
    }

    /**
     * @inheritDoc
     */
    public static function eventRegistration(): array
    {
        return [
            InvitationDeliveryResent::class => 'onInvitationDeliveryResent',
            InvitationDeliveryRetryRequested::class => 'onInvitationDeliveryRetryRequested',
            UserInvited::class => 'onUserInvited',
        ];
    }

    /**
     * Dispatches delivery after a replacement invitation is published.
     */
    public function onInvitationDeliveryResent(EventMessage $eventMessage): void
    {
        /** @var InvitationDeliveryResent $event */
        $event = $eventMessage->payload();
        $this->commandBus->execute(new DeliverUserInvitation(
            $event->getActorId(),
            $event->getUserId(),
            $event->getActivationDeliveryId()
        ));
    }

    /**
     * Dispatches delivery after the retry request is published.
     */
    public function onInvitationDeliveryRetryRequested(EventMessage $eventMessage): void
    {
        /** @var InvitationDeliveryRetryRequested $event */
        $event = $eventMessage->payload();
        $this->commandBus->execute(new DeliverUserInvitation(
            $event->getActorId(),
            $event->getUserId(),
            $event->getActivationDeliveryId()
        ));
    }

    /**
     * Dispatches initial invitation delivery after durable invitation creation.
     */
    public function onUserInvited(EventMessage $eventMessage): void
    {
        /** @var UserInvited $event */
        $event = $eventMessage->payload();
        $this->commandBus->execute(new DeliverUserInvitation(
            $event->getActorId(),
            $event->getUserId(),
            $event->getActivationDeliveryId()
        ));
    }
}
