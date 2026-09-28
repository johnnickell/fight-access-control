<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\Service;

use Fight\AccessControl\Domain\AccessControl\Agent\Maintenance\AgentDeliveryKeyVersion;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentDeliveryMaterial;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentIssuance;
use SensitiveParameter;

/**
 * Interface AgentDeliveryRewrapper
 *
 * Reprotects original delivery bytes without exposing a plaintext or general secret-read handle.
 */
interface AgentDeliveryRewrapper
{
    /**
     * Creates authenticated delivery ciphertext at the requested writable key version
     *
     * Runs under maintenance authorization and shared key-reference fences in the package transaction. Authenticate
     * versioned associated data covering every issuance field before re-encrypting identical bytes. Never read an
     * authentication envelope or invoke a sink. Return only a separately protected copy. Keep key use leased against
     * physical retirement until completion; bound provider latency. A consumer unable to participate fails closed.
     * Throw sanitized AgentDeliveryFailedException: TEMPORARY, KEY_RETIRED for permanent source-key loss, or
     * CORRUPT_MATERIAL. Target-key unavailability is TEMPORARY, not loss of the original. Never chain diagnostics.
     * Implementations must retain SensitiveParameter; do not log, cache or serialize plaintext intermediates.
     */
    public function rewrap(
        #[SensitiveParameter] AgentDeliveryMaterial $material,
        AgentIssuance $issuance,
        AgentDeliveryKeyVersion $target
    ): AgentDeliveryMaterial;
}
