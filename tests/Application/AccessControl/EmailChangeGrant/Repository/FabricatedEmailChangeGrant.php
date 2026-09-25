<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\EmailChangeGrant\Repository;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeDelivery;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeDeliveryId;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeGrant;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeGrantId;

final class FabricatedEmailChangeGrant extends EmailChangeGrant
{
    public static function withDeliveryState(
        EmailChangeGrant $grant,
        DateTimeImmutable $dueAt,
        bool $withMetadata
    ): self {
        return new self(
            $grant->getId(),
            $grant->getUserId(),
            $grant->getCredentialHash(),
            $grant->getExpiresAt(),
            FabricatedEmailChangeDelivery::malformedPending($grant->getDelivery(), $dueAt, $withMetadata)
        );
    }

    public static function fromGrant(EmailChangeGrant $grant): self
    {
        return new self(
            $grant->getId(),
            $grant->getUserId(),
            str_repeat('f', 64),
            $grant->getExpiresAt(),
            $grant->getDelivery(),
            $grant->getConsumedAt(),
            $grant->getRevokedAt(),
            $grant->getExpiredAt(),
            $grant->getRevision()
        );
    }

    public static function withDeliveryExpiry(EmailChangeGrant $grant, DateTimeImmutable $expiresAt): self
    {
        $delivery = $grant->getDelivery();
        $material = $delivery->getEncryptedMaterial();
        assert($material !== null);

        return new self(
            $grant->getId(),
            $grant->getUserId(),
            $grant->getCredentialHash(),
            $grant->getExpiresAt(),
            EmailChangeDelivery::create(
                $delivery->getId(),
                $grant->getUserId(),
                $delivery->getEmail(),
                $material->reveal(),
                $expiresAt,
                $delivery->getDueAt()
            )
        );
    }

    public static function withIdentifiers(
        EmailChangeGrant $grant,
        EmailChangeGrantId $grantId,
        EmailChangeDeliveryId $deliveryId
    ): self {
        $delivery = $grant->getDelivery();
        $material = $delivery->getEncryptedMaterial();
        assert($material !== null);

        return new self(
            $grantId,
            $grant->getUserId(),
            $grant->getCredentialHash(),
            $grant->getExpiresAt(),
            EmailChangeDelivery::create(
                $deliveryId,
                $grant->getUserId(),
                $delivery->getEmail(),
                $material->reveal(),
                $delivery->getExpiresAt(),
                $delivery->getDueAt()
            )
        );
    }
}
