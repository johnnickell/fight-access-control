<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\ActivationGrant;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Exception\ActivationDeliveryException;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDelivery;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\EncryptedCredentialMaterial;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use InvalidArgumentException;

/**
 * Class ActivationDelivery
 *
 * Represents recoverable encrypted delivery work owned by an activation grant.
 *
 * @phpstan-consistent-constructor
 */
class ActivationDelivery extends CredentialDelivery
{
    /**
     * Creates pending encrypted activation delivery work
     */
    public static function create(
        ActivationDeliveryId $id,
        UserId $userId,
        EmailAddress $email,
        string $ciphertext,
        DateTimeImmutable $expiresAt,
        ?DateTimeImmutable $dueAt = null
    ): static {
        try {
            $encryptedMaterial = EncryptedCredentialMaterial::fromString($ciphertext);
        } catch (InvalidArgumentException $invalidArgumentException) {
            throw new ActivationDeliveryException(
                'The activation delivery ciphertext must not be empty.',
                previous: $invalidArgumentException
            );
        }

        return new static($id, $userId, $email, $encryptedMaterial, $expiresAt, $dueAt ?? new DateTimeImmutable('@0'));
    }

    /**
     * Returns the delivery-generation identifier
     */
    public function getId(): ActivationDeliveryId
    {
        $id = parent::getId();
        assert($id instanceof ActivationDeliveryId);

        return $id;
    }
}
