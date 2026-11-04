<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\QueryHandler;

use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Maintenance\AgentMaintenanceAuthorization;
use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentCredentialOperationReadRepository;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationFailure;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Exception\AgentOperationRejectedException;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Query\CountAgentDeliveryKeyReferences;
use Fight\Common\Application\Messaging\Query\QueryHandler;
use Fight\Common\Domain\Messaging\Query\QueryMessage;
use Throwable;

/**
 * Class CountAgentDeliveryKeyReferencesHandler
 */
final readonly class CountAgentDeliveryKeyReferencesHandler implements QueryHandler
{
    /**
     * Constructs CountAgentDeliveryKeyReferencesHandler
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
        return CountAgentDeliveryKeyReferences::class;
    }

    /**
     * Returns a currently authorized complete diagnostic count without opening a transaction
     */
    public function handle(QueryMessage $queryMessage): int
    {
        /** @var CountAgentDeliveryKeyReferences $query */
        $query = $queryMessage->payload();
        try {
            $this->maintenanceAuthorization->authorizeKeyAccounting($query->getVersion(), $this->clock->now());
            $this->operationReadRepository->getOperationContract()->assertCompatible();
            $count = $this->operationReadRepository->countDeliveryKeyReferences($query->getVersion());
            $this->maintenanceAuthorization->authorizeKeyAccounting($query->getVersion(), $this->clock->now());
            if ($count < 0) {
                throw new AgentOperationRejectedException(AgentOperationFailure::UNAVAILABLE);
            }

            return $count;
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
