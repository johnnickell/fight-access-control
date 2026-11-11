<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Task;

use Closure;
use Doctrine\DBAL\Connection;
use Fight\AccessControl\Application\AccessControl\Agent\Task\AgentTaskCancellationAdmission;
use Fight\AccessControl\Application\AccessControl\Agent\Task\AgentTaskCancellationScope;
use Fight\AccessControl\Application\AccessControl\Agent\Task\AgentTaskCreationAdmission;
use Fight\AccessControl\Application\AccessControl\Agent\Task\AgentTaskReadAdmission;
use Fight\AccessControl\Application\AccessControl\Agent\Task\AgentTaskUpdateAdmission;
use Fight\AccessControl\Domain\AccessControl\Agent\AuthenticatedAgentPrincipal;
use Fight\Common\Adapter\Mcp\Persistence\Task\Dbal\DbalTaskRepository;
use Fight\Common\Adapter\Mcp\Persistence\Task\Dbal\DbalTaskTransaction;
use Fight\Common\Adapter\Messaging\Command\Sync\Routing\InMemoryCommandRouter;
use Fight\Common\Adapter\Messaging\Command\Sync\RoutingCommandBus;
use Fight\Common\Adapter\Messaging\Event\Sync\SimpleEventDispatcher;
use Fight\Common\Adapter\Messaging\Query\Routing\InMemoryQueryRouter;
use Fight\Common\Adapter\Messaging\Query\RoutingQueryBus;
use Fight\Common\Application\Mcp\Task\CommandHandler\AcquireTaskInputHandler;
use Fight\Common\Application\Mcp\Task\CommandHandler\CreateTaskHandler;
use Fight\Common\Application\Mcp\Task\CommandHandler\RecordTaskStateHandler;
use Fight\Common\Application\Mcp\Task\CommandHandler\RequestTaskCancellationHandler;
use Fight\Common\Application\Mcp\Task\CommandHandler\RequestTaskInputHandler;
use Fight\Common\Application\Mcp\Task\CommandHandler\RetireTaskInputHandler;
use Fight\Common\Application\Mcp\Task\CommandHandler\SupplyTaskInputHandler;
use Fight\Common\Application\Mcp\Task\QueryHandler\GetTaskCancellationHandler;
use Fight\Common\Application\Mcp\Task\QueryHandler\GetTaskHandler;
use Fight\Common\Application\Mcp\Task\QueryHandler\GetTaskInputHandler;
use Fight\Common\Application\Mcp\Task\Service\CommittedTaskReader;
use Fight\Common\Application\Mcp\Task\Service\TaskInputForms;
use Fight\Common\Application\Mcp\Task\Service\ToolTaskPayloadPolicy;
use Fight\Common\Application\Mcp\Tool\Interaction\McpInputRequest;
use Fight\Common\Application\Mcp\Tool\Interaction\McpInputRequired;
use Fight\Common\Domain\Mcp\Task\Command\AcquireTaskInput;
use Fight\Common\Domain\Mcp\Task\Command\CreateTask;
use Fight\Common\Domain\Mcp\Task\Command\RecordTaskState;
use Fight\Common\Domain\Mcp\Task\Command\RequestTaskCancellation;
use Fight\Common\Domain\Mcp\Task\Command\RequestTaskInput;
use Fight\Common\Domain\Mcp\Task\Command\SupplyTaskInput;
use Fight\Common\Domain\Mcp\Task\Event\TaskChanged;
use Fight\Common\Domain\Mcp\Task\Query\GetTaskCancellation;
use Fight\Common\Domain\Mcp\Task\Query\GetTaskInput;
use Fight\Common\Domain\Mcp\Task\Task;
use Fight\Common\Domain\Mcp\Task\TaskId;
use Fight\Common\Domain\Mcp\Task\TaskState;
use Fight\Common\Domain\Utility\ClassName;
use Fight\Common\Domain\Value\Basic\StrictJson;
use RuntimeException;

final class TaskCancellationFixture implements AgentTaskCancellationScope
{
    public int $now = 1794312000000;

    public bool $allowed = true;

    public bool $fault = false;

    public bool $publicationFails = false;

    public int $checks = 0;

    public int $events = 0;

    public ?Closure $beforeCancellation = null;

    public readonly TaskInputAdmissionFixture $input;

    public readonly DbalTaskRepository $repository;

    public readonly RoutingCommandBus $commandBus;

    public readonly RoutingQueryBus $queryBus;

    /** @param list<string> $grants */
    public function __construct(
        public readonly Connection $connection,
        array $grants = [...TaskInputAdmissionFixture::GRANTS, 'TASK_CANCEL'],
        ?CommittedTaskReader $committedReader = null
    ) {
        $this->input = new TaskInputAdmissionFixture($grants);
        $auth = $this->input->auth;
        $transaction = new DbalTaskTransaction($connection);
        $this->repository = new DbalTaskRepository($transaction);
        $committedReader ??= $this->repository;
        $payload = new ToolTaskPayloadPolicy(static fn(): bool => true);
        $clock = fn(): int => $this->now;
        $eventDispatcher = new SimpleEventDispatcher();
        $eventDispatcher->addHandler(ClassName::underscore(TaskChanged::class), function (): void {
            ++$this->events;
            if ($this->publicationFails) {
                throw new RuntimeException('Private publisher cause.');
            }
        });
        $intent = new AgentTaskCancellationAdmission(
            $auth->authority('TASK_CANCEL'),
            $this,
            $auth->authority('INTENT_OBSERVE'),
            $this->input
        );
        $issuer = new AgentTaskUpdateAdmission($auth->authority('INPUT_ISSUE'), $auth);
        $input = $this->input->input();
        $router = new InMemoryCommandRouter();
        foreach (
            [
                new CreateTaskHandler(
                    $this->repository,
                    $transaction,
                    new AgentTaskCreationAdmission($auth->authority('TASK_CREATE'), $auth),
                    $payload,
                    $eventDispatcher,
                    $clock
                ),
                new RecordTaskStateHandler(
                    $this->repository,
                    $transaction,
                    new AgentTaskUpdateAdmission($auth->authority('TASK_UPDATE'), $auth),
                    $payload,
                    $eventDispatcher,
                    $clock
                ),
                new RequestTaskInputHandler(
                    $this->repository,
                    $transaction,
                    $issuer,
                    $payload,
                    $eventDispatcher,
                    $clock
                ),
                new RetireTaskInputHandler(
                    $this->repository,
                    $transaction,
                    $issuer,
                    $payload,
                    $eventDispatcher,
                    $clock
                ),
                new SupplyTaskInputHandler($this->repository, $transaction, $input, $payload, $eventDispatcher, $clock),
                new AcquireTaskInputHandler(
                    $this->repository,
                    $transaction,
                    $input,
                    $intent,
                    $payload,
                    $eventDispatcher,
                    $clock
                ),
                new RequestTaskCancellationHandler(
                    $this->repository,
                    $transaction,
                    $committedReader,
                    $intent,
                    $eventDispatcher,
                    $clock
                )
            ] as $handler
        ) {
            $router->registerHandler($handler::commandRegistration(), $handler);
        }

        $queries = new InMemoryQueryRouter();
        foreach (
            [
                new GetTaskHandler(
                    $committedReader,
                    new AgentTaskReadAdmission($auth->authority('TASK_READ'), $auth),
                    $payload,
                    $clock
                ),
                new GetTaskCancellationHandler($committedReader, $intent, $clock),
                new GetTaskInputHandler($committedReader, $input, $intent, $clock)
            ] as $handler
        ) {
            $queries->registerHandler($handler::queryRegistration(), $handler);
        }

        $this->commandBus = new RoutingCommandBus($router);
        $this->queryBus = new RoutingQueryBus($queries);
    }

    public static function id(): TaskId
    {
        return TaskId::fromString(TaskAdmissionFixture::ID);
    }

    public function allowsCancellation(
        AuthenticatedAgentPrincipal $principal,
        string $scope,
        TaskId $id,
        string $caller
    ): bool {
        ++$this->checks;
        if ($this->beforeCancellation !== null) {
            ($this->beforeCancellation)();
        }

        if ($this->fault) {
            throw new RuntimeException('Private cancellation scope cause.');
        }

        return $this->allowed && $principal->getAgentId()->equals($this->input->auth->agent->getId())
            && $scope === 'scope' && $id->equals(self::id()) && $caller === 'caller';
    }

    public function create(?int $ttl = 10000): void
    {
        $this->commandBus->execute(new CreateTask(
            TaskAdmissionFixture::definition(),
            TaskState::create('working'),
            $ttl
        ));
    }

    /** @return list<string> */
    public function issue(bool $confirmation = true): array
    {
        $form = McpInputRequest::form('Approve', [
            'type' => 'object', 'properties' => (object) ['ok' => (object) ['type' => 'boolean']], 'required' => ['ok']
        ]);
        $input = McpInputRequired::fromRequests(['one' => $form]);
        if ($confirmation) {
            $input = McpInputRequired::confirmation(['one' => $form]);
        }

        $this->commandBus->execute(new RequestTaskInput(
            'scope',
            self::id(),
            'producer',
            $this->task()->version(),
            TaskInputForms::batch($input, 'caller', $this->now + 5000)
        ));

        return array_keys($this->task()->inputs()->active('caller', $this->now));
    }

    public function cancel(): void
    {
        $this->commandBus->execute(new RequestTaskCancellation('scope', self::id(), 'caller'));
    }

    public function supply(string $group): void
    {
        $this->commandBus->execute(new SupplyTaskInput('scope', self::id(), 'caller', StrictJson::fromObject([
            $group => (object) ['action' => 'accept', 'content' => (object) ['ok' => true]]
        ])));
    }

    public function acquire(string $group): void
    {
        $this->commandBus->execute(new AcquireTaskInput('scope', self::id(), 'caller', $group));
    }

    public function record(string $outcome, ?int $version = null): void
    {
        $state = match ($outcome) {
            'completed' => TaskState::create('completed', result: StrictJson::fromObject([
                'content' => [], 'structuredContent' => (object) ['ok' => true], 'isError' => false
            ])),
            'failed' => TaskState::create('failed', error: StrictJson::fromObject([
                'code' => -32603, 'message' => 'Internal error'
            ])),
            default => TaskState::create($outcome)
        };
        $this->commandBus->execute(new RecordTaskState(
            'scope',
            self::id(),
            'producer',
            $version ?? $this->task()->version(),
            $state
        ));
    }

    public function task(): Task
    {
        return $this->repository->read('scope', self::id());
    }

    public function intent(): ?StrictJson
    {
        return $this->queryBus->fetch(new GetTaskCancellation('scope', self::id(), 'caller'));
    }

    public function answers(): ?StrictJson
    {
        return $this->queryBus->fetch(new GetTaskInput('scope', self::id(), 'caller'));
    }

    public function snapshot(): string
    {
        return StrictJson::fromObject($this->task()->toArray(), 72)->toString();
    }
}
