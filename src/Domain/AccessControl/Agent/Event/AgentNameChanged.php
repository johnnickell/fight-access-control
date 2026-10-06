<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent\Event;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentName;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentUpdateInitiator;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Event\Event;

/**
 * Class AgentNameChanged
 *
 * Records a committed normalized name change and its typed provenance.
 */
final readonly class AgentNameChanged implements Event
{
    /**
     * Constructs AgentNameChanged
     */
    public function __construct(
        private AgentUpdateInitiator $initiator,
        private AgentId $agentId,
        private AgentName $name,
        private DateTimeImmutable $changedAt
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function fromArray(array $data): static
    {
        foreach (['initiator', 'agent_id', 'name', 'changed_at'] as $key) {
            if (!array_key_exists($key, $data)) {
                throw new DomainException(sprintf('Missing required key "%s" in data array', $key));
            }
        }

        return new static(
            AgentUpdateInitiator::fromArray($data['initiator']),
            AgentId::fromString((string) $data['agent_id']),
            AgentName::fromString((string) $data['name']),
            new DateTimeImmutable((string) $data['changed_at'])
        );
    }

    /**
     * @inheritDoc
     */
    public function toArray(): array
    {
        return [
            'initiator'  => $this->initiator->toArray(),
            'agent_id'   => $this->agentId->toString(),
            'name'       => $this->name->toString(),
            'changed_at' => $this->changedAt->format('Y-m-d\TH:i:s.uP')
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
     * Returns the changed Agent
     */
    public function getAgentId(): AgentId
    {
        return $this->agentId;
    }

    /**
     * Returns the committed normalized name
     */
    public function getName(): AgentName
    {
        return $this->name;
    }

    /**
     * Returns the change timestamp
     */
    public function getChangedAt(): DateTimeImmutable
    {
        return $this->changedAt;
    }
}
