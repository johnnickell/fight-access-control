<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent\Query;

use Fight\AccessControl\Domain\AccessControl\Agent\Delivery\AgentDeliverySchedule;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentOperationRejectedException;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentCredentialDestination;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationFailure;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationScope;
use Fight\Common\Domain\Messaging\Query\Query;
use SensitiveParameter;
use Throwable;

/**
 * Class ListDueAgentDeliveries
 *
 * Requests bounded original work under the real scheduler's current delegation, not caller impersonation.
 */
final readonly class ListDueAgentDeliveries implements Query
{
    /**
     * Constructs ListDueAgentDeliveries
     */
    public function __construct(
        private AgentOperationScope $scope,
        private AgentCredentialDestination $destination,
        private int $limit = 50
    ) {
        new AgentDeliverySchedule($limit);
    }

    /**
     * Creates a bounded discovery request from its canonical representation
     *
     * @throws AgentOperationRejectedException When required data is absent or invalid
     */
    public static function fromArray(#[SensitiveParameter] array $data): static
    {
        try {
            foreach (['namespace', 'caller_type', 'caller_id'] as $field) {
                if (!isset($data[$field]) || !is_string($data[$field])) {
                    throw new AgentOperationRejectedException(AgentOperationFailure::INVALID_REQUEST);
                }
            }

            if (!isset($data['limit']) || !is_int($data['limit'])) {
                throw new AgentOperationRejectedException(AgentOperationFailure::INVALID_REQUEST);
            }

            return new static(
                new AgentOperationScope($data['namespace'], $data['caller_type'], $data['caller_id']),
                AgentCredentialDestination::fromArray($data),
                $data['limit']
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
            'limit'       => $this->limit
        ] + $this->destination->toArray();
    }

    /**
     * Returns the original scope rather than the authenticated worker identity
     */
    public function getScope(): AgentOperationScope
    {
        return $this->scope;
    }

    /**
     * Returns the registered destination boundary for this bounded selection
     */
    public function getDestination(): AgentCredentialDestination
    {
        return $this->destination;
    }

    /**
     * Returns the maximum number of safe work items
     */
    public function getLimit(): int
    {
        return $this->limit;
    }
}
