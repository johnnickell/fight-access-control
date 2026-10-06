<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\Service;

use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentDeliveryMaterial;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentIssuance;
use SensitiveParameter;

/**
 * Interface AgentDeliveryCipher
 *
 * Encrypts a separate delivery copy without reusing the authentication envelope or exposing a general read port.
 */
interface AgentDeliveryCipher
{
    /**
     * Encrypts original bytes with authenticated binding to every field of the issuance tuple
     *
     * Implementations authenticate AgentIssuance::toArray() as versioned associated data; ciphertext swaps across
     * any operation, credential, destination or write order must fail later admitted decryption. Return an opaque
     * key version, never provider paths. Key/encryption failure aborts issuance. No sink invocation occurs here.
     */
    public function encrypt(#[SensitiveParameter] string $secret, AgentIssuance $issuance): AgentDeliveryMaterial;
}
