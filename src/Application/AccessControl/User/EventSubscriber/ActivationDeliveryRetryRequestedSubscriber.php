<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\User\EventSubscriber;

use Fight\AccessControl\Domain\AccessControl\User\Command\DeliverUserInvitation;
use Fight\AccessControl\Domain\AccessControl\User\Event\ActivationDeliveryRetryRequested;
use Fight\Common\Application\Messaging\Command\CommandBus;
use Fight\Common\Application\Messaging\Event\EventSubscriber;
use Fight\Common\Domain\Messaging\Event\EventMessage;

/**
 * Routes a durable retry request to invocation-neutral invitation delivery.
 */
final readonly class ActivationDeliveryRetryRequestedSubscriber implements EventSubscriber
{
    /**
     * Creates the retry-request subscriber.
     */
    public function __construct(private CommandBus $commandBus)
    {
    }

    /**
     * @inheritDoc
     */
    public static function eventRegistration(): array
    {
        return [ActivationDeliveryRetryRequested::class => 'onActivationDeliveryRetryRequested'];
    }

    /**
     * Dispatches delivery after the retry request is published.
     */
    public function onActivationDeliveryRetryRequested(EventMessage $eventMessage): void
    {
        /** @var ActivationDeliveryRetryRequested $event */
        $event = $eventMessage->payload();
        $this->commandBus->execute(new DeliverUserInvitation($event->getActorId(), $event->getUserId()));
    }
}
