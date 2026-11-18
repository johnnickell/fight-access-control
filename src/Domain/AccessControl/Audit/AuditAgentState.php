<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Audit;

use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentState;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Type\Arrayable;

/**
 * Class AuditAgentState
 *
 * Preserves credential identity, authoritative revision and lifecycle without key material.
 *
 * @phpstan-type AgentStatePayload array{credential_id: string, credential_revision: int, state: string}
 */
final readonly class AuditAgentState implements Arrayable
{
    /**
     * Constructs AuditAgentState
     *
     * @throws DomainException When the credential revision is negative
     */
    public function __construct(
        private AgentCredentialId $credentialId,
        private int $credentialRevision,
        private AgentState $state
    ) {
        if ($credentialRevision < 0) {
            throw new DomainException('Audit Agent credential revision must be nonnegative.');
        }
    }

    /**
     * Creates a snapshot containing only authoritative credential metadata
     */
    public static function fromAgent(Agent $agent): self
    {
        return new self($agent->getCredentialId(), $agent->getCredentialRevision(), $agent->getState());
    }

    /**
     * Creates credential metadata from its complete persisted shape
     *
     * @phpstan-param array<array-key, mixed> $data
     *
     * @throws DomainException When fields are missing, unknown or coercive
     */
    public static function fromArray(array $data): self
    {
        if (
            count($data) !== 3 || !isset($data['credential_id'], $data['credential_revision'], $data['state'])
            || !is_string($data['credential_id']) || !is_int($data['credential_revision'])
            || !is_string($data['state'])
        ) {
            throw new DomainException('Audit Agent state requires its complete current representation.');
        }

        return new self(
            AgentCredentialId::fromString($data['credential_id']),
            $data['credential_revision'],
            AgentState::from($data['state'])
        );
    }

    /**
     * Returns credential identity, revision and lifecycle without the aggregate
     *
     * @phpstan-return AgentStatePayload
     */
    public function toArray(): array
    {
        return [
            'credential_id'       => $this->credentialId->toString(),
            'credential_revision' => $this->credentialRevision,
            'state'               => $this->state->value
        ];
    }
}
