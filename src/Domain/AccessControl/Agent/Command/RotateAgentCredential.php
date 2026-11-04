<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent\Command;

use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentCredentialDestination;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationFailure;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationKey;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentRotationRequest;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Exception\AgentOperationRejectedException;
use Fight\Common\Domain\Messaging\Command\Command;
use SensitiveParameter;
use Throwable;

/**
 * Class RotateAgentCredential
 *
 * Retains the exact original predecessor across delayed invocation and redelivery without granting authority.
 */
final readonly class RotateAgentCredential implements Command
{
    /**
     * Constructs RotateAgentCredential
     */
    public function __construct(private AgentOperationKey $key, private AgentRotationRequest $request)
    {
    }

    /**
     * @inheritDoc
     */
    public static function fromArray(#[SensitiveParameter] array $data): static
    {
        try {
            if (
                !isset($data['agent_id'], $data['expected_credential_id'], $data['expected_credential_revision'])
                || !is_string($data['agent_id']) || !is_string($data['expected_credential_id'])
                || !is_int($data['expected_credential_revision'])
            ) {
                throw new AgentOperationRejectedException(AgentOperationFailure::INVALID_REQUEST);
            }

            return new static(
                AgentOperationKey::fromArray($data),
                new AgentRotationRequest(
                    AgentId::fromString($data['agent_id']),
                    AgentCredentialId::fromString($data['expected_credential_id']),
                    $data['expected_credential_revision'],
                    AgentCredentialDestination::fromArray($data)
                )
            );
        } catch (Throwable) {
            throw new AgentOperationRejectedException(AgentOperationFailure::INVALID_REQUEST);
        }
    }

    /**
     * @inheritDoc
     */
    public function toArray(): array
    {
        return $this->key->toArray() + [
            'agent_id'                     => $this->request->getAgentId()->toString(),
            'expected_credential_id'       => $this->request->getExpectedCredentialId()->toString(),
            'expected_credential_revision' => $this->request->getExpectedCredentialRevision()
        ] + $this->request->getDestination()->toArray();
    }

    /**
     * Returns the retained original scoped key rather than execution authority
     */
    public function getKey(): AgentOperationKey
    {
        return $this->key;
    }

    /**
     * Returns the immutable original target and predecessor without silently retargeting retries
     */
    public function getRequest(): AgentRotationRequest
    {
        return $this->request;
    }
}
