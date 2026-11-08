<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\Authentication;

use SensitiveParameter;

/**
 * Interface HmacSharedSecretDecipher
 *
 * Opens one consumer-managed HMAC shared secret envelope for verification.
 */
interface HmacSharedSecretDecipher
{
    /**
     * Decrypts one consumer-managed envelope for HMAC verification
     */
    public function decrypt(#[SensitiveParameter] string $encryptedHmacSharedSecretEnvelope): string;
}
