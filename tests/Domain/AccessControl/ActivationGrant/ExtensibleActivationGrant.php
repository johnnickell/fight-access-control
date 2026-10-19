<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\ActivationGrant;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationDelivery;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrant;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrantId;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationOrigin;
use Fight\AccessControl\Domain\AccessControl\User\UserId;

final class ExtensibleActivationGrant extends ActivationGrant
{
    public static function reconstitute(
        ActivationOrigin $origin,
        ActivationGrantId $id,
        UserId $userId,
        string $credentialHash,
        DateTimeImmutable $expiresAt,
        ActivationDelivery $delivery,
        ?DateTimeImmutable $consumedAt = null,
        ?DateTimeImmutable $revokedAt = null,
        int $revision = 0
    ): self {
        return new self(
            $origin,
            $id,
            $userId,
            $credentialHash,
            $expiresAt,
            $delivery,
            $consumedAt,
            $revokedAt,
            $revision
        );
    }
}
