<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\ActivationGrant;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationDelivery;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationDeliveryId;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryClaimToken;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryStatus;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Value\Internet\EmailAddress;

final class ExtensibleActivationDelivery extends ActivationDelivery
{
    public static function claimedWithoutMaterial(
        CredentialDeliveryClaimToken $claimToken,
        DateTimeImmutable $claimedAt,
        DateTimeImmutable $leaseUntil
    ): self {
        return new self(
            ActivationDeliveryId::generate(),
            UserId::generate(),
            EmailAddress::fromString('malformed@example.test'),
            null,
            $leaseUntil->modify('+1 hour'),
            $claimedAt,
            CredentialDeliveryStatus::CLAIMED,
            $claimToken,
            $claimedAt,
            $leaseUntil,
            1,
            $claimedAt
        );
    }
}
