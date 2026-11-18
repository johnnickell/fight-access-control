<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Task;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Fight\AccessControl\Application\AccessControl\Agent\Continuation\AgentContinuationBinding;
use Fight\AccessControl\Application\AccessControl\Agent\Continuation\AgentContinuationContext;
use Fight\AccessControl\Application\AccessControl\Agent\Continuation\AgentContinuationFence;
use Fight\AccessControl\Application\AccessControl\Agent\Continuation\AgentContinuationGuard;
use Fight\AccessControl\Application\AccessControl\Agent\Continuation\AgentContinuationHandoff;
use Fight\AccessControl\Application\AccessControl\Agent\Continuation\AgentContinuationScope;
use Fight\AccessControl\Application\AccessControl\Agent\Exception\AgentContinuationRejectedException;
use Fight\AccessControl\Application\AccessControl\Agent\Task\AgentTaskAuthority;
use Fight\AccessControl\Application\AccessControl\Agent\Task\AgentTaskContinuationScope;
use Fight\AccessControl\Application\AccessControl\Agent\Task\AgentTaskCreationAdmission;
use Fight\AccessControl\Application\AccessControl\Agent\Task\AgentTaskReadAdmission;
use Fight\AccessControl\Application\AccessControl\Agent\Task\AgentTaskUpdateAdmission;
use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentRepository;
use Fight\AccessControl\Domain\AccessControl\Agent\AuthenticatedAgentPrincipal;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\CurrentAgentPrincipalResolutionRejectedException;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\Common\Adapter\Mcp\Persistence\Task\Dbal\DbalTaskRepository;
use Fight\Common\Adapter\Mcp\Persistence\Task\Dbal\DbalTaskSchema;
use Fight\Common\Adapter\Mcp\Persistence\Task\Dbal\DbalTaskTransaction;
use Fight\Common\Adapter\Messaging\Command\Sync\Routing\InMemoryCommandRouter;
use Fight\Common\Adapter\Messaging\Command\Sync\RoutingCommandBus;
use Fight\Common\Adapter\Messaging\Event\Sync\SimpleEventDispatcher;
use Fight\Common\Adapter\Messaging\Query\Routing\InMemoryQueryRouter;
use Fight\Common\Adapter\Messaging\Query\RoutingQueryBus;
use Fight\Common\Application\Mcp\Task\CommandHandler\CreateTaskHandler;
use Fight\Common\Application\Mcp\Task\CommandHandler\RecordTaskStateHandler;
use Fight\Common\Application\Mcp\Task\CommandHandler\RequestTaskInputHandler;
use Fight\Common\Application\Mcp\Task\Exception\CommittedTaskDispatchFailed;
use Fight\Common\Application\Mcp\Task\Exception\TaskConflict;
use Fight\Common\Application\Mcp\Task\Exception\TaskRejected;
use Fight\Common\Application\Mcp\Task\Exception\TaskStorageFailed;
use Fight\Common\Application\Mcp\Task\QueryHandler\GetTaskHandler;
use Fight\Common\Application\Mcp\Task\Service\TaskInputForms;
use Fight\Common\Application\Mcp\Task\Service\ToolTaskPayloadPolicy;
use Fight\Common\Application\Mcp\Tool\Interaction\McpInputRequest;
use Fight\Common\Application\Mcp\Tool\Interaction\McpInputRequired;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Mcp\Task\Command\CreateTask;
use Fight\Common\Domain\Mcp\Task\Command\RecordTaskState;
use Fight\Common\Domain\Mcp\Task\Command\RequestTaskInput;
use Fight\Common\Domain\Mcp\Task\Event\TaskChanged;
use Fight\Common\Domain\Mcp\Task\Query\GetTask;
use Fight\Common\Domain\Mcp\Task\TaskId;
use Fight\Common\Domain\Mcp\Task\TaskState;
use Fight\Common\Domain\Messaging\Event\EventMessage;
use Fight\Common\Domain\Utility\ClassName;
use Fight\Common\Domain\Value\Basic\StrictJson;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Continuation\ControlledContinuationAuthority;
use Fight\Test\AccessControl\Application\AccessControl\Permission\Repository\InMemoryPermissionRepository;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(AgentTaskAuthority::class)]
#[CoversClass(AgentTaskContinuationScope::class)]
#[CoversClass(AgentTaskCreationAdmission::class)]
#[CoversClass(AgentTaskUpdateAdmission::class)]
#[CoversClass(AgentTaskReadAdmission::class)]
final class AgentTaskAdmissionTest extends TestCase
{
    private int $now = 1794312000000;

    /** @var list<array<string, mixed>> */
    private array $events = [];

    private bool $publicationFails = false;

    public function test_that_independent_permissions_precede_scope_and_concealed_lookup(): void
    {
        $connection = $this->database();
        $creator = new TaskAdmissionFixture(['TASK_CREATE']);
        [$commands, $queries] = $this->buses($creator, $connection);
        $commands->execute($this->create());
        $commands->execute($this->create());
        self::assertCount(1, $this->events);
        self::assertNull($queries->fetch($this->query()));
        $checks = $creator->checks;
        $this->rejects(fn() => $commands->execute($this->record(TaskState::create('cancelled'))));
        self::assertSame($checks, $creator->checks, 'A create grant never invokes producer scope.');
        $reader = new TaskAdmissionFixture(['TASK_READ'], identity: 401);
        [$readerCommands, $readerQueries] = $this->buses($reader, $connection);
        self::assertSame('working', $readerQueries->fetch($this->query())->get('state')->get('status'));
        $this->rejects(fn() => $readerCommands->execute($this->create()));
        $this->rejects(fn() => $readerCommands->execute($this->record(TaskState::create('cancelled'))));
        self::assertCount(1, $this->events);
        self::assertSame(1, $creator->nonce->consumptionCalls());
        self::assertSame(1, $reader->nonce->consumptionCalls());
    }

    public function test_that_cross_scope_and_forged_producer_attempts_conceal_presence_on_repeats_and_absence(): void
    {
        $fixture = new TaskAdmissionFixture();
        $connection = $this->database();
        [$commands, $queries] = $this->buses($fixture, $connection);
        $commands->execute($this->create());
        $before = $connection->fetchAllAssociative('SELECT * FROM fight_tasks');
        $fixture->create = false;
        $this->rejects(fn() => $commands->execute($this->create()));
        $this->rejects(fn() => $commands->execute(new CreateTask(
            TaskAdmissionFixture::definition('other'),
            TaskState::create('working')
        )));
        $this->rejects(fn() => $commands->execute(new RecordTaskState(
            'scope',
            $this->id(),
            'ADMIN',
            1,
            TaskState::create('cancelled')
        )));
        $this->rejects(fn() => $commands->execute(new RecordTaskState(
            'other',
            $this->id(),
            'producer',
            1,
            TaskState::create('cancelled')
        )));
        self::assertNull($queries->fetch(new GetTask('other', $this->id())));
        $fixture->read = false;
        self::assertNull($queries->fetch($this->query()));
        self::assertNull($queries->fetch(new GetTask('scope', TaskId::fromString(str_repeat('a', 32)))));
        self::assertSame($before, $connection->fetchAllAssociative('SELECT * FROM fight_tasks'));
        self::assertCount(1, $this->events);
    }

    public function test_that_complete_states_and_terminal_winner_survive_real_storage(): void
    {
        $fixture = new TaskAdmissionFixture();
        $connection = $this->database();
        [$commands, $queries, $repository] = $this->buses($fixture, $connection);
        $commands->execute(new CreateTask(TaskAdmissionFixture::definition(), TaskState::create('working'), 1000, 25));
        self::assertSame(25, $queries->fetch($this->query())->get('poll_interval'));
        // Common input issuance seeds the state being read; no input-answer/continuation integration is claimed.
        $input = TaskInputForms::batch(McpInputRequired::fromRequests([
            'request' => McpInputRequest::form('Private input', ['type' => 'object', 'properties' => (object) []])
        ]), 'caller', $this->now + 500);
        $commands->execute(new RequestTaskInput('scope', $this->id(), 'producer', 1, $input));
        $view = $queries->fetch($this->query());
        self::assertSame('input_required', $view->get('state')->get('status'));
        $requests = array_values($view->get('state')->get('input')->properties());
        self::assertSame('Private input', $requests[0]->get('message'));
        self::assertSame(
            TaskAdmissionFixture::definition()->operation()->toString(),
            $repository->read('scope', $this->id())->definition()->operation()->toString()
        );
        $commands->execute($this->record($this->completed(true), 2));
        $terminal = $queries->fetch($this->query())->toString();
        self::assertTrue($queries->fetch($this->query())->get('state')->get('result')->get('isError'));
        $commands->execute($this->record($this->completed(true), 2));
        self::assertCount(3, $this->events, 'An exact retry dispatches nothing.');
        try {
            $commands->execute($this->record(TaskState::create('cancelled'), 3));
            self::fail('A late writer cannot replace the terminal winner.');
        } catch (TaskConflict) {
            self::assertSame($terminal, $queries->fetch($this->query())->toString());
        }

        $fixture->update = false;
        $this->rejects(fn() => $commands->execute($this->record($this->completed(true))));
        $before = $connection->fetchAllAssociative('SELECT * FROM fight_tasks');
        $this->now += 1000;
        self::assertNull($queries->fetch($this->query()));
        self::assertSame(
            $before,
            $connection->fetchAllAssociative('SELECT * FROM fight_tasks'),
            'Reads do not clean or renew TTL.'
        );
        $fixture->create = true;
        $this->rejects(fn() => $commands->execute(new CreateTask(
            TaskAdmissionFixture::definition(),
            TaskState::create('working'),
            1000,
            25
        )));
    }

    public function test_that_working_failed_and_cancelled_are_not_fabricated_success_or_reinterpreted_payloads(): void
    {
        $states = [
            TaskState::create('working'),
            TaskState::create('failed', error: StrictJson::fromObject([
                'code' => -32603, 'message' => 'Internal error'
            ])),
            TaskState::create('cancelled'),
            $this->completed(false)
        ];
        foreach ($states as $state) {
            $fixture = new TaskAdmissionFixture();
            $connection = $this->database();
            [$commands, $queries] = $this->buses($fixture, $connection);
            $commands->execute($this->create());
            if ($state->status() !== 'working') {
                $commands->execute($this->record($state));
            }

            self::assertSame($state->toString(), $queries->fetch($this->query())->get('state')->toString());
            $fixture->read = false;
            self::assertNull(
                $queries->fetch($this->query()),
                'Whole state including error/result/message is concealed.'
            );
            $fixture->read = true;
            $restored = $this->view($queries);
            self::assertSame($state->toString(), $restored->get('state')->toString());
        }
    }

    public function test_that_late_read_scope_loss_and_comparison_epochs_preserve_whole_state_concealment(): void
    {
        $fixture = new TaskAdmissionFixture();
        [$commands, $queries] = $this->buses($fixture, $this->database());
        $commands->execute($this->create());
        $before = $queries->fetch($this->query());
        $fixture->afterComparison = static function () use ($fixture): void {
            $fixture->read = false;
        };
        self::assertNull($queries->fetch($this->query()));
        $fixture->afterComparison = null;
        $fixture->read = true;
        $fixture->comparison = 'reader-visibility-v2';

        $restored = $this->view($queries);
        self::assertNotSame($before->toString(), $restored->toString());
        $admission = new AgentTaskReadAdmission($fixture->authority('TASK_READ'), $fixture);
        $fixture->read = false;
        $this->rejects(fn(): string => $admission->comparisonScope('scope', $this->id()));
        $denied = new TaskAdmissionFixture(['TASK_CREATE']);
        $deniedAdmission = new AgentTaskReadAdmission($denied->authority('TASK_READ'), $denied);
        $this->rejects(fn(): string => $deniedAdmission->comparisonScope('scope', $this->id()));
    }

    public function test_that_authentication_refusal_and_operational_faults_are_distinct(): void
    {
        $invalid = new TaskAdmissionFixture(validSignature: false);
        self::assertNull($invalid->authority('TASK_READ')->principal());
        self::assertSame(0, $invalid->nonce->consumptionCalls());
        $incomplete = new TaskAdmissionFixture(complete: false);
        try {
            $incomplete->authority('TASK_READ')->principal();
            self::fail('A broken snapshot must not become denial or empty success.');
        } catch (CurrentAgentPrincipalResolutionRejectedException $currentAgentPrincipalResolutionRejectedException) {
            self::assertStringNotContainsString(
                'encrypted',
                $currentAgentPrincipalResolutionRejectedException->getMessage()
            );
        }

        $fixture = new TaskAdmissionFixture();
        $connection = $this->database();
        [$commands, $queries] = $this->buses($fixture, $connection);
        $commands->execute($this->create());
        $before = $connection->fetchAllAssociative('SELECT * FROM fight_tasks');
        $fixture->fault = true;
        $actions = [
            fn() => $commands->execute($this->create()),
            fn() => $commands->execute($this->record(TaskState::create('cancelled'))),
            fn() => $queries->fetch($this->query())
        ];
        foreach ($actions as $action) {
            try {
                $action();
                self::fail('Policy unavailability must propagate to the safe owner.');
            } catch (RuntimeException $failure) {
                self::assertSame('Private consumer policy unavailable.', $failure->getMessage());
            }
        }

        self::assertSame($before, $connection->fetchAllAssociative('SELECT * FROM fight_tasks'));
        self::assertCount(1, $this->events);
    }

    public function test_that_invalid_requirement_configuration_never_creates_a_permissive_default(): void
    {
        $fixture = new TaskAdmissionFixture();
        foreach ([[], ['TASK_READ'], ['key' => PermissionName::fromString('TASK_READ')]] as $requirements) {
            try {
                AgentTaskAuthority::fromRequest($fixture->provider, $fixture->request, 'config', $requirements);
                self::fail('Malformed configuration must fail closed.');
            } catch (LogicException) {
                self::assertSame(0, $fixture->nonce->consumptionCalls());
            }
        }

        $conjunctive = AgentTaskAuthority::fromRequest($fixture->provider, $fixture->request, 'all', [
            PermissionName::fromString('TASK_READ'), PermissionName::fromString('UNGRANTED')
        ]);
        self::assertNull($conjunctive->principal());
    }

    public function test_that_postcommit_failure_preserves_truth_and_denial_conceals_conflicts(): void
    {
        $fixture = new TaskAdmissionFixture();
        $connection = $this->database();
        [$commands, $queries] = $this->buses($fixture, $connection);
        $commands->execute($this->create());
        $changed = TaskAdmissionFixture::definition()->operation()->with('revision', 'different-v2');
        $fixture->create = false;
        $conflict = new CreateTask(TaskAdmissionFixture::definition(operation: $changed), TaskState::create('working'));
        $this->rejects(fn() => $commands->execute($conflict));
        $fixture->create = true;
        try {
            $commands->execute($conflict);
            self::fail('An authorized conflicting repeat must remain conflict.');
        } catch (TaskConflict $taskConflict) {
            self::assertStringNotContainsString('different-v2', $taskConflict->getMessage());
        }

        $unsafe = TaskState::create('failed', error: StrictJson::fromObject([
            'code' => -32603, 'message' => 'Private cause'
        ]));
        $this->rejects(fn() => $commands->execute($this->record($unsafe)));
        self::assertCount(1, $this->events);
        $this->publicationFails = true;
        try {
            $commands->execute($this->record($this->completed(false)));
            self::fail('Postcommit dispatch failure is not rollback.');
        } catch (CommittedTaskDispatchFailed $committedTaskDispatchFailed) {
            self::assertSame(2, $committedTaskDispatchFailed->event()->toArray()['version']);
        }

        self::assertSame('completed', $queries->fetch($this->query())->get('state')->get('status'));
        $commands->execute($this->record($this->completed(false)));
        self::assertCount(2, $this->events);
        self::assertStringNotContainsString('original', json_encode($this->events, JSON_THROW_ON_ERROR));
    }

    public function test_that_real_storage_rollback_and_outer_transaction_rejection_preserve_other_work(): void
    {
        $fixture = new TaskAdmissionFixture();
        $connection = $this->database();
        [$commands, $queries] = $this->buses($fixture, $connection);
        $connection->beginTransaction();
        try {
            $commands->execute($this->create());
            self::fail('No enclosing transaction is supported.');
        } catch (LogicException) {
            self::assertTrue($connection->isTransactionActive());
            self::assertSame(0, $fixture->checks);
        } finally {
            $connection->rollBack();
        }

        $commands->execute($this->create());
        $connection->executeStatement(
            "CREATE TRIGGER task_fail BEFORE INSERT ON fight_tasks BEGIN SELECT RAISE(ABORT, 'private'); END"
        );
        try {
            $commands->execute($this->record($this->completed(false)));
            self::fail('Actual storage rollback required.');
        } catch (TaskStorageFailed $taskStorageFailed) {
            self::assertFalse($taskStorageFailed->outcomeUncertain());
        }

        self::assertSame('working', $queries->fetch($this->query())->get('state')->get('status'));
        self::assertCount(1, $this->events);
    }

    public function test_that_producer_authority_never_substitutes_for_original_caller_continuation(): void
    {
        $caller = new TaskAdmissionFixture();
        $producer = new TaskAdmissionFixture(['TASK_UPDATE'], identity: 501);
        $binding = AgentContinuationBinding::fromRequest($caller->provider, $caller->request, 'original');
        $scope = new class implements AgentContinuationScope {
            public bool $allowed = true;

            public function isAllowed(AuthenticatedAgentPrincipal $principal): bool
            {
                return $this->allowed;
            }
        };
        $fence = new class implements AgentContinuationFence {
            public function synchronized(AgentId $id, AgentContinuationScope $scope, Closure $admit): void
            {
                $admit();
            }
        };
        $guard = new AgentContinuationGuard(
            $caller->agentRepository,
            $caller->permissionRepository,
            new FixedClock(TaskAdmissionFixture::now()),
            $fence
        );
        $connection = $this->database();
        [$callerCommands, $callerQueries] = $this->buses($caller, $connection);
        [$producerCommands] = $this->buses($producer, $connection);
        $callerCommands->execute($this->create());
        $guard->run(
            $binding,
            [PermissionName::fromString('TASK_CONTINUE')],
            $scope,
            fn() => $producerCommands->execute($this->record(TaskState::create('working', message: 'step one')))
        );
        $scope->allowed = false;
        try {
            $guard->run(
                $binding,
                [PermissionName::fromString('TASK_CONTINUE')],
                $scope,
                fn() => $producerCommands->execute($this->record($this->completed(false), 2))
            );
            self::fail('Producer Permission cannot replace original caller scope.');
        } catch (AgentContinuationRejectedException) {
            self::assertSame('step one', $callerQueries->fetch($this->query())->get('state')->get('message'));
        }

        $producerCommands->execute($this->record($this->completed(false), 2));
        self::assertSame(
            'completed',
            $callerQueries->fetch($this->query())->get('state')->get('status'),
            'Producer reporting is not execution continuation.'
        );
        self::assertSame(1, $caller->nonce->consumptionCalls());
        self::assertSame(1, $producer->nonce->consumptionCalls());
    }

    public function test_that_background_reports_recheck_original_producer_binding_without_nonce_replay(): void
    {
        $changes = ['revoked', 'rotated', 'permission removed', 'definition missing', 'scope', 'resolver fault'];
        foreach ($changes as $change) {
            $producer = new TaskAdmissionFixture();
            $original = AgentContinuationBinding::fromRequest($producer->provider, $producer->request, 'producer');
            $handoff = new readonly class ($original->getContext()) implements AgentContinuationHandoff {
                public function __construct(private AgentContinuationContext $context)
                {
                }

                public function verify(): AgentContinuationContext
                {
                    return $this->context;
                }
            };
            $binding = AgentContinuationBinding::fromHandoff($handoff);
            $state = new ControlledContinuationAuthority(
                $producer->agent,
                $producer->permissionRepository->getByIds($producer->agent->getPermissionIds())
            );
            $agents = $this->createStub(AgentRepository::class);
            $agents->method('getOperationContract')->willReturnCallback($state->readContract(...));
            $agents->method('getById')->willReturnCallback(static fn(): ?Agent => $state->readAgent());
            $permissions = new InMemoryPermissionRepository(getByIdsResult: $state->readPermissions(...));
            $fence = $this->createStub(AgentContinuationFence::class);
            $fence->method('synchronized')->willReturnCallback(
                static function (AgentId $id, AgentContinuationScope $scope, Closure $admit) use ($state): void {
                    self::assertInstanceOf(AgentTaskContinuationScope::class, $scope);
                    self::assertSame($state, $scope->getScope());
                    $state->synchronized($id, $state, $admit);
                }
            );
            $guard = new AgentContinuationGuard(
                $agents,
                $permissions,
                new FixedClock(TaskAdmissionFixture::now()),
                $fence
            );
            $connection = $this->database();
            [$commands, $queries] = $this->buses($producer, $connection);
            $commands->execute($this->create());
            $saved = null;
            $report = function (AgentTaskAuthority $authority) use ($producer, $connection, &$saved): void {
                $saved = $authority;
                [$workerCommands] = $this->buses($producer, $connection, $authority);
                $workerCommands->execute($this->record(TaskState::create('working', message: 'first report')));
            };
            AgentTaskAuthority::duringContinuation(
                $guard,
                $binding,
                [PermissionName::fromString('TASK_UPDATE')],
                $state,
                $report
            );
            self::assertInstanceOf(AgentTaskAuthority::class, $saved);
            try {
                $saved->principal();
                self::fail('Worker authority must expire when the bounded action returns.');
            } catch (AgentContinuationRejectedException) {
                self::assertFalse($state->held);
            }

            $before = $queries->fetch($this->query())->toString();
            $agent = $producer->agent;
            match ($change) {
                'revoked' => $state->agent = $agent->revoke(TaskAdmissionFixture::now()),
                'rotated' => $state->agent = $agent->rotateRecoverableCredential(
                    $agent->getCredentialId(),
                    0,
                    AgentCredentialId::fromString('018f0000-0000-7000-8000-000000000999'),
                    'encrypted:successor',
                    TaskAdmissionFixture::now()
                ),
                'permission removed' => $state->agent = $agent->revokePermission(
                    $producer->permissionRepository->getByName(PermissionName::fromString('TASK_UPDATE'))->getId(),
                    TaskAdmissionFixture::now()
                ),
                'definition missing' => $state->definitions = [],
                'scope' => $state->scopeAllowed = false,
                'resolver fault' => $state->agentFailure = new RuntimeException('Private authority failure.')
            };
            try {
                AgentTaskAuthority::duringContinuation(
                    $guard,
                    $binding,
                    [PermissionName::fromString('TASK_UPDATE')],
                    $state,
                    $report
                );
                self::fail('Changed current authority must deny the next protected producer report.');
            } catch (AgentContinuationRejectedException $failure) {
                self::assertNull($failure->getPrevious());
                self::assertSame($before, $queries->fetch($this->query())->toString());
            }

            self::assertSame(1, $producer->nonce->consumptionCalls());
            self::assertFalse($state->held);
        }
    }

    public function test_that_continuation_scope_is_not_an_independent_authority_factory(): void
    {
        $scope = $this->createStub(AgentContinuationScope::class);
        $scope->method('isAllowed')->willReturn(false);
        $wrapper = new AgentTaskContinuationScope($scope);
        self::assertSame($scope, $wrapper->getScope());
        $fixture = new TaskAdmissionFixture();
        self::assertFalse($wrapper->isAllowed($fixture->provider->resolve($fixture->request, 'scope')));
        try {
            $wrapper->principal();
            self::fail('No admitted principal may be manufactured by a denied scope.');
        } catch (AgentContinuationRejectedException) {
            self::assertSame(1, $fixture->nonce->consumptionCalls());
        }
    }

    public function test_that_lost_real_commit_acknowledgment_reconciles_through_a_fresh_connection(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'task-admission-');
        self::assertIsString($path);
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $path]);
        $params = ['driver' => 'pdo_sqlite', 'path' => $path];
        $uncertain = new class ($params, $connection->getDriver()) extends Connection {
            public function commit(): void
            {
                parent::commit();
                throw new RuntimeException('Private lost commit acknowledgment.');
            }
        };
        try {
            new DbalTaskSchema()->install($connection);
            $fixture = new TaskAdmissionFixture();
            [$commands] = $this->buses($fixture, $connection);
            $commands->execute($this->create());
            [$uncertainCommands] = $this->buses($fixture, $uncertain);
            try {
                $uncertainCommands->execute($this->record($this->completed(false)));
                self::fail('Lost acknowledgment must not be guessed committed or rolled back.');
            } catch (TaskStorageFailed $failure) {
                self::assertTrue($failure->outcomeUncertain());
            }

            $connection->close();
            $fresh = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $path]);
            try {
                [$retryCommands, $queries] = $this->buses($fixture, $fresh);
                self::assertSame('completed', $queries->fetch($this->query())->get('state')->get('status'));
                $retryCommands->execute($this->record($this->completed(false)));
                self::assertCount(1, $this->events, 'Reconciliation does not invent the lost postcommit dispatch.');
            } finally {
                $fresh->close();
            }
        } finally {
            $connection->close();
            $uncertain->close();
            unlink($path);
        }
    }

    public function test_that_exact_finite_payload_bounds_survive_storage_without_partial_success(): void
    {
        $fixture = new TaskAdmissionFixture();
        [$commands, $queries] = $this->buses($fixture, $this->database());
        $commands->execute($this->create());
        $result = StrictJson::fromObject([
            'content'           => [(object) ['type' => 'text', 'text' => '']],
            'structuredContent' => (object) ['ok' => true],
            'isError'           => false
        ]);
        $empty = TaskState::create('completed', result: $result);
        $size = TaskState::MAX_BYTES - strlen($empty->toString());
        $atBound = $result->with('content', [(object) ['type' => 'text', 'text' => str_repeat('x', $size)]]);
        $state = TaskState::create('completed', result: $atBound);
        self::assertSame(TaskState::MAX_BYTES, strlen($state->toString()));
        $commands->execute($this->record($state));
        self::assertSame($state->toString(), $queries->fetch($this->query())->get('state')->toString());
        try {
            TaskState::create('completed', result: $result->with('content', [
                (object) ['type' => 'text', 'text' => str_repeat('x', $size + 1)]
            ]));
            self::fail('One encoded byte above the bound must reject rather than truncate.');
        } catch (DomainException) {
            self::assertSame($state->toString(), $this->view($queries)->get('state')->toString());
        }
    }

    private function database(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        new DbalTaskSchema()->install($connection);

        return $connection;
    }

    /** @return array{RoutingCommandBus, RoutingQueryBus, DbalTaskRepository} */
    private function buses(
        TaskAdmissionFixture $fixture,
        Connection $connection,
        ?AgentTaskAuthority $producerAuthority = null
    ): array {
        $transaction = new DbalTaskTransaction($connection);
        $repository = new DbalTaskRepository($transaction);
        $events = new SimpleEventDispatcher();
        $events->addHandler(ClassName::underscore(TaskChanged::class), function (EventMessage $message): void {
            $this->events[] = $message->payload()->toArray();
            if ($this->publicationFails) {
                throw new RuntimeException('Private publisher failure.');
            }
        });
        $payload = new ToolTaskPayloadPolicy(static fn(): bool => true);
        $clock = fn(): int => $this->now;
        $creation = new CreateTaskHandler(
            $repository,
            $transaction,
            new AgentTaskCreationAdmission($fixture->authority('TASK_CREATE'), $fixture),
            $payload,
            $events,
            $clock
        );
        $updateAdmission = new AgentTaskUpdateAdmission(
            $producerAuthority ?? $fixture->authority('TASK_UPDATE'),
            $fixture
        );
        $update = new RecordTaskStateHandler(
            $repository,
            $transaction,
            $updateAdmission,
            $payload,
            $events,
            $clock
        );
        $read = new GetTaskHandler(
            $repository,
            new AgentTaskReadAdmission($fixture->authority('TASK_READ'), $fixture),
            $payload,
            $clock
        );
        $commandRouter = new InMemoryCommandRouter();
        $commandRouter->registerHandler($creation::commandRegistration(), $creation);
        $commandRouter->registerHandler($update::commandRegistration(), $update);

        $input = new RequestTaskInputHandler(
            $repository,
            $transaction,
            $updateAdmission,
            $payload,
            $events,
            $clock
        );
        $commandRouter->registerHandler($input::commandRegistration(), $input);
        $queryRouter = new InMemoryQueryRouter();
        $queryRouter->registerHandler($read::queryRegistration(), $read);

        return [new RoutingCommandBus($commandRouter), new RoutingQueryBus($queryRouter), $repository];
    }

    private function view(RoutingQueryBus $queryBus): StrictJson
    {
        $view = $queryBus->fetch($this->query());
        self::assertInstanceOf(StrictJson::class, $view);

        return $view;
    }

    private function create(): CreateTask
    {
        return new CreateTask(TaskAdmissionFixture::definition(), TaskState::create('working'));
    }

    private function query(): GetTask
    {
        return new GetTask('scope', $this->id());
    }

    private function id(): TaskId
    {
        return TaskId::fromString(TaskAdmissionFixture::ID);
    }

    private function record(TaskState $state, int $version = 1): RecordTaskState
    {
        return new RecordTaskState('scope', $this->id(), 'producer', $version, $state);
    }

    private function completed(bool $isError): TaskState
    {
        return TaskState::create('completed', result: StrictJson::fromObject([
            'content'           => [(object) ['type' => 'text', 'text' => 'Outcome']],
            'structuredContent' => (object) ['ok' => !$isError],
            'isError'           => $isError
        ]));
    }

    /** @phpstan-param Closure(): mixed $action */
    private function rejects(Closure $action): void
    {
        try {
            $action();
            self::fail('Expected neutral Task rejection.');
        } catch (TaskRejected $taskRejected) {
            self::assertSame('Task operation rejected.', $taskRejected->getMessage());
        }
    }
}
