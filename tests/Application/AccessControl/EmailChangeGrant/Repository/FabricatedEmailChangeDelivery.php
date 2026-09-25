<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\EmailChangeGrant\Repository;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryClaimToken;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryFailure;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryStatus;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeDelivery;

final class FabricatedEmailChangeDelivery extends EmailChangeDelivery
{
    public static function malformedPending(
        EmailChangeDelivery $delivery,
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
}
