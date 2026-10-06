<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent\Query;

use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentName;
use Fight\Common\Domain\Type\Arrayable;

/**
 * Class AgentProfileView
 *
 * Contains only the Agent identity and name observed by a profile read.
 */
final readonly class AgentProfileView implements Arrayable
{
    /**
     * Constructs AgentProfileView
     */
    public function __construct(private AgentId $agentId, private AgentName $name)
    {
    }

    /**
     * Returns the stable Agent identifier
     */
    public function getAgentId(): AgentId
    {
        return $this->agentId;
    }

    /**
     * Returns the observed Agent name
     */
    public function getName(): AgentName
    {
        return $this->name;
    }

    /**
     * Returns the exact minimal profile representation
     *
     * @return array{agent_id: string, name: string}
     */
    public function toArray(): array
    {
        return [
            'agent_id' => $this->agentId->toString(),
            'name'     => $this->name->toString()
        ];
    }
}
