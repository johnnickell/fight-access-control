<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent\Event;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryTimestamp;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Event\Event;

/**
 * Class AgentCredentialRevoked
 *
 * Records a committed terminal Agent credential revocation without secret material.
 */
final readonly class AgentCredentialRevoked implements Event
{
    /**
     * Constructs AgentCredentialRevoked
     *
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
            if (!isset($data[$key]) || !is_string($data[$key])) {
                throw new DomainException('Missing or invalid Agent revocation fact field.');
            }
        }

        return new static(
            AgentId::fromString($data['agent_id']),
            CredentialDeliveryTimestamp::fromString($data['revoked_at'])->toDateTimeImmutable()
        );
    }

    /**
     * Returns the canonical secret-free revocation fact
     *
     * @phpstan-return array{agent_id: string, revoked_at: string}
     */
    public function toArray(): array
    {
        return [
            'agent_id'   => $this->agentId->toString(),
            'revoked_at' => $this->revokedAt->format(DATE_ATOM)
        ];
    }

    /**
     * Returns the Agent whose credential was terminally revoked
     */
    public function getAgentId(): AgentId
    {
        return $this->agentId;
    }

    /**
     * Returns when the Agent credential revocation committed
     */
    public function getRevokedAt(): DateTimeImmutable
    {
        return $this->revokedAt;
    }
}
