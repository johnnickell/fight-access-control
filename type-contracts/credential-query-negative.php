<?php

declare(strict_types=1);

use Fight\AccessControl\Application\AccessControl\ActivationGrant\QueryHandler\FindInvitationDeliveryStatusHandler;
use Fight\AccessControl\Application\AccessControl\CredentialDelivery\QueryHandler\FindCredentialDeliveryStatusHandler;
use Fight\AccessControl\Application\AccessControl\CredentialDelivery\QueryHandler\FindDueCredentialDeliveriesHandler;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Query\InvitationDeliveryStatusView;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\ExpiredCredentialDelivery;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\Query\CredentialDeliveryStatusView;
use Fight\Common\Domain\Messaging\Query\QueryMessage;

(static function (
    FindInvitationDeliveryStatusHandler $invitation,
    FindCredentialDeliveryStatusHandler $status,
    FindDueCredentialDeliveriesHandler $due,
    QueryMessage $message
): void {
    (static fn (InvitationDeliveryStatusView $view): string => $view->getStatus()->name)($invitation->handle($message));
    (static fn (CredentialDeliveryStatusView $view): int => $view->getRevision())($status->handle($message));
    array_map(static fn (ExpiredCredentialDelivery $work): string => $work->getPurpose(), $due->handle($message));
});
