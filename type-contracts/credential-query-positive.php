<?php

declare(strict_types=1);

use Fight\AccessControl\Application\AccessControl\ActivationGrant\QueryHandler\FindInvitationDeliveryStatusHandler;
use Fight\AccessControl\Application\AccessControl\CredentialDelivery\QueryHandler\FindCredentialDeliveryStatusHandler;
use Fight\AccessControl\Application\AccessControl\CredentialDelivery\QueryHandler\FindDueCredentialDeliveriesHandler;
use Fight\AccessControl\Application\AccessControl\CredentialDelivery\QueryHandler\{
    FindExpiredCredentialDeliveriesHandler
};
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrantRepository;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\Query\CredentialDeliveryAbsent;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Messaging\Query\QueryMessage;

(static function (
    FindInvitationDeliveryStatusHandler $invitation,
    FindCredentialDeliveryStatusHandler $status,
    FindDueCredentialDeliveriesHandler $due,
    FindExpiredCredentialDeliveriesHandler $expired,
    QueryMessage $message,
    ActivationGrantRepository $repository,
    UserId $userId
): void {
    \PHPStan\dumpType($invitation->handle($message));
    \PHPStan\dumpType($status->handle($message));
    \PHPStan\dumpType($due->handle($message));
    \PHPStan\dumpType($expired->handle($message));
    \PHPStan\dumpType($repository->getLatestByUserId($userId));
    $result = $invitation->handle($message);
    if ($result instanceof CredentialDeliveryAbsent) {
        \PHPStan\dumpType($result->toArray());
    } else {
        \PHPStan\dumpType($result->getOrigin());
        \PHPStan\dumpType($result->toArray());
    }
});
