<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent\Query;

use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentOperationRejectedException;
use Fight\AccessControl\Domain\AccessControl\Agent\Maintenance\AgentMaintenancePolicy;
use Fight\AccessControl\Domain\AccessControl\Agent\Maintenance\AgentMaintenanceWork;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentCredentialDestination;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentDeliveryId;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationFailure;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationScope;
use Fight\Common\Domain\Messaging\Query\Query;
use SensitiveParameter;
use Throwable;

/**
 * Class ListAgentDeliveryMaintenance
 */
final readonly class ListAgentDeliveryMaintenance implements Query
{
    /**
     * Constructs ListAgentDeliveryMaintenance
     */
    public function __construct(
        private AgentOperationScope $scope,
        private AgentCredentialDestination $destination,
        private AgentMaintenanceWork $work = AgentMaintenanceWork::MATERIAL,
        private AgentMaintenancePolicy $policy = new AgentMaintenancePolicy(),
        private ?AgentDeliveryId $after = null
    ) {
    }

    /**
     * Creates a request from the complete canonical safe representation
     *
     * @throws AgentOperationRejectedException When required data is absent or invalid
     */
    public static function fromArray(#[SensitiveParameter] array $data): static
    {
        try {
            foreach (['namespace', 'caller_type', 'caller_id', 'work'] as $field) {
                if (!isset($data[$field]) || !is_string($data[$field])) {
                    throw new AgentOperationRejectedException(AgentOperationFailure::INVALID_REQUEST);
                }
            }

            if (
                !isset($data['batch_size'], $data['cleanup_grace_seconds'])
                || !is_int($data['batch_size']) || !is_int($data['cleanup_grace_seconds'])
                || !array_key_exists('after', $data) || ($data['after'] !== null && !is_string($data['after']))
            ) {
                throw new AgentOperationRejectedException(AgentOperationFailure::INVALID_REQUEST);
            }

            return new static(
                new AgentOperationScope($data['namespace'], $data['caller_type'], $data['caller_id']),
                AgentCredentialDestination::fromArray($data),
                AgentMaintenanceWork::from($data['work']),
                new AgentMaintenancePolicy($data['batch_size'], $data['cleanup_grace_seconds']),
                $data['after'] === null ? null : AgentDeliveryId::fromString($data['after'])
            );
        } catch (Throwable) {
            throw new AgentOperationRejectedException(AgentOperationFailure::INVALID_REQUEST);
        }
    }

    /**
     * @inheritDoc
     */
    public function toArray(): array
    {
        return [
            'namespace'   => $this->scope->getNamespace(),
            'caller_type' => $this->scope->getCallerType(),
            'caller_id'   => $this->scope->getCallerId(),
            'work'        => $this->work->value,
            'after'       => $this->after?->toString()
        ] + $this->destination->toArray() + $this->policy->toArray();
    }

    /**
     * Returns the original authorized scope
     */
    public function getScope(): AgentOperationScope
    {
        return $this->scope;
    }

    /**
     * Returns the exact historical destination
     */
    public function getDestination(): AgentCredentialDestination
    {
        return $this->destination;
    }

    /**
     * Returns the maintenance selection
     */
    public function getWork(): AgentMaintenanceWork
    {
        return $this->work;
    }

    /**
     * Returns bounded selection and cleanup settings
     */
    public function getPolicy(): AgentMaintenancePolicy
    {
        return $this->policy;
    }

    /**
     * Returns the exclusive cursor, not authorization to access that delivery
     */
    public function getAfter(): ?AgentDeliveryId
    {
        return $this->after;
    }
}
