<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent\Operation;

use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentOperationRejectedException;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\EncryptedCredentialMaterial;
use LogicException;
use SensitiveParameter;

/**
 * Class AgentDeliveryMaterial
 *
 * Retains a separately encrypted pending copy and opaque key version for consumer persistence.
 */
final readonly class AgentDeliveryMaterial
{
    /**
     * Constructs AgentDeliveryMaterial
     */
    public function __construct(
        #[SensitiveParameter] private EncryptedCredentialMaterial $ciphertext,
        private string $keyVersion
    ) {
        if (preg_match('/\A[A-Za-z0-9_.-]{1,64}\z/D', $keyVersion) !== 1) {
            throw new AgentOperationRejectedException(AgentOperationFailure::INVALID_REQUEST);
        }
    }

    /**
     * Returns encrypted material only to persistence or an admitted internal delivery path
     */
    public function getCiphertext(): EncryptedCredentialMaterial
    {
        return $this->ciphertext;
    }

    /**
     * Returns the opaque key version for pending-copy accounting rather than a key path
     */
    public function getKeyVersion(): string
    {
        return $this->keyVersion;
    }

    /**
     * Returns a redacted diagnostic representation
     *
     * @return array{material: string}
     */
    public function __debugInfo(): array
    {
        return ['material' => '[REDACTED]'];
    }

    /**
     * Prevents prepared delivery material from entering ordinary serialization
     */
    public function __serialize(): array
    {
        throw new LogicException('Agent delivery material cannot be serialized.');
    }
}
