<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\Security;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\Agent\QueryHandler\ListDueAgentDeliveriesHandler;
use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Domain\AccessControl\Agent\Delivery\AgentDeliverySchedule;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentCredentialDestination;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationScope;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\ListDueAgentDeliveries;
use Fight\Common\Domain\Messaging\Query\QueryMessage;

/**
 * Class AgentDeliveryRecoveryService
 *
 * Runs one bounded scheduler pass through the actual delivery path without events or caller retries.
 */
final readonly class AgentDeliveryRecoveryService
{
    /**
     * Constructs AgentDeliveryRecoveryService
     */
    public function __construct(
        private ListDueAgentDeliveriesHandler $discovery,
        private AgentCredentialDeliveryService $delivery,
        private Clock $clock,
        private AgentDeliverySchedule $schedule = new AgentDeliverySchedule()
    ) {
    }

    /**
     * Handles at most one batch of authorized original deliveries without repeating selection in this pass
     *
     * Storage and authorization failures from discovery propagate as sanitized operation failures, not empty work.
     * Delivery results remain independent; one rejected or unavailable attempt cannot trigger new issuance.
     *
     * @return array<string, AgentDeliveryResult>
     */
    public function recover(AgentOperationScope $scope, AgentCredentialDestination $destination): array
    {
        $views = $this->discovery->handle(QueryMessage::create(new ListDueAgentDeliveries(
            $scope,
            $destination,
            $this->schedule->getBatchSize()
        )));
        $results = [];
        foreach ($views as $view) {
            // Discovery guarantees confirmed issuance; no outcome grants admission or activation authority.
            $issuance = $view->getIssuance();
            if ($issuance !== null) {
                $results[$issuance->getDeliveryId()->toString()] = $this->delivery->deliver(
                    $issuance->getKey(),
                    $issuance->getDestination(),
                    $issuance->getDeliveryId()
                );
            }
        }

        return $results;
    }

    /**
     * Returns a bounded next polling time for the consumer scheduler without a process-local queue
     */
    public function nextRunAt(): DateTimeImmutable
    {
        return $this->schedule->nextRunAt($this->clock->now());
    }
}
