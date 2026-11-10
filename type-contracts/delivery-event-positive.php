<?php

declare(strict_types=1);

use Fight\AccessControl\Application\AccessControl\ActivationGrant\EventSubscriber\InvitationDeliverySubscriber;
use Fight\AccessControl\Application\AccessControl\EmailChangeGrant\EventSubscriber\EmailChangeDeliverySubscriber;
use Fight\AccessControl\Application\AccessControl\PasswordResetGrant\EventSubscriber\PasswordResetDeliverySubscriber;
use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Event\InvitationDeliveryResent;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Event\InvitationDeliveryRetryRequested;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Event\EmailChangeRequested;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\Event\PasswordResetRequested;
use Fight\AccessControl\Domain\AccessControl\User\Event\PendingRegistrationRecovered;
use Fight\AccessControl\Domain\AccessControl\User\Event\UserInvited;
use Fight\AccessControl\Domain\AccessControl\User\Event\UserRegistered;
use Fight\AccessControl\Domain\AccessControl\User\Event\UserRestored;
use Fight\Common\Domain\Messaging\Event\EventMessage;

return static function (
    InvitationDeliverySubscriber $invitations,
    PasswordResetDeliverySubscriber $resets,
    EmailChangeDeliverySubscriber $changes,
    EventMessage $message,
    Clock $clock
): void {
    PHPStan\dumpType($message->payload());
    PHPStan\dumpType($invitations->onUserInvited(...));
    PHPStan\dumpType($resets->onPasswordResetRequested(...));
    PHPStan\dumpType($changes->onEmailChangeRequested(...));
    PHPStan\dumpType($clock->now());
    PHPStan\dumpType(InvitationDeliverySubscriber::eventRegistration());
    /** @var InvitationDeliveryResent $resent */
    $resent = $message->payload();
    PHPStan\dumpType($resent->getActivationDeliveryId());
    /** @var InvitationDeliveryRetryRequested $retry */
    $retry = $message->payload();
    PHPStan\dumpType($retry->getActorId());
    /** @var PendingRegistrationRecovered $recovered */
    $recovered = $message->payload();
    PHPStan\dumpType($recovered->getActorId());
    /** @var UserInvited $invited */
    $invited = $message->payload();
    PHPStan\dumpType($invited->getUserId());
    /** @var UserRegistered $registered */
    $registered = $message->payload();
    PHPStan\dumpType($registered->getActivationDeliveryId());
    /** @var UserRestored $restored */
    $restored = $message->payload();
    PHPStan\dumpType($restored->getActivationDeliveryId());
    /** @var PasswordResetRequested $reset */
    $reset = $message->payload();
    PHPStan\dumpType($reset->getPasswordResetDeliveryId());
    /** @var EmailChangeRequested $change */
    $change = $message->payload();
    PHPStan\dumpType($change->getEmailChangeDeliveryId());
};
