<?php

declare(strict_types=1);

namespace Fight\AccessControl\Adapter\Mcp\Task\Agent;

use Fight\AccessControl\Adapter\Mcp\Tool\Agent\AgentToolAvailability;
use Fight\AccessControl\Adapter\Mcp\Tool\Agent\AgentToolPermissionCatalog;
use Fight\AccessControl\Application\AccessControl\Agent\Authentication\CurrentAgentPrincipalProvider;
use Fight\AccessControl\Application\AccessControl\Agent\Authentication\SignedAgentRequest;
use Fight\Common\Adapter\Messaging\Command\Sync\Routing\InMemoryCommandRouter;
use Fight\Common\Adapter\Messaging\Command\Sync\RoutingCommandBus;
use Fight\Common\Adapter\Messaging\Query\Routing\InMemoryQueryRouter;
use Fight\Common\Adapter\Messaging\Query\RoutingQueryBus;
use Fight\Common\Application\Mcp\Capability\McpCapabilityRegistry;
use Fight\Common\Application\Mcp\McpServerInfo;
use Fight\Common\Application\Mcp\Task\CommandHandler\CreateTaskHandler;
use Fight\Common\Application\Mcp\Task\CommandHandler\RequestTaskCancellationHandler;
use Fight\Common\Application\Mcp\Task\CommandHandler\SupplyTaskInputHandler;
use Fight\Common\Application\Mcp\Task\McpTasks;
use Fight\Common\Application\Mcp\Task\QueryHandler\GetTaskHandler;
use Fight\Common\Application\Mcp\Tool\Interaction\McpToolInteraction;
use Fight\Common\Application\Mcp\Tool\McpToolDiscovery;
use Fight\Common\Application\Mcp\Tool\McpToolInvocation;
use Fight\Common\Application\Mcp\Tool\McpToolInvoker;
use Fight\Common\Application\Mcp\Tool\McpToolRegistry;
use Fight\Common\Application\Messaging\Command\CommandBus;
use Fight\Common\Application\Messaging\Query\QueryBus;
use SensitiveParameter;

/**
 * Class AgentTaskServing
 *
 * Request-scoped explicit composition; handlers carry independent Agent admissions and consumer scope.
 * This object neither submits consumer work nor infers grants from the Tool catalog or Task handles.
 */
final readonly class AgentTaskServing
{
    private CommandBus $commandBus;

    private QueryBus $queryBus;

    private McpTasks $tasks;

    /**
     * Constructs AgentTaskServing
     *
     * All four real serving suppliers are mandatory. Configure the read handler with the same
     * AgentTaskRequestAdmission used by the guarded endpoint for independent response purposes.
     */
    public function __construct(
        CreateTaskHandler $createTaskHandler,
        GetTaskHandler $getTaskHandler,
        SupplyTaskInputHandler $supplyTaskInputHandler,
        RequestTaskCancellationHandler $requestTaskCancellationHandler,
        string $scope,
        string $caller,
        int $maxResponseBytes = McpTasks::MAX_RESPONSE_BYTES
    ) {
        $commandRouter = new InMemoryCommandRouter();
        foreach ([$createTaskHandler, $supplyTaskInputHandler, $requestTaskCancellationHandler] as $handler) {
            $commandRouter->registerHandler($handler::commandRegistration(), $handler);
        }

        $queryRouter = new InMemoryQueryRouter();
        $queryRouter->registerHandler($getTaskHandler::queryRegistration(), $getTaskHandler);

        $this->commandBus = new RoutingCommandBus($commandRouter);
        $this->queryBus = new RoutingQueryBus($queryRouter);
        $this->tasks = new McpTasks($this->queryBus, $this->commandBus, $scope, $caller, $maxResponseBytes);
    }

    /**
     * Returns the admitted creation and protocol mutation bus for consumer Task-capable Tools
     */
    public function commandBus(): CommandBus
    {
        return $this->commandBus;
    }

    /**
     * Returns the independently admitted complete-state Query bus
     */
    public function queryBus(): QueryBus
    {
        return $this->queryBus;
    }

    /**
     * Creates complete Common serving with identical Task capability and Tool definition identities
     *
     * Constructs the Permission catalog against this exact registry before advertising any capability.
     * Construct a fresh composition for every request; do not retain acknowledgment authority for later reads.
     *
     * @phpstan-param array<string, list<string>> $bindings
     */
    public function capabilities(
        McpServerInfo $serverInfo,
        McpToolRegistry $toolRegistry,
        CurrentAgentPrincipalProvider $principalProvider,
        #[SensitiveParameter] SignedAgentRequest $signedRequest,
        string $correlationId,
        string $discoverySecret,
        array $bindings = [],
        ?McpToolInteraction $interaction = null
    ): McpCapabilityRegistry {
        $availability = new AgentToolAvailability(
            new AgentToolPermissionCatalog($toolRegistry, $bindings),
            $principalProvider,
            $signedRequest,
            $correlationId
        );
        $invoker = new McpToolInvoker(
            $toolRegistry,
            $availability,
            interaction: $interaction,
            taskCapability: $this->tasks
        );

        return new McpCapabilityRegistry($serverInfo, [
            new McpToolDiscovery($toolRegistry, $availability, $discoverySecret),
            new McpToolInvocation($invoker),
            $this->tasks
        ]);
    }
}
