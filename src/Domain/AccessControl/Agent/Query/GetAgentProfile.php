<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent\Query;

use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Query\Query;

/**
 * Class GetAgentProfile
 *
 * Requests a minimal current profile without granting caller authority.
 */
final readonly class GetAgentProfile implements Query
{
    /**
     * Constructs GetAgentProfile
     */
    public function __construct(private AgentId $agentId)
    {
    }

    /**
     * @inheritDoc
     */
    public static function fromArray(array $data): static
    {
        if (!array_key_exists('agent_id', $data)) {
            throw new DomainException('Missing required key "agent_id" in data array');
        }

        return new static(AgentId::fromString((string) $data['agent_id']));
    }

    /**
     * @inheritDoc
     */
    public function toArray(): array
    {
        return ['agent_id' => $this->agentId->toString()];
    }

    /**
     * Returns the target Agent identifier
     */
    public function getAgentId(): AgentId
    {
        return $this->agentId;
    }
}
