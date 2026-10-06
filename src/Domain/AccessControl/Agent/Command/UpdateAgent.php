<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent\Command;

use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentUpdateInitiator;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\Command;

/**
 * Class UpdateAgent
 *
 * Requests a name-only update through a consumer-authorized entry point.
 */
final readonly class UpdateAgent implements Command
{
    /**
     * Constructs UpdateAgent
     */
    public function __construct(
        private AgentUpdateInitiator $initiator,
        private AgentId $agentId,
        private string $name
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function fromArray(array $data): static
    {
        foreach (['initiator', 'agent_id', 'name'] as $key) {
            if (!array_key_exists($key, $data)) {
                throw new DomainException(sprintf('Missing required key "%s" in data array', $key));
            }
        }

        return new static(
            AgentUpdateInitiator::fromArray($data['initiator']),
            AgentId::fromString((string) $data['agent_id']),
            (string) $data['name']
        );
    }

    /**
     * @inheritDoc
     */
    public function toArray(): array
    {
        return [
            'initiator' => $this->initiator->toArray(),
            'agent_id'  => $this->agentId->toString(),
            'name'      => $this->name
        ];
    }

    /**
     * Returns the typed initiating identity
     */
    public function getInitiator(): AgentUpdateInitiator
    {
        return $this->initiator;
    }

    /**
     * Returns the target Agent
     */
    public function getAgentId(): AgentId
    {
        return $this->agentId;
    }

    /**
     * Returns the requested name for validation by AgentName
     */
    public function getName(): string
    {
        return $this->name;
    }
}
