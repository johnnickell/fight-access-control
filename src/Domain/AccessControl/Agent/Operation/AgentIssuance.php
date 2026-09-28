<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent\Operation;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentOperationRejectedException;
use Fight\Common\Domain\Type\Arrayable;
use SensitiveParameter;
use Throwable;

/**
 * Class AgentIssuance
 *
 * Describes original issuance without asserting delivery, activation or current use authority.
 */
final readonly class AgentIssuance implements Arrayable
{
    /**
     * Constructs AgentIssuance
     */
    public function __construct(
        private AgentOperationKey $key,
        private AgentDeliveryId $deliveryId,
        private AgentId $agentId,
        private AgentCredentialId $credentialId,
        private int $credentialRevision,
        private AgentCredentialDestination $destination,
        private int $destinationWriteVersion,
        private DateTimeImmutable $issuedAt
    ) {
        if ($credentialRevision < 0 || $destinationWriteVersion < 1) {
            throw new AgentOperationRejectedException(AgentOperationFailure::INVALID_REQUEST);
        }
    }

    /**
     * Creates original metadata from its canonical safe representation
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(#[SensitiveParameter] array $data): self
    {
        try {
            foreach (['delivery_id', 'agent_id', 'credential_id', 'issued_at'] as $field) {
                if (!isset($data[$field]) || !is_string($data[$field])) {
                    throw new AgentOperationRejectedException(AgentOperationFailure::INVALID_REQUEST);
                }
            }

            foreach (['credential_revision', 'destination_write_version'] as $field) {
                if (!isset($data[$field]) || !is_int($data[$field])) {
                    throw new AgentOperationRejectedException(AgentOperationFailure::INVALID_REQUEST);
                }
            }

            $issuedAt = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.uP', $data['issued_at']);
            if ($issuedAt === false || $issuedAt->format('Y-m-d\TH:i:s.uP') !== $data['issued_at']) {
                throw new AgentOperationRejectedException(AgentOperationFailure::INVALID_REQUEST);
            }

            return new self(
                AgentOperationKey::fromArray($data),
                AgentDeliveryId::fromString($data['delivery_id']),
                AgentId::fromString($data['agent_id']),
                AgentCredentialId::fromString($data['credential_id']),
                $data['credential_revision'],
                AgentCredentialDestination::fromArray($data),
                $data['destination_write_version'],
                $issuedAt
            );
        } catch (Throwable) {
            throw new AgentOperationRejectedException(AgentOperationFailure::INVALID_REQUEST);
        }
    }

    /**
     * Returns the retained original key
     */
    public function getKey(): AgentOperationKey
    {
        return $this->key;
    }

    /**
     * Returns the globally unique delivery identity
     */
    public function getDeliveryId(): AgentDeliveryId
    {
        return $this->deliveryId;
    }

    /**
     * Returns the originally issued Agent identity
     */
    public function getAgentId(): AgentId
    {
        return $this->agentId;
    }

    /**
     * Returns the original credential identity
     */
    public function getCredentialId(): AgentCredentialId
    {
        return $this->credentialId;
    }

    /**
     * Returns the original credential revision
     */
    public function getCredentialRevision(): int
    {
        return $this->credentialRevision;
    }

    /**
     * Returns the originally authorized destination binding
     */
    public function getDestination(): AgentCredentialDestination
    {
        return $this->destination;
    }

    /**
     * Returns the write order across all Agents and scopes sharing the slot
     */
    public function getDestinationWriteVersion(): int
    {
        return $this->destinationWriteVersion;
    }

    /**
     * Returns the original issuance time
     */
    public function getIssuedAt(): DateTimeImmutable
    {
        return $this->issuedAt;
    }

    /**
     * Returns safe original metadata also used as the delivery cipher's authenticated binding
     *
     * @return array<string, int|string>
     */
    public function toArray(): array
    {
        return [
            'namespace'                 => $this->key->getScope()->getNamespace(),
            'caller_type'               => $this->key->getScope()->getCallerType(),
            'caller_id'                 => $this->key->getScope()->getCallerId(),
            'operation_id'              => $this->key->getId()->toString(),
            'delivery_id'               => $this->deliveryId->toString(),
            'agent_id'                  => $this->agentId->toString(),
            'credential_id'             => $this->credentialId->toString(),
            'credential_revision'       => $this->credentialRevision,
            'destination_id'            => $this->destination->getId()->toString(),
            'destination_revision'      => $this->destination->getRevision(),
            'destination_write_version' => $this->destinationWriteVersion,
            'issued_at'                 => $this->issuedAt->format('Y-m-d\TH:i:s.uP')
        ];
    }
}
