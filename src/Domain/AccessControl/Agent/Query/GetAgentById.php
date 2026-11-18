<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent\Query;

use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Query\Query;

/**
 * Class GetAgentById
 *
 * Queries one Agent by stable identifier.
 */
final readonly class GetAgentById implements Query
{
    /**
     * Constructs GetAgentById
     *
     * Creates the Agent-identity query.
     */
    public function __construct(private AgentId $agentId)
    {
    }

    /** @inheritDoc */
    public static function fromArray(array $data): static
    {
        if (!array_key_exists('agent_id', $data)) {
            throw new DomainException('Missing required key "agent_id" in data array');
        }

        if (!is_string($data['agent_id'])) {
            throw new DomainException('Required key "agent_id" must be a string.');
        }

        return new static(AgentId::fromString($data['agent_id']));
    }

    /**
     * Returns the canonical query payload
     *
     * @return array{agent_id: string}
     */
    public function toArray(): array
    {
        return ['agent_id' => $this->agentId->toString()];
    }

    /**
     * Returns the stable Agent identifier
     */
    public function getAgentId(): AgentId
    {
        return $this->agentId;
    }
}
