<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\PasswordResetGrant\EventSubscriber;

use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\Command\DeliverPasswordReset;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\Event\PasswordResetRequested;
use Fight\Common\Application\Messaging\Command\CommandBus;
use Fight\Common\Application\Messaging\Event\EventSubscriber;
use Fight\Common\Domain\Messaging\Event\EventMessage;

/**
 * Class PasswordResetDeliverySubscriber
 *
 * Routes new password-reset work to its direct package handler.
 */
final readonly class PasswordResetDeliverySubscriber implements EventSubscriber
{
    /**
     * Constructs PasswordResetDeliverySubscriber
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
            PasswordResetRequested::class => 'onPasswordResetRequested'
        ];
    }

    /**
     * Dispatches one exact durable delivery generation after request commit
     */
    public function onPasswordResetRequested(EventMessage $eventMessage): void
    {
        /** @var PasswordResetRequested $event */
        $event = $eventMessage->payload();
        $this->commandBus->execute(new DeliverPasswordReset(
            'anonymous',
            $event->getUserId(),
            $event->getPasswordResetDeliveryId()
        ));
    }
}
