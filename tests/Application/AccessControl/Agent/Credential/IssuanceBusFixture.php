<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Credential;

use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\AgentOperationAuthorization;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\QueryHandler\GetAgentOperationHandler;
use Fight\AccessControl\Domain\AccessControl\Agent\Command\ProvisionAgent;
use Fight\AccessControl\Domain\AccessControl\Agent\Command\RotateAgentCredential;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentCredentialOperationReadRepository;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Query\AgentOperationView;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Query\GetAgentOperation;
use Fight\Common\Adapter\Messaging\Command\Sync\Routing\InMemoryCommandRouter;
use Fight\Common\Adapter\Messaging\Command\Sync\RoutingCommandBus;
use Fight\Common\Adapter\Messaging\Query\Routing\InMemoryQueryRouter;
use Fight\Common\Adapter\Messaging\Query\RoutingQueryBus;
use Fight\Common\Application\Messaging\Command\CommandHandler;
use Fight\Common\Application\Messaging\Command\SynchronousCommandBus;
use Fight\Common\Application\Messaging\Query\QueryBus;

/** Shared package-bus composition for business fixtures, not an alternative issuance implementation */
final readonly class IssuanceBusFixture
{
    public SynchronousCommandBus $commandBus;

    public QueryBus $queryBus;

    public function __construct(
        CommandHandler $handler,
        AgentCredentialOperationReadRepository $operationReadRepository,
        AgentOperationAuthorization $operationAuthorization
    ) {
        $commandRouter = new InMemoryCommandRouter();
        $commandRouter->registerHandler($handler::commandRegistration(), $handler);

        $this->commandBus = new RoutingCommandBus($commandRouter);
        $queryRouter = new InMemoryQueryRouter();
        $queryRouter->registerHandler(
            GetAgentOperationHandler::queryRegistration(),
            new GetAgentOperationHandler($operationReadRepository, $operationAuthorization)
        );
        $this->queryBus = new RoutingQueryBus($queryRouter);
    }

    /** Executes synchronously for fixture setup, then independently reads authoritative status through Common */
    public function dispatchAndRead(ProvisionAgent|RotateAgentCredential $command): AgentOperationView
    {
        $this->commandBus->execute($command);

        /** @var AgentOperationView $view Known matching GetAgentOperationHandler registration */
        $view = $this->queryBus->fetch(
            new GetAgentOperation($command->getKey(), $command->getRequest()->getDestination())
        );

        return $view;
    }
}
