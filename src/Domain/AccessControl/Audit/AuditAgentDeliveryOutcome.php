<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Audit;

use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentDeliveryDisposition;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentDeliveryId;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Delivery\AgentDeliveryFailure;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Type\Arrayable;

/**
 * Class AuditAgentDeliveryOutcome
 *
 * Retains one committed operation transition without material, receipt or worker diagnostics.
 *
 * @phpstan-type AgentDeliveryOutcomePayload array{
 *     credential_id: string, credential_revision: int, delivery_id: string,
 *     before_revision: int, after_revision: int, before: string, after: string, failure: ?string
 * }
 */
final readonly class AuditAgentDeliveryOutcome implements Arrayable
{
    /**
     * Constructs AuditAgentDeliveryOutcome
     */
    public function __construct(
        private AgentCredentialId $credentialId,
        private int $credentialRevision,
        private AgentDeliveryId $deliveryId,
        private int $beforeRevision,
        private int $afterRevision,
        private AgentDeliveryDisposition $before,
        private AgentDeliveryDisposition $after,
        private ?AgentDeliveryFailure $failure
    ) {
        if (
            $credentialRevision < 0 || $beforeRevision < 0 || $beforeRevision === PHP_INT_MAX
            || $afterRevision !== $beforeRevision + 1
            || !in_array($before, [AgentDeliveryDisposition::PENDING, AgentDeliveryDisposition::RETRYABLE], true)
        ) {
            throw new DomainException('Audit delivery requires its actual credential and single operation advance.');
        }

        $valid = match ($after) {
            AgentDeliveryDisposition::DELIVERED, AgentDeliveryDisposition::EXPIRED => $failure === null,
            AgentDeliveryDisposition::RETRYABLE => $failure === AgentDeliveryFailure::TEMPORARY,
            AgentDeliveryDisposition::TERMINAL => $failure !== AgentDeliveryFailure::TEMPORARY,
            default => false
        };
        if (!$valid) {
            throw new DomainException('Audit delivery requires a meaningful admitted outcome.');
        }
    }

    /**
     * Creates the complete current persisted representation
     *
     * @phpstan-param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        if (
            count($data) !== 8 || !isset(
                $data['credential_id'],
                $data['credential_revision'],
                $data['delivery_id'],
                $data['before_revision'],
                $data['after_revision'],
                $data['before'],
                $data['after']
            ) || !is_string($data['credential_id']) || !is_int($data['credential_revision'])
            || !is_string($data['delivery_id']) || !is_int($data['before_revision'])
            || !is_int($data['after_revision']) || !is_string($data['before']) || !is_string($data['after'])
            || !array_key_exists('failure', $data) || ($data['failure'] !== null && !is_string($data['failure']))
        ) {
            throw new DomainException('Audit delivery requires its complete current representation.');
        }

        $failure = null;
        if ($data['failure'] !== null) {
            $failure = AgentDeliveryFailure::tryFrom($data['failure']);
            if ($failure === null) {
                throw new DomainException('Audit delivery failure is unsupported.');
            }
        }

        return new self(
            AgentCredentialId::fromString($data['credential_id']),
            $data['credential_revision'],
            AgentDeliveryId::fromString($data['delivery_id']),
            $data['before_revision'],
            $data['after_revision'],
            AgentDeliveryDisposition::tryFrom($data['before']) ?? throw new DomainException('Invalid predecessor.'),
            AgentDeliveryDisposition::tryFrom($data['after']) ?? throw new DomainException('Invalid successor.'),
            $failure
        );
    }

    /**
     * Validates the action against its admitted outcome
     */
    public function assertOutcome(AuditAction $action): void
    {
        $expected = match ($this->after) {
            AgentDeliveryDisposition::DELIVERED => AuditAction::AGENT_DELIVERY_CONFIRMED,
            AgentDeliveryDisposition::EXPIRED => AuditAction::AGENT_DELIVERY_EXPIRED,
            AgentDeliveryDisposition::TERMINAL => match ($this->failure) {
                null => AuditAction::AGENT_DELIVERY_EXHAUSTED,
                default => AuditAction::AGENT_DELIVERY_FAILED
            },
            default => AuditAction::AGENT_DELIVERY_FAILED
        };
        if ($action !== $expected) {
            throw new DomainException('Audit delivery action must match its committed outcome.');
        }
    }

    /**
     * Returns bounded typed relationships and operation transition facts
     *
     * @phpstan-return AgentDeliveryOutcomePayload
     */
    public function toArray(): array
    {
        return [
            'credential_id'       => $this->credentialId->toString(),
            'credential_revision' => $this->credentialRevision,
            'delivery_id'         => $this->deliveryId->toString(),
            'before_revision'     => $this->beforeRevision,
            'after_revision'      => $this->afterRevision,
            'before'              => $this->before->value,
            'after'               => $this->after->value,
            'failure'             => $this->failure?->value
        ];
    }
}
