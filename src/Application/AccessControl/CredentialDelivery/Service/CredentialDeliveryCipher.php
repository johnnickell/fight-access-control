<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\CredentialDelivery\Service;

use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\EncryptedCredentialMaterial;
use SensitiveParameter;

/**
 * Interface CredentialDeliveryCipher
 *
 * Protects recoverable credential material with consumer-owned keys.
 * Implementations also mark sensitive parameters; PHP does not inherit parameter attributes from interfaces.
 */
interface CredentialDeliveryCipher
{
    /**
     * Encrypts raw credential material before its originating transaction commits
     */
    public function encrypt(#[SensitiveParameter] string $plaintext): string;

    /**
     * Decrypts material only for one committed live delivery claim
     */
    public function decrypt(EncryptedCredentialMaterial $encryptedMaterial): string;
}
