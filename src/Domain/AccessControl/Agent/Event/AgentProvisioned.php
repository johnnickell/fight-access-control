<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent\Event;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryTimestamp;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Event\Event;

/**
 * Class AgentProvisioned
 *
 * Records a committed Agent provisioning without carrying secret material.
 */
final readonly class AgentProvisioned implements Event
{
    /**
     * Constructs AgentProvisioned
     *
     * Creates a safe post-commit Agent provisioning event.
     */
    public function __construct(
        private AgentId $agentId,
        private AgentCredentialId $credentialId,
        private int $credentialRevision,
        private DateTimeImmutable $provisionedAt
    ) {
        if ($credentialRevision < 0) {
            throw new DomainException('Invalid Agent credential revision.');
        }
    }

    /**
     * @inheritDoc
     */
    public static function fromArray(array $data): static
    {
        foreach (['agent_id', 'credential_id', 'provisioned_at'] as $key) {
            if (!isset($data[$key]) || !is_string($data[$key])) {
                throw new DomainException('Missing or invalid Agent provisioning fact field.');
            }
        }

        if (!isset($data['credential_revision']) || !is_int($data['credential_revision'])) {
            throw new DomainException('Missing or invalid Agent credential revision.');
        }

        return new static(
            AgentId::fromString($data['agent_id']),
            AgentCredentialId::fromString($data['credential_id']),
            $data['credential_revision'],
            CredentialDeliveryTimestamp::fromString($data['provisioned_at'])->toDateTimeImmutable()
        );
    }

    /**
     * Returns the canonical secret-free provisioning fact
     *
     * @phpstan-return array{agent_id: string, credential_id: string, credential_revision: int, provisioned_at: string}
     */
    public function toArray(): array
    {
        return [
            'agent_id'            => $this->agentId->toString(),
            'credential_id'       => $this->credentialId->toString(),
            'credential_revision' => $this->credentialRevision,
            'provisioned_at'      => $this->provisionedAt->format(DATE_ATOM)
        ];
    }

    /**
     * Returns the provisioned Agent identifier
     */
    public function getAgentId(): AgentId
    {
        return $this->agentId;
    }

    /**
     * Returns the provisioned Agent credential identifier
     */
    public function getCredentialId(): AgentCredentialId
    {
        return $this->credentialId;
    }

    /**
     * Returns the initial credential revision
     */
    public function getCredentialRevision(): int
    {
        return $this->credentialRevision;
    }

    /**
     * Returns when the Agent was provisioned
     */
    public function getProvisionedAt(): DateTimeImmutable
    {
        return $this->provisionedAt;
    }
}
