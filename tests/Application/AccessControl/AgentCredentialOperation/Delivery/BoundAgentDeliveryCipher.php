<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery;

use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery\AgentDeliveryCipher;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentDeliveryMaterial;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentIssuance;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\EncryptedCredentialMaterial;
use LogicException;
use SensitiveParameter;

final readonly class BoundAgentDeliveryCipher implements AgentDeliveryCipher
{
    public function encrypt(#[SensitiveParameter] string $secret, AgentIssuance $issuance): AgentDeliveryMaterial
    {
        // Deliberately not production cryptography: proves the package supplies the exact binding and original bytes.
        return new AgentDeliveryMaterial(
            EncryptedCredentialMaterial::fromString(json_encode([$issuance->toArray(), $secret], JSON_THROW_ON_ERROR)),
            'test-key-v1'
        );
    }

    public function inspect(AgentDeliveryMaterial $material, AgentIssuance $issuance): string
    {
        [$binding, $secret] = json_decode($material->getCiphertext()->reveal(), true, flags: JSON_THROW_ON_ERROR);
        if ($binding !== $issuance->toArray()) {
            throw new LogicException('Delivery binding mismatch.');
        }

        return $secret;
    }
}
