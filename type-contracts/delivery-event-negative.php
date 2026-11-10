<?php

declare(strict_types=1);

use Fight\AccessControl\Application\AccessControl\ActivationGrant\EventSubscriber\InvitationDeliverySubscriber;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationDeliveryId;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeDeliveryId;
use Fight\AccessControl\Domain\AccessControl\User\Event\UserRestored;
use Fight\Common\Domain\Messaging\Command\Command;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\EventMessage;

return static function (
    InvitationDeliverySubscriber $subscriber,
    Command $command,
    CommandMessage $commandMessage,
    EventMessage $eventMessage,
    UserRestored $restored,
    EmailChangeDeliveryId $wrongGeneration
): void {
    EventMessage::create($command);
    $subscriber->onUserInvited($commandMessage);
    $eventMessage->payload()->getActivationDeliveryId();
    $acceptGeneration = static function (ActivationDeliveryId $delivery): void {
        print $delivery->toString();
    };
    $acceptGeneration($restored->getActivationDeliveryId());
    $acceptGeneration($wrongGeneration);
};
