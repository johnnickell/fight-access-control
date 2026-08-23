<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\PasswordResetGrant;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetDelivery;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrant;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrantId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;

final class ExtensiblePasswordResetGrant extends PasswordResetGrant
{
    public static function reconstitute(
        PasswordResetGrantId $id,
        UserId $userId,
        string $credentialHash,
        DateTimeImmutable $expiresAt,
        PasswordResetDelivery $delivery,
        ?DateTimeImmutable $consumedAt = null,
        ?DateTimeImmutable $revokedAt = null,
        int $revision = 0
    ): self {
        return new self(
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
