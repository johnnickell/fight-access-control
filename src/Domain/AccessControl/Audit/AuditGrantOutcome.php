<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Audit;

use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryFailure;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryStatus;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Type\Arrayable;

/**
 * Class AuditGrantOutcome
 *
 * Describes a grant or delivery outcome without inventing a mutation or snapshot of its owning User.
 *
 * @phpstan-import-type GrantStatePayload from AuditGrantState
 * @phpstan-type GrantOutcomePayload array{
 *     grant_before: ?GrantStatePayload, grant_after: GrantStatePayload, delivery_status: ?string, failure: ?string,
 *     replaced_predecessor: ?GrantStatePayload
 * }
 */
final readonly class AuditGrantOutcome implements Arrayable
{
    /**
     * Constructs AuditGrantOutcome
     *
     * @throws DomainException When failure classification contradicts the persisted delivery status
     */
    public function __construct(
        private ?AuditGrantState $before,
        private AuditGrantState $after,
        private ?CredentialDeliveryStatus $deliveryStatus = null,
        private ?CredentialDeliveryFailure $failure = null,
        private ?AuditGrantState $replacedPredecessor = null
    ) {
        if ($before !== null) {
            $before->assertReplacement($after, $replacedPredecessor);
        } elseif ($replacedPredecessor !== null) {
            throw new DomainException('Initial grant issuance has no replaced predecessor.');
        }

        if (
            $failure !== null && $deliveryStatus !== match ($failure) {
                CredentialDeliveryFailure::PERMANENT_PROVIDER => CredentialDeliveryStatus::PERMANENT_FAILURE,
                default => CredentialDeliveryStatus::RETRY_PENDING
            }
        ) {
            throw new DomainException('Audit failure classification must match the persisted delivery outcome.');
        }
    }

    /**
     * Creates admitted facts from the complete persisted representation
     *
     * @phpstan-param array<array-key, mixed> $data
     *
     * @throws DomainException When fields are missing, unknown or coercive
     */
    public static function fromArray(array $data): self
    {
        if (
            count($data) !== 5 || !array_key_exists('grant_before', $data)
            || !array_key_exists('replaced_predecessor', $data)
            || ($data['replaced_predecessor'] !== null && !is_array($data['replaced_predecessor']))
            || ($data['grant_before'] !== null && !is_array($data['grant_before']))
            || !isset($data['grant_after']) || !is_array($data['grant_after'])
            || !array_key_exists('delivery_status', $data) || !array_key_exists('failure', $data)
            || ($data['delivery_status'] !== null && !is_string($data['delivery_status']))
            || ($data['failure'] !== null && !is_string($data['failure']))
        ) {
            throw new DomainException('Audit grant outcome requires its complete current representation.');
        }

        return new self(
            $data['grant_before'] === null ? null : AuditGrantState::fromArray($data['grant_before']),
            AuditGrantState::fromArray($data['grant_after']),
            $data['delivery_status'] === null ? null : CredentialDeliveryStatus::from($data['delivery_status']),
            $data['failure'] === null ? null : CredentialDeliveryFailure::from($data['failure']),
            $data['replaced_predecessor'] === null ? null : AuditGrantState::fromArray($data['replaced_predecessor'])
        );
    }

    /**
     * Returns the actual predecessor grant or absence on initial issuance
     */
    public function getBefore(): ?AuditGrantState
    {
        return $this->before;
    }

    /**
     * Returns the actual successor grant relationship
     */
    public function getAfter(): AuditGrantState
    {
        return $this->after;
    }

    /**
     * Returns the persisted delivery status without provider diagnostics
     */
    public function getDeliveryStatus(): ?CredentialDeliveryStatus
    {
        return $this->deliveryStatus;
    }

    /**
     * Returns the retained predecessor after cancellation or its unchanged terminal state
     */
    public function getReplacedPredecessor(): ?AuditGrantState
    {
        return $this->replacedPredecessor;
    }

    /**
     * Returns bounded grant revision and delivery relationships
     *
     * @phpstan-return GrantOutcomePayload
     */
    public function toArray(): array
    {
        return [
            'grant_before'         => $this->before?->toArray(),
            'grant_after'          => $this->after->toArray(),
            'delivery_status'      => $this->deliveryStatus?->value,
            'failure'              => $this->failure?->value,
            'replaced_predecessor' => $this->replacedPredecessor?->toArray()
        ];
    }
}
