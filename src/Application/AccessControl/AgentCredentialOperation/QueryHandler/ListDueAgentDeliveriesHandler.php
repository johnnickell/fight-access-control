<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\QueryHandler;

use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery\AgentDeliveryAuthorization;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Exception\AgentOperationReadFailed;
use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentCredentialDisposition;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentCredentialOperationReadRepository;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentDeliveryDisposition;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationFailure;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Exception\AgentOperationRejectedException;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Query\AgentDeliveryWorkView;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Query\AgentOperationView;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Query\ListDueAgentDeliveries;
use Fight\Common\Application\Messaging\Query\QueryHandler;
use Fight\Common\Domain\Messaging\Query\QueryMessage;
use Throwable;

/**
 * Class ListDueAgentDeliveriesHandler
 *
 * Reads bounded safe work without claims, material, mutations, commits or events.
 */
final readonly class ListDueAgentDeliveriesHandler implements QueryHandler
{
    /**
     * Constructs ListDueAgentDeliveriesHandler
     */
    public function __construct(
        private AgentCredentialOperationReadRepository $operationReadRepository,
        private AgentDeliveryAuthorization $deliveryAuthorization,
        private Clock $clock
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function queryRegistration(): string
    {
        return ListDueAgentDeliveries::class;
    }

    /**
     * Handles one currently authorized bounded discovery without partial unauthorized disclosure
     *
     * @return list<AgentOperationView>
     */
    public function handle(QueryMessage $queryMessage): array
    {
        /** @var ListDueAgentDeliveries $query */
        $query = $queryMessage->payload();
        try {
            $this->deliveryAuthorization->authorizeDiscovery(
                $query->getScope(),
                $query->getDestination(),
                null,
                $this->clock->now()
            );
            $this->operationReadRepository->getOperationContract()->assertCompatible();
            $selectedAt = $this->clock->now();
            $work = $this->operationReadRepository->listDueDeliveries(
                $query->getScope(),
                $query->getDestination(),
                $selectedAt,
                $query->getLimit()
            );
            $this->deliveryAuthorization->authorizeDiscovery(
                $query->getScope(),
                $query->getDestination(),
                null,
                $this->clock->now()
            );
            $this->validateWork($work, $query->getLimit());

            $views = [];
            $previous = null;
            $keys = [];
            $deliveryIds = [];
            foreach ($work as $item) {
                $view = $item->getOperation();
                $issuance = $view->getIssuance();
                $dueAt = $item->getDueAt();
                if (
                    $issuance === null || $view->getKey()->getScope()->toString() !== $query->getScope()->toString()
                    || $issuance->getDestination()->toArray() !== $query->getDestination()->toArray()
                    || !$item->hasMaterial() || $item->isSinkCleaned() || $dueAt === null || $dueAt > $selectedAt
                    || $dueAt < $issuance->getIssuedAt() || $dueAt > $item->getRetentionEnd()
                    || $item->getRetentionEnd() <= $issuance->getIssuedAt()
                    || $view->getCredentialDisposition() !== AgentCredentialDisposition::CURRENT
                    || !in_array($view->getDeliveryDisposition(), [
                        AgentDeliveryDisposition::PENDING, AgentDeliveryDisposition::RETRYABLE
                    ], true)
                    || $issuance->getDestinationWriteVersion() !== $item->getCurrentDestinationWriteVersion()
                ) {
                    throw new AgentOperationReadFailed();
                }

                $id = $issuance->getDeliveryId()->toString();
                $order = [$dueAt, $id];
                if (
                    ($previous !== null && ($dueAt < $previous[0]
                        || ($dueAt == $previous[0] && strcmp($id, $previous[1]) <= 0)))
                    || isset($keys[$view->getKey()->toString()]) || isset($deliveryIds[$id])
                ) {
                    throw new AgentOperationReadFailed();
                }

                $this->deliveryAuthorization->authorizeDiscovery(
                    $query->getScope(),
                    $query->getDestination(),
                    $issuance,
                    $this->clock->now()
                );
                $previous = $order;
                $keys[$view->getKey()->toString()] = true;
                $deliveryIds[$id] = true;
                $views[] = $view;
            }
        } catch (AgentOperationRejectedException $failure) {
            if (
                !in_array($failure->getReason(), [
                AgentOperationFailure::UNAUTHORIZED, AgentOperationFailure::UNAVAILABLE
                ], true)
            ) {
                throw new AgentOperationReadFailed();
            }

            throw new AgentOperationRejectedException($failure->getReason());
        } catch (Throwable) {
            throw new AgentOperationReadFailed();
        }

        foreach ($views as $view) {
            $view->assertReadable($view->getKey(), $query->getDestination());
        }

        return $views;
    }

    /**
     * Validates the dynamic native array boundary independently of the repository's static promise
     *
     * @param array<array-key, mixed> $work
     */
    private function validateWork(array $work, int $limit): void
    {
        if (!array_is_list($work) || count($work) > $limit) {
            throw new AgentOperationReadFailed();
        }

        foreach ($work as $item) {
            if (!$item instanceof AgentDeliveryWorkView) {
                throw new AgentOperationReadFailed();
            }
        }
    }
}
