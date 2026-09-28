<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\QueryHandler;

use Fight\AccessControl\Application\AccessControl\Agent\Service\AgentDeliveryAuthorization;
use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentOperationRejectedException;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationFailure;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationRepository;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\AgentOperationView;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\ListDueAgentDeliveries;
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
        private AgentOperationRepository $operations,
        private AgentDeliveryAuthorization $authorization,
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
            $this->authorization->authorizeDiscovery(
                $query->getScope(),
                $query->getDestination(),
                null,
                $this->clock->now()
            );
            $views = $this->operations->listDueDeliveries(
                $query->getScope(),
                $query->getDestination(),
                $this->clock->now(),
                $query->getLimit()
            );
            $this->authorization->authorizeDiscovery(
                $query->getScope(),
                $query->getDestination(),
                null,
                $this->clock->now()
            );
            if (count($views) > $query->getLimit()) {
                throw new AgentOperationRejectedException(AgentOperationFailure::UNAVAILABLE);
            }

            foreach ($views as $view) {
                if (
                    !$view->isConfirmed()
                    || $view->getKey()->getScope()->toString() !== $query->getScope()->toString()
                ) {
                    throw new AgentOperationRejectedException(AgentOperationFailure::UNAVAILABLE);
                }

                $view->assertReadable($view->getKey(), $query->getDestination());
                $this->authorization->authorizeDiscovery(
                    $query->getScope(),
                    $query->getDestination(),
                    $view->getIssuance(),
                    $this->clock->now()
                );
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
