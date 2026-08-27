<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Service;

use Fight\AccessControl\Application\AccessControl\Agent\Security\SignedAgentRequest;
use Fight\AccessControl\Application\AccessControl\Agent\Service\HmacSignedAgentRequestVerifier;
use SensitiveParameter;

final class FixedHmacSignedAgentRequestVerifier implements HmacSignedAgentRequestVerifier
{
    private int $calls = 0;

    public function __construct(
        private readonly SignedAgentRequest $expectedRequest,
        #[SensitiveParameter] private readonly string $expectedHmacSharedSecret
    ) {
    }

    public function verifies(
        SignedAgentRequest $signedAgentRequest,
        #[SensitiveParameter] string $hmacSharedSecret
    ): bool {
        ++$this->calls;

        return $signedAgentRequest->getMethod() === $this->expectedRequest->getMethod()
            && $signedAgentRequest->getAuthority() === $this->expectedRequest->getAuthority()
            && $signedAgentRequest->getPath() === $this->expectedRequest->getPath()
            && $signedAgentRequest->getNormalizedQuery() === $this->expectedRequest->getNormalizedQuery()
            && $signedAgentRequest->getTimestamp() == $this->expectedRequest->getTimestamp()
            && $signedAgentRequest->getNonce() === $this->expectedRequest->getNonce()
            && $signedAgentRequest->getCredentialId()->equals($this->expectedRequest->getCredentialId())
            && $signedAgentRequest->getAuthorizationAlgorithm() === $this->expectedRequest->getAuthorizationAlgorithm()
            && $signedAgentRequest->getSignature() === $this->expectedRequest->getSignature()
            && $signedAgentRequest->getBodyDigest() === $this->expectedRequest->getBodyDigest()
            && $signedAgentRequest->getBody() === $this->expectedRequest->getBody()
            && hash_equals($this->expectedHmacSharedSecret, $hmacSharedSecret);
    }

    public function calls(): int
    {
        return $this->calls;
    }
}
