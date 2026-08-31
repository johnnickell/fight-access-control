<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent\Event;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Event\Event;

/**
 * Records a committed Agent credential rotation without carrying secret material.
 */
final readonly class AgentCredentialRotated implements Event
{
    /**
     * Creates a safe post-commit Agent credential rotation event.
     */
    public function __construct(
        private AgentId $agentId,
        private AgentCredentialId $credentialId,
        private int $credentialRevision,
        private DateTimeImmutable $rotatedAt
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function fromArray(array $data): static
    {
        foreach (['agent_id', 'credential_id', 'credential_revision', 'rotated_at'] as $key) {
            if (!array_key_exists($key, $data)) {
                throw new DomainException(sprintf('Missing required key "%s" in data array', $key));
            }
        }

        return new static(
            AgentId::fromString((string) $data['agent_id']),
            AgentCredentialId::fromString((string) $data['credential_id']),
            (int) $data['credential_revision'],
            new DateTimeImmutable((string) $data['rotated_at'])
        );
    }

    /**
     * @inheritDoc
     */
    public function toArray(): array
    {
        return [
            'agent_id'            => $this->agentId->toString(),
            'credential_id'       => $this->credentialId->toString(),
            'credential_revision' => $this->credentialRevision,
            'rotated_at'          => $this->rotatedAt->format(DATE_ATOM),
        ];
    }

    /**
     * Returns the Agent whose credential was rotated.
     */
    public function getAgentId(): AgentId
    {
        return $this->agentId;
    }

    /**
     * Returns the successor credential identifier.
     */
    public function getCredentialId(): AgentCredentialId
    {
        return $this->credentialId;
    }

    /**
     * Returns the successor credential revision.
     */
    public function getCredentialRevision(): int
    {
        return $this->credentialRevision;
    }

    /**
     * Returns when the credential rotation committed.
     */
    public function getRotatedAt(): DateTimeImmutable
    {
        return $this->rotatedAt;
    }
}
