<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\QueryHandler;

use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\AgentOperationAuthorization;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Exception\AgentOperationReadFailed;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentCredentialOperationReadRepository;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationFailure;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Exception\AgentOperationRejectedException;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Query\AgentOperationView;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Query\GetAgentOperation;
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
        private AgentCredentialOperationReadRepository $operationReadRepository,
        private AgentOperationAuthorization $operationAuthorization
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
            $this->operationAuthorization->authorizeRead($query->getKey()->getScope(), $query->getDestination(), null);
            $view = $this->operationReadRepository->getStatusByKey($query->getKey());
            $view ??= AgentOperationView::indeterminate($query->getKey());
            $this->operationAuthorization->authorizeRead(
                $query->getKey()->getScope(),
                $query->getDestination(),
                $view->getIssuance()?->getAgentId()
            );
            if ($view->getKey()->toString() !== $query->getKey()->toString()) {
                throw new AgentOperationReadFailed();
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

        // A key-only lookup may legitimately find another destination binding: conceal it.
        // Check the marker only after target authorization, never from a provider's failed read.
        $view->assertReadable($query->getKey(), $query->getDestination());

        return $view;
    }
}
