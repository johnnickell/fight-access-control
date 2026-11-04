<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\QueryHandler;

use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Maintenance\AgentMaintenanceAuthorization;
use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentCredentialOperationReadRepository;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationFailure;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Exception\AgentOperationRejectedException;
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
            $views = $this->operationReadRepository->listMaintenance(
                $query->getScope(),
                $query->getDestination(),
                $query->getWork(),
                $this->clock->now(),
                $query->getPolicy(),
                $query->getAfter()
            );
            $this->maintenanceAuthorization->authorizeRead(
                $query->getScope(),
                $query->getDestination(),
                null,
                $this->clock->now()
            );
            if (count($views) > $query->getPolicy()->getBatchSize()) {
                throw new AgentOperationRejectedException(AgentOperationFailure::UNAVAILABLE);
            }

            $cursor = $query->getAfter()?->toString();
            foreach ($views as $view) {
                $issuance = $view->getIssuance();
                if (
                    $issuance === null || $view->getKey()->getScope()->toString() !== $query->getScope()->toString()
                    || ($cursor !== null && strcmp($issuance->getDeliveryId()->toString(), $cursor) <= 0)
                ) {
                    throw new AgentOperationRejectedException(AgentOperationFailure::UNAVAILABLE);
                }

                $view->assertReadable($view->getKey(), $query->getDestination());
                $this->maintenanceAuthorization->authorizeRead(
                    $query->getScope(),
                    $query->getDestination(),
                    $issuance,
                    $this->clock->now()
                );
                $cursor = $issuance->getDeliveryId()->toString();
            }

            return $views;
        } catch (Throwable $throwable) {
            $reason = AgentOperationFailure::UNAVAILABLE;
            if (
                $throwable instanceof AgentOperationRejectedException
                && $throwable->getReason() === AgentOperationFailure::UNAUTHORIZED
            ) {
                $reason = AgentOperationFailure::UNAUTHORIZED;
            }

            throw new AgentOperationRejectedException($reason);
        }
    }
}
