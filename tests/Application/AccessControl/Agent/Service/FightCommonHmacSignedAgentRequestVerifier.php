<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Service;

use Closure;
use Fight\AccessControl\Application\AccessControl\Agent\Security\SignedAgentRequest;
use Fight\AccessControl\Application\AccessControl\Agent\Service\HmacSignedAgentRequestVerifier;
use Fight\Common\Adapter\Auth\Hmac\HmacAuthenticator;
use Fight\Common\Application\Auth\Exception\AuthException;
use SensitiveParameter;

/**
 * Binds portable inputs to Fight Common's public HMAC authenticator in tests.
 */
final readonly class FightCommonHmacSignedAgentRequestVerifier implements HmacSignedAgentRequestVerifier
{
    /**
     * Creates the test-only bridge to the public Fight Common HMAC behavior.
     */
    public function __construct(private Closure $serverRequestFactory)
    {
    }

    /**
     * Verifies through Fight Common without reproducing its HMAC implementation.
     */
    public function verifies(
        SignedAgentRequest $signedAgentRequest,
        #[SensitiveParameter] string $hmacSharedSecret
    ): bool {
        $authenticator = new HmacAuthenticator(
            $signedAgentRequest->getCredentialId()->toString(),
            $hmacSharedSecret,
            300
        );

        try {
            return $authenticator->validate(($this->serverRequestFactory)($signedAgentRequest));
        } catch (AuthException) {
            return false;
        }
    }
}
