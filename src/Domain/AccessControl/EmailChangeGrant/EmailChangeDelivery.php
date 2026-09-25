<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\EmailChangeGrant;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDelivery;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\EncryptedCredentialMaterial;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Exception\EmailChangeGrantException;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use InvalidArgumentException;

/**
 * Class EmailChangeDelivery
 *
 * Represents recoverable encrypted delivery work owned by an email-change grant.
 *
 * @phpstan-consistent-constructor
 */
class EmailChangeDelivery extends CredentialDelivery
{
    /**
     * Creates pending encrypted email-change delivery work
     */
    public static function create(
        EmailChangeDeliveryId $id,
        UserId $userId,
        EmailAddress $email,
        string $ciphertext,
        DateTimeImmutable $expiresAt,
        ?DateTimeImmutable $dueAt = null
    ): static {
        try {
            $encryptedMaterial = EncryptedCredentialMaterial::fromString($ciphertext);
        } catch (InvalidArgumentException $invalidArgumentException) {
            throw new EmailChangeGrantException(
                'The email-change delivery ciphertext must not be empty.',
                previous: $invalidArgumentException
            );
        }

        return new static($id, $userId, $email, $encryptedMaterial, $expiresAt, $dueAt ?? new DateTimeImmutable('@0'));
    }

    /**
     * Returns the delivery-generation identifier
     */
    public function getId(): EmailChangeDeliveryId
    {
        $id = parent::getId();
        assert($id instanceof EmailChangeDeliveryId);

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
