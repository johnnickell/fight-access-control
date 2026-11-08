<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Exception\AgentOperationReadFailed;
use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentCredentialDestination;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationFailure;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationScope;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Delivery\AgentDeliverySchedule;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Exception\AgentOperationRejectedException;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Query\AgentOperationView;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Query\ListDueAgentDeliveries;
use Fight\Common\Application\Messaging\Query\QueryBus;
use Throwable;

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
        private QueryBus $queryBus,
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
        try {
            $views = $this->queryBus->fetch(new ListDueAgentDeliveries(
                $scope,
                $destination,
                $this->schedule->getBatchSize()
            ));
        } catch (AgentOperationRejectedException $failure) {
            if (
                !in_array($failure->getReason(), [
                AgentOperationFailure::UNAUTHORIZED,
                AgentOperationFailure::UNAVAILABLE,
                AgentOperationFailure::UNSUPPORTED_VERSION
                ], true)
            ) {
                throw new AgentOperationReadFailed();
            }

            throw new AgentOperationRejectedException($failure->getReason());
        } catch (Throwable) {
            throw new AgentOperationReadFailed();
        }

        // Common currently returns mixed. Validate this one worker boundary, not a generic inference claim.
        if (!is_array($views) || !array_is_list($views) || count($views) > $this->schedule->getBatchSize()) {
            throw new AgentOperationReadFailed();
        }

        $issuances = [];
        $ids = [];
        foreach ($views as $view) {
            if (!$view instanceof AgentOperationView) {
                throw new AgentOperationReadFailed();
            }

            $issuance = $view->getIssuance();
            if (
                $issuance === null || $issuance->getKey()->getScope()->toString() !== $scope->toString()
                || $issuance->getDestination()->toArray() !== $destination->toArray()
                || isset($ids[$issuance->getDeliveryId()->toString()])
            ) {
                throw new AgentOperationReadFailed();
            }

            $view->assertReadable($issuance->getKey(), $destination);
            $ids[$issuance->getDeliveryId()->toString()] = true;
            $issuances[] = $issuance;
        }

        $results = [];
        foreach ($issuances as $issuance) {
            $results[$issuance->getDeliveryId()->toString()] = $this->delivery->deliver(
                $issuance->getKey(),
                $issuance->getDestination(),
                $issuance->getDeliveryId()
            );
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
