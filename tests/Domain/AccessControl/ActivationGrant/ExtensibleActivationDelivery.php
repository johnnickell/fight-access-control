<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\ActivationGrant;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationDelivery;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationDeliveryId;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryClaimToken;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryFailure;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryStatus;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Value\Internet\EmailAddress;

final class ExtensibleActivationDelivery extends ActivationDelivery
{
    public static function malformedPending(
        ActivationDelivery $delivery,
        DateTimeImmutable $dueAt,
        bool $withMetadata
    ): self {
        $claimToken = $withMetadata ? CredentialDeliveryClaimToken::generate() : null;
        $claimedAt = $withMetadata ? $delivery->getDueAt() : null;
        $leaseUntil = $withMetadata ? $delivery->getDueAt()->modify('+5 minutes') : null;
        $attemptCount = $withMetadata ? 5 : 0;
        $lastAttemptAt = $withMetadata ? $delivery->getDueAt() : null;
        $lastOutcomeAt = $withMetadata ? $delivery->getDueAt()->modify('+1 minute') : null;
        $lastFailure = $withMetadata ? CredentialDeliveryFailure::RETRYABLE_PROVIDER : null;

        return new self(
            $delivery->getId(),
            $delivery->getUserId(),
            $delivery->getEmail(),
            $delivery->getEncryptedMaterial(),
            $delivery->getExpiresAt(),
            $dueAt,
            CredentialDeliveryStatus::PENDING,
            $claimToken,
            $claimedAt,
            $leaseUntil,
            $attemptCount,
            $lastAttemptAt,
            $lastOutcomeAt,
            $lastFailure
        );
    }

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
