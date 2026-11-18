<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\Credential;

/**
 * Interface HmacSharedSecretGenerator
 *
 * Generates original raw HMAC bytes for a newly admitted provision or rotation, never retained-key resolution.
 */
interface HmacSharedSecretGenerator
{
    /**
     * Generates an unpredictable raw HMAC shared secret
     *
     * The caller passes these exact bytes to both independent ciphers inside its transaction. Implementations
     * retain no operation result and perform no delivery or publication. Failure aborts issuance; arbitrary
     * provider/programmer faults are internal failures, not a business rejection or proof of rollback.
     */
    public function generate(): string;
}
