<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\Service;

use SensitiveParameter;

/**
 * Encrypts a raw HMAC shared secret for consumer-owned durable storage.
 */
interface HmacSharedSecretCipher
{
    /**
     * Encrypts one raw HMAC shared secret into a consumer-managed envelope.
     */
    public function encrypt(#[SensitiveParameter] string $hmacSharedSecret): string;
}
