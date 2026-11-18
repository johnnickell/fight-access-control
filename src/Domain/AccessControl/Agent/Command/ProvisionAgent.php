<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent\Command;

use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentCredentialDestination;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationFailure;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationKey;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentProvisioningRequest;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Exception\AgentOperationRejectedException;
use Fight\Common\Domain\Messaging\Command\Command;
use SensitiveParameter;

/**
 * Class ProvisionAgent
 *
 * Retains original secret-free intent independently of envelope identity or current executing authority.
 */
final readonly class ProvisionAgent implements Command
{
    /**
     * Constructs ProvisionAgent
     */
    public function __construct(private AgentOperationKey $key, private AgentProvisioningRequest $request)
    {
    }

    /**
     * @inheritDoc
     */
    public static function fromArray(#[SensitiveParameter] array $data): static
    {
        if (!isset($data['name']) || !is_string($data['name'])) {
            throw new AgentOperationRejectedException(AgentOperationFailure::INVALID_REQUEST);
        }

        return new static(
            AgentOperationKey::fromArray($data),
            new AgentProvisioningRequest($data['name'], AgentCredentialDestination::fromArray($data))
        );
    }

    /**
     * Returns the complete original secret-free intent
     *
     * @phpstan-return array{
     *     namespace: string, caller_type: string, caller_id: string, operation_id: string,
     *     name: string, destination_id: string, destination_revision: int
     * }
     */
    public function toArray(): array
    {
        $intent = ['name' => $this->request->getName()] + $this->request->getDestination()->toArray();

        return $this->key->toArray() + $intent;
    }

    /**
     * Returns the retained original scoped key rather than execution authority
     */
    public function getKey(): AgentOperationKey
    {
        return $this->key;
    }

    /**
     * Returns the immutable original request including its registered destination binding
     */
    public function getRequest(): AgentProvisioningRequest
    {
        return $this->request;
    }
}
