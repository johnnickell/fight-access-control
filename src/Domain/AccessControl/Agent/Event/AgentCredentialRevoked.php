<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent\Event;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Event\Event;

/**
 * Records a committed terminal Agent credential revocation without secret material.
 */
final readonly class AgentCredentialRevoked implements Event
{
    /**
     * Creates a safe post-commit Agent credential revocation event.
     */
    public function __construct(private AgentId $agentId, private DateTimeImmutable $revokedAt)
    {
    }

    /**
     * @inheritDoc
     */
    public static function fromArray(array $data): static
    {
        foreach (['agent_id', 'revoked_at'] as $key) {
            if (!array_key_exists($key, $data)) {
                throw new DomainException(sprintf('Missing required key "%s" in data array', $key));
            }
        }

        return new static(
            AgentId::fromString((string) $data['agent_id']),
            new DateTimeImmutable((string) $data['revoked_at'])
        );
    }

    /**
     * @inheritDoc
     */
    public function toArray(): array
    {
        return [
            'agent_id'   => $this->agentId->toString(),
            'revoked_at' => $this->revokedAt->format(DATE_ATOM),
        ];
    }

    /**
     * Returns the Agent whose credential was terminally revoked.
     */
    public function getAgentId(): AgentId
    {
        return $this->agentId;
    }

    /**
     * Returns when the Agent credential revocation committed.
     */
    public function getRevokedAt(): DateTimeImmutable
    {
        return $this->revokedAt;
    }
}
