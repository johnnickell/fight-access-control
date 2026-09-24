<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\PasswordResetGrant;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryStatus;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\EncryptedCredentialMaterial;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetDelivery;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetDeliveryId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Value\Internet\EmailAddress;

final class ExtensiblePasswordResetDelivery extends PasswordResetDelivery
{
    public static function reconstitute(
        PasswordResetDeliveryId $id,
        UserId $userId,
        EmailAddress $email,
        ?string $ciphertext,
        DateTimeImmutable $expiresAt
    ): self {
        $material = null;
        $status = CredentialDeliveryStatus::INVALIDATED;
        if ($ciphertext !== null && $ciphertext !== '') {
            $material = EncryptedCredentialMaterial::fromString($ciphertext);
            $status = CredentialDeliveryStatus::PENDING;
        } elseif ($ciphertext === '') {
            $status = CredentialDeliveryStatus::PENDING;
        }

        return new self(
            $id,
            $userId,
            $email,
            $material,
            $expiresAt,
            new DateTimeImmutable('@0'),
            $status
        );
    }
}
