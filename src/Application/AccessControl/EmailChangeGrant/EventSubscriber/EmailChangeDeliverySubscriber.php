<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\EmailChangeGrant\EventSubscriber;

use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Command\DeliverEmailChange;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Event\EmailChangeRequested;
use Fight\Common\Application\Messaging\Command\CommandBus;
use Fight\Common\Application\Messaging\Event\EventSubscriber;
use Fight\Common\Domain\Messaging\Event\EventMessage;

/**
 * Routes email-change request events to invocation-neutral delivery work.
 */
final readonly class EmailChangeDeliverySubscriber implements EventSubscriber
{
    /**
     * Creates the email-change delivery subscriber.
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
            EmailChangeRequested::class => 'onEmailChangeRequested',
        ];
    }

    /**
     * Dispatches exact delivery identity after a durable email-change request.
     */
    public function onEmailChangeRequested(EventMessage $eventMessage): void
    {
        /** @var EmailChangeRequested $event */
        $event = $eventMessage->payload();
        $this->commandBus->execute(new DeliverEmailChange(
            $event->getActorId(),
            $event->getUserId(),
            $event->getEmailChangeDeliveryId()
        ));
    }
}
