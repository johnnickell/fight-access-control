<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\Service;

/**
 * Generates one raw HMAC shared secret for synchronous Agent provisioning.
 */
interface HmacSharedSecretGenerator
{
    /**
     * Generates an unpredictable raw HMAC shared secret.
     */
    public function generate(): string;
}
