<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\Service;

/**
 * Opens one consumer-managed HMAC shared secret envelope for verification.
 */
interface HmacSharedSecretDecipher
{
    /**
     * Decrypts one consumer-managed envelope for HMAC verification.
     */
    public function decrypt(string $encryptedHmacSharedSecretEnvelope): string;
}
