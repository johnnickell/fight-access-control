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
 * Class AgentCredentialRotated
 *
 * Records a committed Agent credential rotation without carrying secret material.
 */
final readonly class AgentCredentialRotated implements Event
{
    /**
     * Constructs AgentCredentialRotated
     *
     * Creates a safe post-commit Agent credential rotation event.
     */
    public function __construct(
        private AgentId $agentId,
        private AgentCredentialId $credentialId,
        private int $credentialRevision,
        private DateTimeImmutable $rotatedAt
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
        foreach (['agent_id', 'credential_id', 'rotated_at'] as $key) {
            if (!isset($data[$key]) || !is_string($data[$key])) {
                throw new DomainException('Missing or invalid Agent rotation fact field.');
            }
        }

        if (!isset($data['credential_revision']) || !is_int($data['credential_revision'])) {
            throw new DomainException('Missing or invalid Agent credential revision.');
        }

        return new static(
            AgentId::fromString($data['agent_id']),
            AgentCredentialId::fromString($data['credential_id']),
            $data['credential_revision'],
            CredentialDeliveryTimestamp::fromString($data['rotated_at'])->toDateTimeImmutable()
        );
    }

    /**
     * Returns the canonical secret-free rotation fact
     *
     * @phpstan-return array{agent_id: string, credential_id: string, credential_revision: int, rotated_at: string}
     */
    public function toArray(): array
    {
        return [
            'agent_id'            => $this->agentId->toString(),
            'credential_id'       => $this->credentialId->toString(),
            'credential_revision' => $this->credentialRevision,
            'rotated_at'          => $this->rotatedAt->format(DATE_ATOM)
        ];
    }

    /**
     * Returns the Agent whose credential was rotated
     */
    public function getAgentId(): AgentId
    {
        return $this->agentId;
    }

    /**
     * Returns the successor credential identifier
     */
    public function getCredentialId(): AgentCredentialId
    {
        return $this->credentialId;
    }

    /**
     * Returns the successor credential revision
     */
    public function getCredentialRevision(): int
    {
        return $this->credentialRevision;
    }

    /**
     * Returns when the credential rotation committed
     */
    public function getRotatedAt(): DateTimeImmutable
    {
        return $this->rotatedAt;
    }
}
