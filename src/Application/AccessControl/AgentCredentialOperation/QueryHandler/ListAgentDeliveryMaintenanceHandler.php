<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\QueryHandler;

use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Exception\AgentOperationReadFailed;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Maintenance\AgentMaintenanceAuthorization;
use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentCredentialDisposition;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentCredentialOperationReadRepository;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentDeliveryDisposition;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationFailure;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Exception\AgentOperationRejectedException;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Maintenance\AgentMaintenanceWork;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Query\AgentDeliveryWorkView;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Query\AgentOperationView;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Query\ListAgentDeliveryMaintenance;
use Fight\Common\Application\Messaging\Query\QueryHandler;
use Fight\Common\Domain\Messaging\Query\QueryMessage;
use Throwable;

/**
 * Class ListAgentDeliveryMaintenanceHandler
 */
final readonly class ListAgentDeliveryMaintenanceHandler implements QueryHandler
{
    /**
     * Constructs ListAgentDeliveryMaintenanceHandler
     */
    public function __construct(
        private AgentCredentialOperationReadRepository $operationReadRepository,
        private AgentMaintenanceAuthorization $maintenanceAuthorization,
        private Clock $clock
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function queryRegistration(): string
    {
        return ListAgentDeliveryMaintenance::class;
    }

    /**
     * Returns one authorized secret-free page without partial disclosure or query-time transitions
     *
     * @return list<AgentOperationView>
     */
    public function handle(QueryMessage $queryMessage): array
    {
        /** @var ListAgentDeliveryMaintenance $query */
        $query = $queryMessage->payload();
        try {
            $this->maintenanceAuthorization->authorizeRead(
                $query->getScope(),
                $query->getDestination(),
                null,
                $this->clock->now()
            );
            $this->operationReadRepository->getOperationContract()->assertCompatible();
            $selectedAt = $this->clock->now();
            $work = $this->operationReadRepository->listMaintenance(
                $query->getScope(),
                $query->getDestination(),
                $query->getWork(),
                $selectedAt,
                $query->getPolicy(),
                $query->getAfter()
            );
            $this->maintenanceAuthorization->authorizeRead(
                $query->getScope(),
                $query->getDestination(),
                null,
                $this->clock->now()
            );
            $this->validateWork($work, $query->getPolicy()->getBatchSize());

            $views = [];
            $keys = [];
            $cursor = $query->getAfter()?->toString();
            foreach ($work as $item) {
                $view = $item->getOperation();
                $issuance = $view->getIssuance();
                if (
                    $issuance === null || $view->getKey()->getScope()->toString() !== $query->getScope()->toString()
                    || $issuance->getDestination()->toArray() !== $query->getDestination()->toArray()
                    || ($cursor !== null && strcmp($issuance->getDeliveryId()->toString(), $cursor) <= 0)
                    || isset($keys[$view->getKey()->toString()])
                    || $item->getRetentionEnd() <= $issuance->getIssuedAt()
                ) {
                    throw new AgentOperationReadFailed();
                }

                if ($query->getWork() === AgentMaintenanceWork::MATERIAL) {
                    if (!$item->hasMaterial()) {
                        throw new AgentOperationReadFailed();
                    }
                } elseif (
                    $item->hasMaterial() || $item->isSinkCleaned()
                    || in_array($view->getDeliveryDisposition(), [
                        AgentDeliveryDisposition::PENDING, AgentDeliveryDisposition::RETRYABLE
                    ], true)
                    || ($view->getDeliveryDisposition() === AgentDeliveryDisposition::DELIVERED
                        && $view->getCredentialDisposition() === AgentCredentialDisposition::CURRENT)
                    || $selectedAt < $query->getPolicy()->cleanupAfter($item->getRetentionEnd())
                ) {
                    throw new AgentOperationReadFailed();
                }

                $this->maintenanceAuthorization->authorizeRead(
                    $query->getScope(),
                    $query->getDestination(),
                    $issuance,
                    $this->clock->now()
                );
                $cursor = $issuance->getDeliveryId()->toString();
                $keys[$view->getKey()->toString()] = true;
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
