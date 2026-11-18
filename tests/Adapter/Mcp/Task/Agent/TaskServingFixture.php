<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Mcp\Task\Agent;

use Closure;
use Doctrine\DBAL\Connection;
use Fight\AccessControl\Adapter\Mcp\Task\Agent\AgentTaskRequestAdmission;
use Fight\AccessControl\Adapter\Mcp\Task\Agent\AgentTaskServing;
use Fight\AccessControl\Application\AccessControl\Agent\Task\AgentTaskCancellationAdmission;
use Fight\AccessControl\Application\AccessControl\Agent\Task\AgentTaskCreationAdmission;
use Fight\AccessControl\Application\AccessControl\Agent\Task\AgentTaskReadAdmission;
use Fight\Common\Adapter\Mcp\Http\DenyAllMcpOriginPolicy;
use Fight\Common\Adapter\Mcp\Http\McpRequestHandler;
use Fight\Common\Adapter\Mcp\Http\McpResponseFactory;
use Fight\Common\Adapter\Mcp\Persistence\Task\Dbal\DbalTaskRepository;
use Fight\Common\Adapter\Mcp\Persistence\Task\Dbal\DbalTaskTransaction;
use Fight\Common\Adapter\Messaging\Event\Sync\SimpleEventDispatcher;
use Fight\Common\Application\Mcp\Capability\McpCapabilityRegistry;
use Fight\Common\Application\Mcp\Http\McpInvocationGuard;
use Fight\Common\Application\Mcp\McpDiagnostics;
use Fight\Common\Application\Mcp\McpResponder;
use Fight\Common\Application\Mcp\Protocol\McpRequestMetadata;
use Fight\Common\Application\Mcp\Protocol\McpServerInfo;
use Fight\Common\Application\Mcp\Task\CommandHandler\CreateTaskHandler;
use Fight\Common\Application\Mcp\Task\CommandHandler\RequestTaskCancellationHandler;
use Fight\Common\Application\Mcp\Task\CommandHandler\SupplyTaskInputHandler;
use Fight\Common\Application\Mcp\Task\McpTasks;
use Fight\Common\Application\Mcp\Task\QueryHandler\GetTaskHandler;
use Fight\Common\Application\Mcp\Task\Service\CommittedTaskReader;
use Fight\Common\Application\Mcp\Task\Service\ToolTaskPayloadPolicy;
use Fight\Common\Application\Mcp\Tool\McpToolRegistry;
use Fight\Common\Domain\Mcp\Task\Event\TaskChanged;
use Fight\Common\Domain\Utility\ClassName;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Task\TaskCancellationFixture;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Task\TaskInputAdmissionFixture;
use RuntimeException;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use stdClass;
use Throwable;

final readonly class TaskServingFixture
{
    public const array GRANTS = [...TaskInputAdmissionFixture::GRANTS, 'TASK_CANCEL', 'TOOL_START', 'TASK_ACK'];

    public TaskCancellationFixture $lifecycle;

    public AgentTaskServing $serving;

    public AgentTaskRequestAdmission $admission;

    public TaskStartTool $tool;

    public McpCapabilityRegistry $capabilities;

    public McpToolRegistry $registry;

    /** @param list<string> $grants */
    public function __construct(
        Connection $connection,
        array $grants = self::GRANTS,
        int $maxResponseBytes = McpTasks::MAX_RESPONSE_BYTES,
        ?Closure $submit = null,
        bool $publicationFails = false,
        ?CommittedTaskReader $reader = null,
        ?McpInvocationGuard $guard = null,
        ?Closure $readFactory = null,
        ?Closure $subscriptionsFactory = null
    ) {
        $this->lifecycle = new TaskCancellationFixture($connection, $grants);
        $auth = $this->lifecycle->input->auth;
        $transaction = new DbalTaskTransaction($connection);
        $repository = new DbalTaskRepository($transaction);
        $reader ??= $repository;
        $payload = new ToolTaskPayloadPolicy(static fn(): bool => true);
        $clock = fn(): int => $this->lifecycle->now;
        $dispatcher = new SimpleEventDispatcher();
        if ($publicationFails) {
            $dispatcher->addHandler(ClassName::underscore(TaskChanged::class), static function (): void {
                throw new RuntimeException('Private publisher failure.');
            });
        }

        $guard ??= new class implements McpInvocationGuard {
            public function allows(string $method, ?string $name): bool
            {
                return true;
            }
        };
        $stateAdmission = new AgentTaskReadAdmission($auth->authority('TASK_READ'), $auth);
        if ($readFactory !== null) {
            $stateAdmission = $readFactory($auth);
        }

        $this->admission = new AgentTaskRequestAdmission(
            $guard,
            new AgentTaskReadAdmission($auth->authority('TASK_ACK'), $auth),
            $stateAdmission
        );
        $intent = new AgentTaskCancellationAdmission(
            $auth->authority('TASK_CANCEL'),
            $this->lifecycle,
            $auth->authority('INTENT_OBSERVE'),
            $this->lifecycle->input
        );
        $this->serving = new AgentTaskServing(
            new CreateTaskHandler(
                $repository,
                $transaction,
                new AgentTaskCreationAdmission($auth->authority('TASK_CREATE'), $auth),
                $payload,
                $dispatcher,
                $clock
            ),
            new GetTaskHandler(
                $reader,
                $this->admission,
                $payload,
                $clock
            ),
            new SupplyTaskInputHandler(
                $repository,
                $transaction,
                $this->lifecycle->input->input(),
                $payload,
                $dispatcher,
                $clock
            ),
            new RequestTaskCancellationHandler($repository, $transaction, $reader, $intent, $dispatcher, $clock),
            'scope',
            'caller',
            $maxResponseBytes
        );
        $this->tool = new TaskStartTool($this->serving->commandBus(), $submit);
        $this->registry = new McpToolRegistry([$this->tool]);
        $this->capabilities = $this->serving->capabilities(
            new McpServerInfo('Task fixture', '1.0.0'),
            $this->registry,
            $auth->provider,
            $auth->request,
            'task-serving',
            str_repeat('d', 32),
            ['example.operation' => ['TOOL_START']],
            subscriptions: $subscriptionsFactory?->__invoke($this->serving, $auth, $this->registry)
        );
    }

    /** @param array<string, mixed> $parameters */
    public static function request(string $method, array $parameters = [], bool $support = true): string
    {
        return json_encode([
            'jsonrpc' => '2.0',
            'id'      => 'request-1',
            'method'  => $method,
            'params'  => [
                ...$parameters,
                '_meta' => [
                    McpRequestMetadata::PROTOCOL_VERSION_KEY    => McpResponder::PROTOCOL_VERSION,
                    McpRequestMetadata::CLIENT_CAPABILITIES_KEY => $support ? [
                            'extensions'  => [McpTasks::EXTENSION => new stdClass()],
                            'elicitation' => ['form' => new stdClass()]
                        ] : new stdClass()
                ]
            ]
        ], JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $parameters
     * @return array<string, mixed>
     */
    public function call(string $method, array $parameters = [], bool $support = true): array
    {
        $streamFactory = new StreamFactory();
        $request = new ServerRequestFactory()->createServerRequest('POST', 'https://task.example/mcp')
            ->withHeader('Accept', 'application/json, text/event-stream')
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('MCP-Protocol-Version', McpResponder::PROTOCOL_VERSION)
            ->withHeader('MCP-Method', $method)
            ->withBody($streamFactory->createStream(self::request($method, $parameters, $support)));
        if ($method === 'tools/call' || str_starts_with($method, 'tasks/')) {
            $request = $request->withHeader('MCP-Name', $parameters['name'] ?? $parameters['taskId'] ?? '');
        }

        $handler = new McpRequestHandler(
            $this->capabilities,
            new DenyAllMcpOriginPolicy(),
            $this->admission,
            new McpResponseFactory(new ResponseFactory(), $streamFactory, new class implements McpDiagnostics {
                public function record(Throwable $failure): void
                {
                }
            })
        );

        return json_decode((string) $handler->handle($request)->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }
}
