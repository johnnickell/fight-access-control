<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent\Event;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Event\Event;

/**
 * Records a committed Agent provisioning without carrying secret material.
 */
final readonly class AgentProvisioned implements Event
{
    /**
     * Creates a safe post-commit Agent provisioning event.
     */
    public function __construct(
        private AgentId $agentId,
        private AgentCredentialId $credentialId,
        private int $credentialRevision,
        private DateTimeImmutable $provisionedAt
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function fromArray(array $data): static
    {
        foreach (['agent_id', 'credential_id', 'credential_revision', 'provisioned_at'] as $key) {
            if (!array_key_exists($key, $data)) {
                throw new DomainException(sprintf('Missing required key "%s" in data array', $key));
            }
        }

        return new static(
            AgentId::fromString((string) $data['agent_id']),
            AgentCredentialId::fromString((string) $data['credential_id']),
            (int) $data['credential_revision'],
            new DateTimeImmutable((string) $data['provisioned_at'])
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
            'provisioned_at'      => $this->provisionedAt->format(DATE_ATOM),
        ];
    }

    /**
     * Returns the provisioned Agent identifier.
     */
    public function getAgentId(): AgentId
    {
        return $this->agentId;
    }

    /**
     * Returns the provisioned Agent credential identifier.
     */
    public function getCredentialId(): AgentCredentialId
    {
        return $this->credentialId;
    }

    /**
     * Returns the initial credential revision.
     */
    public function getCredentialRevision(): int
    {
        return $this->credentialRevision;
    }

    /**
     * Returns when the Agent was provisioned.
     */
    public function getProvisionedAt(): DateTimeImmutable
    {
        return $this->provisionedAt;
    }
}
