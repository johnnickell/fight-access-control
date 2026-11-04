<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\Authentication;

use SensitiveParameter;

/**
 * Interface HmacSignedAgentRequestVerifier
 *
 * Verifies one portable Agent request using Fight Common v1 HMAC semantics.
 */
interface HmacSignedAgentRequestVerifier
{
    /**
     * Returns whether the request body digest and canonical signature are valid
     */
    public function verifies(
        SignedAgentRequest $signedAgentRequest,
        #[SensitiveParameter] string $hmacSharedSecret
    ): bool;
}
