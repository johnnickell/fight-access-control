<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent\Query;

use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentCredentialDestination;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationKey;
use Fight\Common\Domain\Messaging\Query\Query;
use SensitiveParameter;

/**
 * Class GetAgentOperation
 *
 * Requests safe status using the retained scope and destination, not an authentication capability.
 */
final readonly class GetAgentOperation implements Query
{
    /**
     * Constructs GetAgentOperation
     */
    public function __construct(private AgentOperationKey $key, private AgentCredentialDestination $destination)
    {
    }

    /**
     * @inheritDoc
     */
    public static function fromArray(#[SensitiveParameter] array $data): static
    {
        return new static(AgentOperationKey::fromArray($data), AgentCredentialDestination::fromArray($data));
    }

    /**
     * @inheritDoc
     */
    public function toArray(): array
    {
        return $this->key->toArray() + $this->destination->toArray();
    }

    /**
     * Returns the original scoped key independently of the authenticated invoker
     */
    public function getKey(): AgentOperationKey
    {
        return $this->key;
    }

    /**
     * Returns the registered destination whose current authorization must be checked
     */
    public function getDestination(): AgentCredentialDestination
    {
        return $this->destination;
    }
}
