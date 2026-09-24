<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\PasswordResetGrant;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDelivery;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\EncryptedCredentialMaterial;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\Exception\PasswordResetDeliveryException;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use InvalidArgumentException;

/**
 * Class PasswordResetDelivery
 *
 * Represents recoverable encrypted delivery work owned by a password-reset grant.
 *
 * @phpstan-consistent-constructor
 */
class PasswordResetDelivery extends CredentialDelivery
{
    /**
     * Creates pending encrypted password-reset delivery work
     */
    public static function create(
        PasswordResetDeliveryId $id,
        UserId $userId,
        EmailAddress $email,
        string $ciphertext,
        DateTimeImmutable $expiresAt,
        ?DateTimeImmutable $dueAt = null
    ): static {
        try {
            $encryptedMaterial = EncryptedCredentialMaterial::fromString($ciphertext);
        } catch (InvalidArgumentException $invalidArgumentException) {
            throw new PasswordResetDeliveryException(
                'The password-reset delivery ciphertext must not be empty.',
                previous: $invalidArgumentException
            );
        }

        return new static($id, $userId, $email, $encryptedMaterial, $expiresAt, $dueAt ?? new DateTimeImmutable('@0'));
    }

    /**
     * Returns the delivery-generation identifier
     */
    public function getId(): PasswordResetDeliveryId
    {
        $id = parent::getId();
        assert($id instanceof PasswordResetDeliveryId);

        return $id;
    }

    /**
     * Returns whether encrypted credential material remains recoverable
     */
    public function isRecoverable(): bool
    {
        return $this->hasRecoverableMaterial();
    }
}
