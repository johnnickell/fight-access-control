<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Audit;

use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentDeliveryId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Type\Arrayable;

/**
 * Class AuditAgentOutcome
 *
 * Describes authoritative credential states and the actual issuance delivery relationship.
 *
 * @phpstan-import-type AgentStatePayload from AuditAgentState
 * @phpstan-type AgentOutcomePayload array{before: ?AgentStatePayload, after: AgentStatePayload, delivery_id: ?string}
 */
final readonly class AuditAgentOutcome implements Arrayable
{
    /**
     * Constructs AuditAgentOutcome
     */
    public function __construct(
        private ?AuditAgentState $before,
        private AuditAgentState $after,
        private ?AgentDeliveryId $deliveryId
    ) {
    }

    /**
     * Creates facts from their complete persisted shape
     *
     * @phpstan-param array<array-key, mixed> $data
     *
     * @throws DomainException When persisted fields are missing, unknown or coercive
     */
    public static function fromArray(array $data): self
    {
        if (
            count($data) !== 3 || !array_key_exists('before', $data)
            || !isset($data['after']) || !is_array($data['after'])
            || ($data['before'] !== null && !is_array($data['before']))
            || !array_key_exists('delivery_id', $data)
            || ($data['delivery_id'] !== null && !is_string($data['delivery_id']))
        ) {
            throw new DomainException('Audit Agent outcome requires its complete current representation.');
        }

        return new self(
            $data['before'] === null ? null : AuditAgentState::fromArray($data['before']),
            AuditAgentState::fromArray($data['after']),
            $data['delivery_id'] === null ? null : AgentDeliveryId::fromString($data['delivery_id'])
        );
    }

    /**
     * Returns the authoritative predecessor or absence on provisioning
     */
    public function getBefore(): ?AuditAgentState
    {
        return $this->before;
    }

    /**
     * Returns the authoritative successor
     */
    public function getAfter(): AuditAgentState
    {
        return $this->after;
    }

    /**
     * Returns the actual issuance delivery reference when present
     */
    public function getDeliveryId(): ?AgentDeliveryId
    {
        return $this->deliveryId;
    }

    /**
     * Returns admitted credential and delivery facts
     *
     * @phpstan-return AgentOutcomePayload
     */
    public function toArray(): array
    {
        return [
            'before'      => $this->before?->toArray(),
            'after'       => $this->after->toArray(),
            'delivery_id' => $this->deliveryId?->toString()
        ];
    }
}
