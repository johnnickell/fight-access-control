<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\Service;

use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentCredentialInvocation;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentDeliveryMaterial;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentIssuance;
use SensitiveParameter;

/**
 * Interface AgentDeliveryDecipher
 *
 * Materializes only a separately protected delivery copy after confirmed admission, never an authentication envelope.
 */
interface AgentDeliveryDecipher
{
    /**
     * Creates one sensitive fixed-destination invocation after authenticating every original issuance field
     *
     * Verify versioned associated data from AgentIssuance::toArray() before returning bytes. Swapped/corrupt material
     * throws AgentDeliveryFailedException(CORRUPT_MATERIAL); permanent key loss uses KEY_RETIRED, temporary failures
     * use TEMPORARY. Do not chain diagnostics. Concrete implementations must retain sensitive-parameter annotations.
     * Do not log, serialize, cache or persist the invocation; this capability is internal to delivery composition.
     */
    public function materialize(
        #[SensitiveParameter] AgentDeliveryMaterial $material,
        AgentIssuance $issuance
    ): AgentCredentialInvocation;
}
