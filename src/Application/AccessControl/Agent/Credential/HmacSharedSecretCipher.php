<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\Credential;

use SensitiveParameter;

/**
 * Interface HmacSharedSecretCipher
 *
 * Encrypts a raw HMAC shared secret for consumer-owned durable storage.
 */
interface HmacSharedSecretCipher
{
    /**
     * Encrypts one raw HMAC shared secret into a consumer-managed envelope
     *
     * Preserve the supplied bytes without generating or delivering a replacement. The authentication envelope
     * is independent of the operation-bound delivery copy. Failure aborts issuance; arbitrary provider faults
     * are internal failures. Never return provider diagnostics in place of an envelope.
     */
    public function encrypt(#[SensitiveParameter] string $hmacSharedSecret): string;
}
