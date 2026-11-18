<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Task;

use Closure;
use Doctrine\DBAL\Connection;
use Fight\AccessControl\Application\AccessControl\Agent\Task\AgentTaskAuthority;
use Fight\AccessControl\Application\AccessControl\Agent\Task\AgentTaskCreationAdmission;
use Fight\AccessControl\Application\AccessControl\Agent\Task\AgentTaskReadAdmission;
use Fight\AccessControl\Application\AccessControl\Agent\Task\AgentTaskRemovalAdmission;
use Fight\AccessControl\Application\AccessControl\Agent\Task\AgentTaskRemovalScope;
use Fight\AccessControl\Application\AccessControl\Agent\Task\AgentTaskUpdateAdmission;
use Fight\AccessControl\Domain\AccessControl\Agent\AuthenticatedAgentPrincipal;
use Fight\Common\Adapter\Mcp\Persistence\Task\Dbal\DbalTaskRepository;
use Fight\Common\Adapter\Mcp\Persistence\Task\Dbal\DbalTaskTransaction;
use Fight\Common\Adapter\Messaging\Command\Sync\Routing\InMemoryCommandRouter;
use Fight\Common\Adapter\Messaging\Command\Sync\RoutingCommandBus;
use Fight\Common\Adapter\Messaging\Event\Sync\SimpleEventDispatcher;
use Fight\Common\Adapter\Messaging\Query\Routing\InMemoryQueryRouter;
use Fight\Common\Adapter\Messaging\Query\RoutingQueryBus;
use Fight\Common\Application\Mcp\Task\CommandHandler\CreateTaskHandler;
use Fight\Common\Application\Mcp\Task\CommandHandler\RecordTaskStateHandler;
use Fight\Common\Application\Mcp\Task\CommandHandler\RemoveTasksHandler;
use Fight\Common\Application\Mcp\Task\CommandHandler\RequestTaskInputHandler;
use Fight\Common\Application\Mcp\Task\QueryHandler\GetTaskHandler;
use Fight\Common\Application\Mcp\Task\Service\ToolTaskPayloadPolicy;
use Fight\Common\Domain\Mcp\Task\Command\CreateTask;
use Fight\Common\Domain\Mcp\Task\Command\RecordTaskState;
use Fight\Common\Domain\Mcp\Task\Command\RemoveTasks;
use Fight\Common\Domain\Mcp\Task\Event\TaskChanged;
use Fight\Common\Domain\Mcp\Task\Event\TaskDataRemoved;
use Fight\Common\Domain\Mcp\Task\Query\GetTask;
use Fight\Common\Domain\Mcp\Task\TaskDefinition;
use Fight\Common\Domain\Mcp\Task\TaskId;
use Fight\Common\Domain\Mcp\Task\TaskSelection;
use Fight\Common\Domain\Mcp\Task\TaskState;
use Fight\Common\Domain\Messaging\Event\EventMessage;
use Fight\Common\Domain\Utility\ClassName;
use Fight\Common\Domain\Value\Basic\StrictJson;
use RuntimeException;

final class TaskRemovalJourney implements AgentTaskRemovalScope
{
    public const string SECOND = '1123456789abcdef0123456789abcdef';

    public int $now = 1794312000000;

    /** @var list<string> */
    public array $checked = [];

    /** @var list<string> */
    public array $denied = [];

    public bool $fault = false;

    public bool $publicationFails = false;

    public ?Closure $beforeAdmission = null;

    /** @var list<array<string, mixed>> */
    public array $removed = [];

    public int $changed = 0;

    public readonly TaskAdmissionFixture $fixture;

    public readonly DbalTaskRepository $repository;

    public readonly DbalTaskTransaction $transaction;

    public readonly RoutingCommandBus $commands;

    public readonly RoutingQueryBus $queries;

    public function __construct(
        public readonly Connection $connection,
        ?TaskAdmissionFixture $fixture = null,
        ?AgentTaskAuthority $removalAuthority = null
    ) {
        $this->fixture = $fixture ?? new TaskAdmissionFixture([
            'TASK_CREATE', 'TASK_UPDATE', 'TASK_READ', 'TASK_REMOVE'
        ]);
        $fixture = $this->fixture;
        $this->transaction = new DbalTaskTransaction($connection);
        $this->repository = new DbalTaskRepository($this->transaction);
        $events = new SimpleEventDispatcher();
        $events->addHandler(ClassName::underscore(TaskChanged::class), function (): void {
            ++$this->changed;
        });
        $events->addHandler(ClassName::underscore(TaskDataRemoved::class), function (EventMessage $message): void {
            $this->removed[] = $message->payload()->toArray();
            if ($this->publicationFails) {
                throw new RuntimeException('Private publisher details.');
            }
        });
        $payload = new ToolTaskPayloadPolicy(static fn(): bool => true);
        $clock = fn(): int => $this->now;
        $updateAdmission = new AgentTaskUpdateAdmission($fixture->authority('TASK_UPDATE'), $fixture);
        $handlers = [
            new CreateTaskHandler(
                $this->repository,
                $this->transaction,
                new AgentTaskCreationAdmission($fixture->authority('TASK_CREATE'), $fixture),
                $payload,
                $events,
                $clock
            ),
            new RecordTaskStateHandler(
                $this->repository,
                $this->transaction,
                $updateAdmission,
                $payload,
                $events,
                $clock
            ),
            new RequestTaskInputHandler(
                $this->repository,
                $this->transaction,
                $updateAdmission,
                $payload,
                $events,
                $clock
            ),
            new RemoveTasksHandler(
                $this->repository,
                $this->transaction,
                new AgentTaskRemovalAdmission($removalAuthority ?? $fixture->authority('TASK_REMOVE'), $this),
                $events
            )
        ];
        $router = new InMemoryCommandRouter();
        foreach ($handlers as $handler) {
            $router->registerHandler($handler::commandRegistration(), $handler);
        }

        $read = new GetTaskHandler(
            $this->repository,
            new AgentTaskReadAdmission($fixture->authority('TASK_READ'), $fixture),
            $payload,
            $clock
        );
        $queryRouter = new InMemoryQueryRouter();
        $queryRouter->registerHandler($read::queryRegistration(), $read);

        $this->commands = new RoutingCommandBus($router);
        $this->queries = new RoutingQueryBus($queryRouter);
    }

    public function allowsRemoval(AuthenticatedAgentPrincipal $principal, string $scope, TaskId $id): bool
    {
        $this->checked[] = $id->toString();
        if ($this->beforeAdmission !== null) {
            ($this->beforeAdmission)();
        }

        if ($this->fault) {
            throw new RuntimeException('Private retention policy details.');
        }

        return $principal->getAgentId()->equals($this->fixture->agent->getId()) && $scope === 'scope'
            && !in_array($id->toString(), $this->denied, true);
    }

    public function create(string $id = TaskAdmissionFixture::ID, ?TaskState $state = null, ?int $ttl = null): void
    {
        $definition = TaskAdmissionFixture::definition();
        $this->commands->execute(new CreateTask(
            TaskDefinition::create(TaskId::fromString($id), 'scope', 'producer', $definition->operation()),
            $state ?? TaskState::create('working'),
            $ttl
        ));
    }

    /** @param list<string> $ids */
    public function remove(array $ids = [TaskAdmissionFixture::ID], string $scope = 'scope'): void
    {
        $this->commands->execute(new RemoveTasks(TaskSelection::fromIds(
            $scope,
            array_map(TaskId::fromString(...), $ids)
        )));
    }

    public function record(TaskState $state, int $version = 1): void
    {
        $this->commands->execute(new RecordTaskState(
            'scope',
            TaskId::fromString(TaskAdmissionFixture::ID),
            'producer',
            $version,
            $state
        ));
    }

    public function read(): ?StrictJson
    {
        /** @var ?StrictJson $view */
        $view = $this->queries->fetch(new GetTask('scope', TaskId::fromString(TaskAdmissionFixture::ID)));

        return $view;
    }

    /** @return list<array<string, mixed>> */
    public function rows(): array
    {
        return $this->connection->fetchAllAssociative('SELECT * FROM fight_tasks ORDER BY task_id');
    }
}
