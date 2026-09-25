<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\CredentialDelivery\Service;

use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\EncryptedCredentialMaterial;

/**
 * Interface CredentialDeliveryCipher
 *
 * Protects recoverable credential material with consumer-owned keys.
 */
interface CredentialDeliveryCipher
{
    /**
     * Encrypts raw credential material before its originating transaction commits
     */
    public function encrypt(string $plaintext): string;

    /**
     * Decrypts material only for one committed live delivery claim
     */
    public function decrypt(EncryptedCredentialMaterial $encryptedMaterial): string;
}
