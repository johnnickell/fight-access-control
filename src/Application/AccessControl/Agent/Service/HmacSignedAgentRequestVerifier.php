<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\Service;

use Fight\AccessControl\Application\AccessControl\Agent\Security\SignedAgentRequest;
use SensitiveParameter;

/**
 * Verifies one portable Agent request using Fight Common v1 HMAC semantics.
 */
interface HmacSignedAgentRequestVerifier
{
    /**
     * Returns whether the request body digest and canonical signature are valid.
     */
    public function verifies(
        SignedAgentRequest $signedAgentRequest,
        #[SensitiveParameter] string $hmacSharedSecret
    ): bool;
}
