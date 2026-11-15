<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Audit;

use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentDeliveryId;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Delivery\AgentDeliveryFailure;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Maintenance\AgentDeliveryKeyVersion;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Type\Arrayable;

/**
 * Class AuditAgentMaintenanceOutcome
 *
 * Retains exact original relationships and operation revisions, never credential authority or protected material.
 *
 * @phpstan-type MaintenanceOutcomePayload array{
 *     credential_id: string, delivery_id: string, revision_before: int, revision_after: int,
 *     key_version_before: ?string, key_version_after: ?string, failure: ?string
 * }
 */
final readonly class AuditAgentMaintenanceOutcome implements Arrayable
{
    /**
     * Constructs AuditAgentMaintenanceOutcome
     *
     * @throws DomainException When revisions do not describe one actual transition
     */
    public function __construct(
        private AgentCredentialId $credentialId,
        private AgentDeliveryId $deliveryId,
        private int $revisionBefore,
        private int $revisionAfter,
        private ?AgentDeliveryKeyVersion $keyVersionBefore,
        private ?AgentDeliveryKeyVersion $keyVersionAfter,
        private ?AgentDeliveryFailure $failure
    ) {
        if ($revisionBefore < 0 || $revisionBefore === PHP_INT_MAX || $revisionAfter !== $revisionBefore + 1) {
            throw new DomainException('Audit maintenance requires consecutive authoritative operation revisions.');
        }
    }

    /**
     * Creates facts from their complete current persisted representation
     *
     * @phpstan-param array<array-key, mixed> $data
     *
     * @throws DomainException When fields are missing, unknown or coercive
     */
    public static function fromArray(array $data): self
    {
        if (
            count($data) !== 7 || !isset(
                $data['credential_id'],
                $data['delivery_id'],
                $data['revision_before'],
                $data['revision_after']
            )
            || !is_string($data['credential_id']) || !is_string($data['delivery_id'])
            || !is_int($data['revision_before']) || !is_int($data['revision_after'])
            || !array_key_exists('key_version_before', $data) || !array_key_exists('key_version_after', $data)
            || !array_key_exists('failure', $data)
            || ($data['key_version_before'] !== null && !is_string($data['key_version_before']))
            || ($data['key_version_after'] !== null && !is_string($data['key_version_after']))
            || ($data['failure'] !== null && !is_string($data['failure']))
        ) {
            throw new DomainException('Audit maintenance requires its complete current representation.');
        }

        $failure = null;
        if ($data['failure'] !== null) {
            $failure = AgentDeliveryFailure::tryFrom($data['failure']) ?? throw new DomainException(
                'Audit maintenance failure is unsupported.'
            );
        }

        return new self(
            AgentCredentialId::fromString($data['credential_id']),
            AgentDeliveryId::fromString($data['delivery_id']),
            $data['revision_before'],
            $data['revision_after'],
            $data['key_version_before'] === null ? null : new AgentDeliveryKeyVersion($data['key_version_before']),
            $data['key_version_after'] === null ? null : new AgentDeliveryKeyVersion($data['key_version_after']),
            $failure
        );
    }

    /**
     * Validates facts against the specific maintenance action
     *
     * @throws DomainException When facts do not describe the declared outcome
     */
    public function assertOutcome(AuditAction $action): void
    {
        $valid = match ($action) {
            AuditAction::AGENT_MATERIAL_REWRAPPED => $this->keyVersionBefore !== null
                && $this->keyVersionAfter !== null
                && $this->keyVersionBefore->toString() !== $this->keyVersionAfter->toString(),
            AuditAction::AGENT_MATERIAL_FAILED => $this->keyVersionBefore !== null && $this->keyVersionAfter === null
                && in_array($this->failure, [
                    AgentDeliveryFailure::KEY_RETIRED, AgentDeliveryFailure::CORRUPT_MATERIAL
                ], true),
            AuditAction::AGENT_MATERIAL_EXPIRED => $this->keyVersionBefore !== null && $this->keyVersionAfter === null
                && $this->failure === null,
            AuditAction::AGENT_SINK_CLEANED => $this->keyVersionBefore === null && $this->keyVersionAfter === null,
            default => false
        };
        if (!$valid) {
            throw new DomainException('Audit maintenance facts must match their actual outcome.');
        }
    }

    /**
     * Returns bounded maintenance facts without ciphertext, receipts or issuance snapshots
     *
     * @phpstan-return MaintenanceOutcomePayload
     */
    public function toArray(): array
    {
        return [
            'credential_id'      => $this->credentialId->toString(),
            'delivery_id'        => $this->deliveryId->toString(),
            'revision_before'    => $this->revisionBefore,
            'revision_after'     => $this->revisionAfter,
            'key_version_before' => $this->keyVersionBefore?->toString(),
            'key_version_after'  => $this->keyVersionAfter?->toString(),
            'failure'            => $this->failure?->value
        ];
    }
}
