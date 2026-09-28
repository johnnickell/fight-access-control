<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\QueryHandler;

use Fight\AccessControl\Application\AccessControl\Agent\Service\AgentOperationAuthorization;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentOperationRejectedException;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationFailure;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationRepository;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\AgentOperationView;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\GetAgentOperation;
use Fight\Common\Application\Messaging\Query\QueryHandler;
use Fight\Common\Domain\Messaging\Query\QueryMessage;
use Throwable;

/**
 * Class GetAgentOperationHandler
 *
 * Reads an authorized secret-free outcome without mutation, admission, commits or events.
 */
final readonly class GetAgentOperationHandler implements QueryHandler
{
    /**
     * Constructs GetAgentOperationHandler
     */
    public function __construct(
        private AgentOperationRepository $operationRepository,
        private AgentOperationAuthorization $authorization
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function queryRegistration(): string
    {
        return GetAgentOperation::class;
    }

    /**
     * @inheritDoc
     */
    public function handle(QueryMessage $queryMessage): AgentOperationView
    {
        /** @var GetAgentOperation $query */
        $query = $queryMessage->payload();
        try {
            $this->authorization->authorizeRead($query->getKey()->getScope(), $query->getDestination(), null);
            $view = $this->operationRepository->getStatusByKey($query->getKey());
            $view ??= AgentOperationView::indeterminate($query->getKey());
            $this->authorization->authorizeRead(
                $query->getKey()->getScope(),
                $query->getDestination(),
                $view->getIssuance()?->getAgentId()
            );
        } catch (Throwable $throwable) {
            $reason = AgentOperationFailure::UNAVAILABLE;
            if (
                $throwable instanceof AgentOperationRejectedException
                && $throwable->getReason() === AgentOperationFailure::UNAUTHORIZED
            ) {
                $reason = $throwable->getReason();
            }

            // Do not retain arbitrary provider exceptions, messages or trace arguments in public failures.
            throw new AgentOperationRejectedException($reason);
        }

        $view->assertReadable($query->getKey(), $query->getDestination());

        return $view;
    }
}
