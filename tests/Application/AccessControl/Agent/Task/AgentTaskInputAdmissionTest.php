<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Task;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Fight\AccessControl\Application\AccessControl\Agent\Continuation\AgentContinuationBinding;
use Fight\AccessControl\Application\AccessControl\Agent\Continuation\AgentContinuationFence;
use Fight\AccessControl\Application\AccessControl\Agent\Continuation\AgentContinuationGuard;
use Fight\AccessControl\Application\AccessControl\Agent\Continuation\AgentContinuationScope;
use Fight\AccessControl\Application\AccessControl\Agent\Exception\AgentContinuationRejectedException;
use Fight\AccessControl\Application\AccessControl\Agent\Task\AgentTaskAuthority;
use Fight\AccessControl\Application\AccessControl\Agent\Task\AgentTaskCancellationObservationAdmission;
use Fight\AccessControl\Application\AccessControl\Agent\Task\AgentTaskContinuationScope;
use Fight\AccessControl\Application\AccessControl\Agent\Task\AgentTaskCreationAdmission;
use Fight\AccessControl\Application\AccessControl\Agent\Task\AgentTaskInputAdmission;
use Fight\AccessControl\Application\AccessControl\Agent\Task\AgentTaskReadAdmission;
use Fight\AccessControl\Application\AccessControl\Agent\Task\AgentTaskUpdateAdmission;
use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentRepository;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\Common\Adapter\Mcp\Persistence\Task\Dbal\DbalTaskRepository;
use Fight\Common\Adapter\Mcp\Persistence\Task\Dbal\DbalTaskSchema;
use Fight\Common\Adapter\Mcp\Persistence\Task\Dbal\DbalTaskTransaction;
use Fight\Common\Adapter\Messaging\Command\Sync\Routing\InMemoryCommandRouter;
use Fight\Common\Adapter\Messaging\Command\Sync\RoutingCommandBus;
use Fight\Common\Adapter\Messaging\Event\Sync\SimpleEventDispatcher;
use Fight\Common\Adapter\Messaging\Query\Routing\InMemoryQueryRouter;
use Fight\Common\Adapter\Messaging\Query\RoutingQueryBus;
use Fight\Common\Application\Mcp\Protocol\Exception\McpProtocolException;
use Fight\Common\Application\Mcp\Protocol\McpRequest;
use Fight\Common\Application\Mcp\Protocol\McpRequestMetadata;
use Fight\Common\Application\Mcp\Task\CommandHandler\AcquireTaskInputHandler;
use Fight\Common\Application\Mcp\Task\CommandHandler\CreateTaskHandler;
use Fight\Common\Application\Mcp\Task\CommandHandler\RecordTaskStateHandler;
use Fight\Common\Application\Mcp\Task\CommandHandler\RequestTaskInputHandler;
use Fight\Common\Application\Mcp\Task\CommandHandler\RetireTaskInputHandler;
use Fight\Common\Application\Mcp\Task\CommandHandler\SupplyTaskInputHandler;
use Fight\Common\Application\Mcp\Task\Exception\CommittedTaskDispatchFailed;
use Fight\Common\Application\Mcp\Task\Exception\TaskConflict;
use Fight\Common\Application\Mcp\Task\Exception\TaskRejected;
use Fight\Common\Application\Mcp\Task\Exception\TaskStorageFailed;
use Fight\Common\Application\Mcp\Task\McpTaskInput;
use Fight\Common\Application\Mcp\Task\QueryHandler\GetTaskHandler;
use Fight\Common\Application\Mcp\Task\QueryHandler\GetTaskInputHandler;
use Fight\Common\Application\Mcp\Task\Service\TaskInputForms;
use Fight\Common\Application\Mcp\Task\Service\TaskPayloadPolicy;
use Fight\Common\Application\Mcp\Task\Service\ToolTaskPayloadPolicy;
use Fight\Common\Application\Mcp\Tool\Interaction\McpInputRequest;
use Fight\Common\Application\Mcp\Tool\Interaction\McpInputRequired;
use Fight\Common\Domain\Mcp\Task\Command\AcquireTaskInput;
use Fight\Common\Domain\Mcp\Task\Command\CreateTask;
use Fight\Common\Domain\Mcp\Task\Command\RecordTaskState;
use Fight\Common\Domain\Mcp\Task\Command\RequestTaskInput;
use Fight\Common\Domain\Mcp\Task\Command\RetireTaskInput;
use Fight\Common\Domain\Mcp\Task\Command\SupplyTaskInput;
use Fight\Common\Domain\Mcp\Task\Event\TaskChanged;
use Fight\Common\Domain\Mcp\Task\Query\GetTask;
use Fight\Common\Domain\Mcp\Task\Query\GetTaskInput;
use Fight\Common\Domain\Mcp\Task\TaskId;
use Fight\Common\Domain\Mcp\Task\TaskRepository;
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
use Symfony\Component\Process\Process;

#[CoversClass(AgentTaskInputAdmission::class)]
#[CoversClass(AgentTaskCancellationObservationAdmission::class)]
final class AgentTaskInputAdmissionTest extends TestCase
{
    private int $now = 1794312000000;

    private int $events = 0;

    private bool $publicationFails = false;

    public function test_that_each_grant_is_independent_and_denial_precedes_scope(): void
    {
        foreach (['INPUT_SUBMIT', 'INPUT_OBSERVE', 'INPUT_CONTINUE', 'INTENT_OBSERVE'] as $grant) {
            $fixture = new TaskInputAdmissionFixture([$grant]);
            $input = $fixture->input();
            $cancellation = $fixture->cancellation();
            self::assertSame($grant === 'INPUT_SUBMIT', $input->allowsSubmission('scope', $this->id(), 'caller'));
            self::assertSame($grant === 'INPUT_OBSERVE', $input->allowsObservation('scope', $this->id(), 'caller'));
            self::assertSame(
                $grant === 'INPUT_CONTINUE',
                $input->allowsContinuation('scope', $this->id(), 'caller', 'group')
            );
            self::assertSame(
                $grant === 'INTENT_OBSERVE',
                $cancellation->allowsCancellationObservation('scope', $this->id(), 'caller')
            );
            self::assertFalse($cancellation->allowsCancellation('scope', $this->id(), 'caller'));
            self::assertSame(1, $fixture->checks);
            self::assertSame(1, $fixture->auth->nonce->consumptionCalls());
        }

        $fixture = new TaskInputAdmissionFixture(['TASK_READ', 'TASK_CREATE', 'TASK_UPDATE']);
        self::assertFalse($fixture->input()->allowsSubmission('scope', $this->id(), 'caller'));
        self::assertFalse($fixture->input()->allowsObservation('scope', $this->id(), 'caller'));
        self::assertFalse($fixture->input()->allowsContinuation('scope', $this->id(), 'caller', 'group'));
        self::assertFalse($fixture->cancellation()->allowsCancellationObservation('scope', $this->id(), 'caller'));
        self::assertSame(0, $fixture->checks);
    }

    public function test_that_real_partial_validation_is_atomic_and_inactive_keys_do_not_replace_evidence(): void
    {
        $fixture = new TaskInputAdmissionFixture();
        [$commands, $queries, $repository] = $this->buses($fixture, $this->database());
        [$group, $second] = $this->issue($commands, $repository);
        $before = $this->snapshot($repository);
        foreach ([StrictJson::fromObject(), StrictJson::fromObject(['unknown' => 'not a response'])] as $empty) {
            $commands->execute(new SupplyTaskInput('scope', $this->id(), 'caller', $empty));
            self::assertSame($before, $this->snapshot($repository));
        }

        $this->rejects(fn() => $commands->execute($this->supply([
            $group => $this->answer(), $second => $this->answer('accept', 'invalid')
        ])));
        self::assertSame($before, $this->snapshot($repository));
        $commands->execute($this->supply([$group => $this->answer(), 'unknown' => 'ignored']));
        $view = $queries->fetch(new GetTask('scope', $this->id()));
        self::assertSame([$second], array_keys($view->get('state')->get('input')->properties()));
        self::assertStringNotContainsString('rules', $view->toString());
        $observed = $this->observe($queries)->get('groups')->get($group);
        self::assertFalse($observed->get('ready'));
        self::assertSame(7, $observed->get('answers')->get($group)->get('content')->get('value'));
        $accepted = $this->snapshot($repository);
        $commands->execute($this->supply([$group => $this->answer('cancel'), 'unknown' => null]));
        self::assertSame($accepted, $this->snapshot($repository));
        $commands->execute($this->supply([$second => $this->answer()]));
        self::assertTrue($this->observe($queries)->get('groups')->get($group)->get('ready'));
        self::assertSame(1, $fixture->auth->nonce->consumptionCalls());
    }

    public function test_that_confirmation_requires_every_accept_and_consumption_survives_failure(): void
    {
        $fixture = new TaskInputAdmissionFixture();
        [$commands, $queries, $repository] = $this->buses($fixture, $this->database());
        [$group, $second] = $this->issue($commands, $repository, true);
        $commands->execute($this->supply([$group => $this->answer()]));
        $this->conflicts(fn() => $commands->execute($this->acquire($group)));
        $commands->execute($this->supply([$second => $this->answer()]));
        $fixture->continuation = false;
        $this->rejects(fn() => $commands->execute($this->acquire($group)));
        self::assertTrue($this->observe($queries)->get('groups')->get($group)->get('ready'));
        $fixture->continuation = true;
        $fixture->intent = false;
        $this->rejects(fn() => $commands->execute($this->acquire($group)));
        self::assertNull($queries->fetch(new GetTaskInput('scope', $this->id(), 'caller')));
        $fixture->intent = true;
        $this->publicationFails = true;
        try {
            $commands->execute($this->acquire($group));
            self::fail('Postcommit publication failure must remain truthful.');
        } catch (CommittedTaskDispatchFailed) {
            self::assertTrue($this->observe($queries)->get('groups')->get($group)->get('consumed'));
        }

        $this->publicationFails = false;
        $this->conflicts(fn() => $commands->execute($this->acquire($group)));
        self::assertFalse($this->observe($queries)->get('groups')->get($group)->get('ready'));
        self::assertSame('working', $queries->fetch(new GetTask('scope', $this->id()))->get('state')->get('status'));
    }

    public function test_that_decline_and_cancel_retire_only_the_confirmation_group(): void
    {
        foreach (['decline', 'cancel'] as $action) {
            $fixture = new TaskInputAdmissionFixture();
            [$commands, $queries, $repository] = $this->buses($fixture, $this->database());
            [$group, $second] = $this->issue($commands, $repository, true);
            $commands->execute($this->supply([$group => $this->answer($action)]));
            $state = $this->observe($queries)->get('groups')->get($group);
            self::assertTrue($state->get('refused'));
            self::assertFalse($state->get('ready'));
            self::assertFalse($state->get('answers')->has($second));
            $taskState = $queries->fetch(new GetTask('scope', $this->id()))->get('state');
            self::assertSame('working', $taskState->get('status'));
            $this->conflicts(fn() => $commands->execute($this->acquire($group)));
        }
    }

    public function test_that_exact_binding_scope_and_expiry_deny_transplant_without_changing_answers(): void
    {
        $fixture = new TaskInputAdmissionFixture();
        [$commands, $queries, $repository] = $this->buses($fixture, $this->database());
        [$group, $second] = $this->issue($commands, $repository);
        $before = $this->snapshot($repository);
        foreach (
            [
                ['other', $this->id(), 'caller'],
                ['scope', $this->id(), 'other'],
                ['scope', TaskId::fromString(str_repeat('a', 32)), 'caller']
            ] as [$scope, $id, $caller]
        ) {
            $this->rejects(fn() => $commands->execute(new SupplyTaskInput(
                $scope,
                $id,
                $caller,
                StrictJson::fromObject([$group => $this->answer()])
            )));
            self::assertNull($queries->fetch(new GetTaskInput($scope, $id, $caller)));
            $this->rejects(fn() => $commands->execute(new AcquireTaskInput($scope, $id, $caller, $group)));
        }

        self::assertSame($before, $this->snapshot($repository));
        $fixture->group = $second;
        $this->rejects(fn() => $commands->execute($this->acquire($group)));
        $fixture->group = null;
        $this->now += 500;
        $commands->execute($this->supply([$group => $this->answer(), $second => $this->answer()]));
        self::assertTrue($this->observe($queries)->get('groups')->get($group)->get('expired'));
        self::assertSame([], $this->observe($queries)->get('groups')->get($group)->get('answers')->properties());
        $this->conflicts(fn() => $commands->execute($this->acquire($group)));
    }

    public function test_that_scope_faults_and_missing_independent_intent_permission_fail_closed(): void
    {
        $fixture = new TaskInputAdmissionFixture();
        [$commands, $queries, $repository] = $this->buses($fixture, $this->database());
        [$group] = $this->issue($commands, $repository);
        $before = $this->snapshot($repository);
        $fixture->fault = true;
        $this->rejects(fn() => $commands->execute($this->supply([$group => $this->answer()])));
        $this->rejects(fn() => $commands->execute($this->acquire($group)));
        self::assertNull($queries->fetch(new GetTaskInput('scope', $this->id(), 'caller')));
        self::assertSame($before, $this->snapshot($repository));
        $fixture->fault = false;
        $fixture->submission = false;
        $this->rejects(fn() => $commands->execute($this->supply([$group => $this->answer()])));
        $fixture->observation = false;
        self::assertNull($queries->fetch(new GetTaskInput('scope', $this->id(), 'caller')));
        $missing = new TaskInputAdmissionFixture(['INPUT_OBSERVE', 'INPUT_CONTINUE']);
        $repositoryConnection = $this->database();
        [$missingCommands, $missingQueries] = $this->buses($missing, $repositoryConnection);
        self::assertNull($missingQueries->fetch(new GetTaskInput('scope', $this->id(), 'caller')));
        $this->rejects(fn() => $missingCommands->execute($this->acquire($group)));
        self::assertSame(0, $repositoryConnection->fetchOne('SELECT COUNT(*) FROM fight_tasks'));
    }

    public function test_that_issuance_retirement_and_terminal_paths_remain_independently_admitted(): void
    {
        $fixture = new TaskInputAdmissionFixture();
        $connection = $this->database();
        [$commands, $queries, $repository] = $this->buses($fixture, $connection);
        [$group, $second] = $this->issue($commands, $repository);
        $ungranted = new TaskInputAdmissionFixture(['TASK_UPDATE', 'TASK_READ', 'INPUT_SUBMIT']);
        [$denied] = $this->buses($ungranted, $connection);
        $this->rejects(fn() => $denied->execute(new RetireTaskInput('scope', $this->id(), 'producer', 2, $group)));
        $commands->execute(new RetireTaskInput('scope', $this->id(), 'producer', 2, $group));
        self::assertSame([], $this->observe($queries)->get('groups')->properties());
        $new = $this->request($commands, $repository, false, 3);
        self::assertNotContains($group, $new);
        self::assertNotContains($second, $new);
        $commands->execute(new RecordTaskState('scope', $this->id(), 'producer', 4, TaskState::create('cancelled')));
        $terminal = $this->snapshot($repository);
        $commands->execute($this->supply([$new[0] => 'invalid inactive response']));
        self::assertSame($terminal, $this->snapshot($repository));
        $fixture->submission = false;
        $this->rejects(fn() => $commands->execute($this->supply([])));
        $fixture->submission = true;
        $this->conflicts(fn() => $commands->execute($this->acquire($new[0])));
        self::assertFalse($this->observe($queries)->get('groups')->get($new[0])->get('ready'));
    }

    public function test_that_each_new_acquisition_and_effect_rechecks_original_authority_without_nonce_replay(): void
    {
        foreach (['revoked', 'rotated', 'permission', 'intent permission', 'definition', 'scope', 'fault'] as $change) {
            $fixture = new TaskInputAdmissionFixture();
            $connection = $this->database();
            [$commands, $queries, $repository] = $this->buses($fixture, $connection);
            [$group, $second] = $this->issue($commands, $repository, true);
            $commands->execute($this->supply([$group => $this->answer(), $second => $this->answer()]));
            $auth = $fixture->auth;
            $binding = AgentContinuationBinding::fromRequest($auth->provider, $auth->request, 'original');
            $state = new ControlledContinuationAuthority(
                $auth->agent,
                $auth->permissionRepository->getByIds($auth->agent->getPermissionIds())
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
            $effects = 0;
            $requirements = [
                PermissionName::fromString('INPUT_CONTINUE'), PermissionName::fromString('INTENT_OBSERVE')
            ];
            AgentTaskAuthority::duringContinuation(
                $guard,
                $binding,
                $requirements,
                $state,
                function (AgentTaskAuthority $authority) use ($fixture, $connection, $group, &$effects): void {
                    [$worker] = $this->buses($fixture, $connection, $authority);
                    $worker->execute($this->acquire($group));
                    ++$effects;
                }
            );
            self::assertSame(1, $effects);
            self::assertTrue($this->observe($queries)->get('groups')->get($group)->get('consumed'));
            // A later group is new work, not a retry token for the consumed admission.
            [$next, $last] = $this->request($commands, $repository, true, 4);
            $commands->execute($this->supply([$next => $this->answer(), $last => $this->answer()]));
            $agent = $auth->agent;
            match ($change) {
                'revoked' => $state->agent = $agent->revoke(TaskAdmissionFixture::now()),
                'rotated' => $state->agent = $agent->rotateRecoverableCredential(
                    $agent->getCredentialId(),
                    0,
                    AgentCredentialId::fromString('018f0000-0000-7000-8000-000000000999'),
                    'encrypted:successor',
                    TaskAdmissionFixture::now()
                ),
                'permission' => $state->agent = $agent->revokePermission(
                    $auth->permissionRepository->getByName(PermissionName::fromString('INPUT_CONTINUE'))->getId(),
                    TaskAdmissionFixture::now()
                ),
                'intent permission' => $state->agent = $agent->revokePermission(
                    $auth->permissionRepository->getByName(PermissionName::fromString('INTENT_OBSERVE'))->getId(),
                    TaskAdmissionFixture::now()
                ),
                'definition' => $state->definitions = [],
                'scope' => $state->scopeAllowed = false,
                'fault' => $state->agentFailure = new RuntimeException('Private authority fault.')
            };
            try {
                AgentTaskAuthority::duringContinuation(
                    $guard,
                    $binding,
                    $requirements,
                    $state,
                    function (AgentTaskAuthority $authority) use ($fixture, $connection, $next, &$effects): void {
                        [$worker] = $this->buses($fixture, $connection, $authority);
                        $worker->execute($this->acquire($next));
                        ++$effects;
                    }
                );
                self::fail('Changed original authority must deny the next step.');
            } catch (AgentContinuationRejectedException $failure) {
                self::assertNull($failure->getPrevious());
            }

            self::assertSame(1, $effects);
            self::assertFalse($this->observe($queries)->get('groups')->get($next)->get('consumed'));
            self::assertSame(1, $auth->nonce->consumptionCalls());
            self::assertFalse($state->held);
        }
    }

    public function test_that_real_precommit_failure_and_caller_transaction_preserve_unconsumed_admission(): void
    {
        $fixture = new TaskInputAdmissionFixture();
        $connection = $this->database();
        [$commands, $queries, $repository] = $this->buses($fixture, $connection);
        [$group, $second] = $this->issue($commands, $repository, true);
        $commands->execute($this->supply([$group => $this->answer(), $second => $this->answer()]));
        $before = $this->snapshot($repository);
        $connection->beginTransaction();
        try {
            $commands->execute($this->acquire($group));
            self::fail('Caller-owned transactions must be refused.');
        } catch (LogicException) {
            self::assertTrue($connection->isTransactionActive());
        } finally {
            $connection->rollBack();
        }

        $connection->executeStatement(
            "CREATE TRIGGER task_fail BEFORE INSERT ON fight_tasks BEGIN SELECT RAISE(ABORT, 'private'); END"
        );
        try {
            $commands->execute($this->acquire($group));
            self::fail('Actual storage failure must roll back acquisition.');
        } catch (TaskStorageFailed $taskStorageFailed) {
            self::assertFalse($taskStorageFailed->outcomeUncertain());
        }

        self::assertSame($before, $this->snapshot($repository));
        self::assertTrue($this->observe($queries)->get('groups')->get($group)->get('ready'));
    }

    public function test_that_both_lost_commit_outcomes_require_authorized_reconciliation_not_blind_execution(): void
    {
        foreach ([false, true] as $committed) {
            $path = tempnam(sys_get_temp_dir(), 'task-input-');
            self::assertIsString($path);
            $params = ['driver' => 'pdo_sqlite', 'path' => $path];
            $connection = DriverManager::getConnection($params);
            $uncertain = new class ($params, $connection->getDriver()) extends Connection {
                public bool $committed = false;

                public function commit(): void
                {
                    if ($this->committed) {
                        parent::commit();
                    } else {
                        parent::rollBack();
                    }

                    throw new RuntimeException('Private lost acknowledgment.');
                }
            };
            $uncertain->committed = $committed;
            try {
                new DbalTaskSchema()->install($connection);
                $fixture = new TaskInputAdmissionFixture();
                [$commands, , $repository] = $this->buses($fixture, $connection);
                [$group, $second] = $this->issue($commands, $repository, true);
                $commands->execute($this->supply([$group => $this->answer(), $second => $this->answer()]));
                [$worker] = $this->buses($fixture, $uncertain);
                try {
                    $worker->execute($this->acquire($group));
                    self::fail('Lost acknowledgment never approves an effect.');
                } catch (TaskStorageFailed $failure) {
                    self::assertTrue($failure->outcomeUncertain());
                }

                $fresh = DriverManager::getConnection($params);
                try {
                    [$retry, $read] = $this->buses($fixture, $fresh);
                    self::assertSame($committed, $this->observe($read)->get('groups')->get($group)->get('consumed'));
                    if ($committed) {
                        $this->conflicts(fn() => $retry->execute($this->acquire($group)));
                    } else {
                        $retry->execute($this->acquire($group));
                        self::assertTrue($this->observe($read)->get('groups')->get($group)->get('consumed'));
                    }
                } finally {
                    $fresh->close();
                }
            } finally {
                $uncertain->close();
                $connection->close();
                unlink($path);
            }
        }
    }

    public function test_that_actual_tasks_update_uses_the_same_admission_and_safe_acknowledgment(): void
    {
        $fixture = new TaskInputAdmissionFixture();
        [$commands, $queries, $repository] = $this->buses($fixture, $this->database());
        [$group] = $this->issue($commands, $repository);
        $method = new McpTaskInput($commands, 'scope', 'caller');
        $metadata = McpRequestMetadata::fromObject(StrictJson::fromObject([
            McpRequestMetadata::PROTOCOL_VERSION_KEY    => '2025-11-25',
            McpRequestMetadata::CLIENT_CAPABILITIES_KEY => (object) [
                'extensions'  => (object) ['io.modelcontextprotocol/tasks' => (object) []],
                'elicitation' => (object) ['form' => (object) []]
            ]
        ]));
        $request = new McpRequest(1, 'tasks/update', [
            'taskId'         => TaskAdmissionFixture::ID,
            'inputResponses' => (object) [$group => $this->answer()]
        ], $metadata);
        $fixture->submission = false;
        try {
            $method->handle($request);
            self::fail('Capability negotiation and valid input confer no submission permission.');
        } catch (McpProtocolException $mcpProtocolException) {
            self::assertStringNotContainsString('Private', $mcpProtocolException->getMessage());
        }

        $fixture->submission = true;
        self::assertSame(['resultType' => 'complete'], $method->handle($request)->toArray());
        self::assertSame(7, $this->observe($queries)->get('groups')->get($group)
            ->get('answers')->get($group)->get('content')->get('value'));
        self::assertSame(1, $fixture->auth->nonce->consumptionCalls());
    }

    public function test_that_real_competing_answers_and_terminal_producers_keep_one_coherent_winner(): void
    {
        foreach (['answer', 'terminal'] as $mode) {
            $path = tempnam(sys_get_temp_dir(), 'task-input-race-');
            self::assertIsString($path);
            $readyOne = $path.'.one';
            $readyTwo = $path.'.two';
            $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $path]);
            new DbalTaskSchema()->install($connection);
            $fixture = new TaskInputAdmissionFixture();
            [$commands, $queries, $repository] = $this->buses($fixture, $connection);
            [$group, $second] = $this->issue($commands, $repository);
            $connection->beginTransaction();
            $connection->executeStatement('UPDATE fight_task_state SET version = version WHERE singleton = 1');
            $code = 'require "vendor/autoload.php"; ';
            $code .= TaskInputRaceWorker::class.'::run(';
            $code .= '$argv[1], $argv[2], $argv[3], (int) $argv[4], $argv[5]);';
            $one = new Process([PHP_BINARY, '-r', $code, $path, 'answer', $group, '7', $readyOne]);
            $two = new Process([PHP_BINARY, '-r', $code, $path, $mode, $group, '9', $readyTwo]);
            try {
                $one->start();
                $two->start();
                $deadline = microtime(true) + 5;
                while ((!is_file($readyOne) || !is_file($readyTwo)) && microtime(true) < $deadline) {
                    usleep(1000);
                }

                self::assertFileExists($readyOne);
                self::assertFileExists($readyTwo);
                $connection->commit();
                self::assertSame(0, $one->wait(), $one->getErrorOutput());
                self::assertSame(0, $two->wait(), $two->getErrorOutput());
                $task = $repository->read('scope', $this->id());
                $observed = $this->observe($queries)->get('groups')->get($group);
                self::assertFalse($observed->get('consumed'));
                self::assertFalse($observed->get('answers')->has($second));
                if ($task->state()->isTerminal()) {
                    self::assertSame('cancelled', $task->state()->status());
                    self::assertSame([], $observed->get('answers')->properties());
                } else {
                    self::assertContains($observed->get('answers')->get($group)->get('content')->get('value'), [7, 9]);
                    self::assertSame([$second], array_keys($task->inputs()->active('caller', $this->now)));
                    self::assertSame(3, $task->version(), 'Conflicting duplicates never replace accepted evidence.');
                }
            } finally {
                if ($connection->isTransactionActive()) {
                    $connection->rollBack();
                }

                $one->stop();
                $two->stop();
                $connection->close();
                foreach ([$readyOne, $readyTwo, $path] as $file) {
                    if (is_file($file)) {
                        unlink($file);
                    }
                }
            }
        }
    }

    public function test_that_denied_submission_never_reads_retained_contracts_or_runs_payload_validation(): void
    {
        $fixture = new TaskInputAdmissionFixture(['TASK_READ']);
        $connection = $this->database();
        $repository = $this->createMock(TaskRepository::class);
        $repository->expects(self::never())->method('getById');
        $repository->expects(self::never())->method('save');
        $policy = $this->createMock(TaskPayloadPolicy::class);
        $policy->expects(self::never())->method('validate');
        $handler = new SupplyTaskInputHandler(
            $repository,
            new DbalTaskTransaction($connection),
            $fixture->input(),
            $policy,
            new SimpleEventDispatcher(),
            fn(): int => $this->now
        );
        $router = new InMemoryCommandRouter();
        $router->registerHandler($handler::commandRegistration(), $handler);

        $bus = new RoutingCommandBus($router);
        $this->rejects(fn() => $bus->execute($this->supply(['forged' => 'invalid protected response'])));
        self::assertSame(0, $fixture->checks);
        self::assertSame(1, $fixture->auth->nonce->consumptionCalls());
    }

    public function test_that_consumer_failure_after_confirmed_acquisition_never_restores_admission(): void
    {
        $fixture = new TaskInputAdmissionFixture();
        [$commands, $queries, $repository] = $this->buses($fixture, $this->database());
        [$group, $second] = $this->issue($commands, $repository, true);
        $commands->execute($this->supply([$group => $this->answer(), $second => $this->answer()]));
        $commands->execute($this->acquire($group));

        $primary = new RuntimeException('Controlled consumer effect failure.');
        try {
            throw $primary;
        } catch (RuntimeException $runtimeException) {
            self::assertSame($primary, $runtimeException);
            self::assertTrue($this->observe($queries)->get('groups')->get($group)->get('consumed'));
        }

        $this->conflicts(fn() => $commands->execute($this->acquire($group)));
    }

    public function test_that_retained_capacity_rejects_atomically_without_truncation_or_key_reuse(): void
    {
        $fixture = new TaskInputAdmissionFixture();
        [$commands, $queries, $repository] = $this->buses($fixture, $this->database());
        $keys = $this->issue($commands, $repository);
        for ($version = 2; $version < 9; ++$version) {
            $keys = [...$keys, ...$this->request($commands, $repository, false, $version)];
        }

        // request() returns every currently active key; the actual retained set still has exactly 16 distinct keys.
        $task = $repository->read('scope', $this->id());
        self::assertCount(16, $task->inputs()->active('caller', $this->now));
        $before = $this->snapshot($repository);
        $this->conflicts(fn(): array => $this->request($commands, $repository, false, 9));
        self::assertSame($before, $this->snapshot($repository));
        $outstanding = $queries->fetch(new GetTask('scope', $this->id()))->get('state')->get('input');
        self::assertCount(16, $outstanding->properties());
        self::assertCount(16, array_unique($keys));
    }

    private function database(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        new DbalTaskSchema()->install($connection);

        return $connection;
    }

    /** @return array{RoutingCommandBus, RoutingQueryBus, DbalTaskRepository} */
    private function buses(
        TaskInputAdmissionFixture $fixture,
        Connection $connection,
        ?AgentTaskAuthority $continuationAuthority = null
    ): array {
        $transaction = new DbalTaskTransaction($connection);
        $repository = new DbalTaskRepository($transaction);
        $events = new SimpleEventDispatcher();
        $events->addHandler(ClassName::underscore(TaskChanged::class), function (EventMessage $message): void {
            ++$this->events;
            if ($this->publicationFails) {
                throw new RuntimeException('Private publisher failure.');
            }
        });
        $payload = new ToolTaskPayloadPolicy(static fn(): bool => true);
        $clock = fn(): int => $this->now;
        $auth = $fixture->auth;
        $issuer = new AgentTaskUpdateAdmission($auth->authority('INPUT_ISSUE'), $auth);
        $input = $fixture->input();
        if ($continuationAuthority !== null) {
            $input = new AgentTaskInputAdmission(
                $auth->authority('INPUT_SUBMIT'),
                $auth->authority('INPUT_OBSERVE'),
                $continuationAuthority,
                $fixture
            );
        }

        $intent = $fixture->cancellation();
        if ($continuationAuthority !== null) {
            $intent = new AgentTaskCancellationObservationAdmission($continuationAuthority, $fixture);
        }

        $router = new InMemoryCommandRouter();
        $handlers = [
            new CreateTaskHandler(
                $repository,
                $transaction,
                new AgentTaskCreationAdmission($auth->authority('TASK_CREATE'), $auth),
                $payload,
                $events,
                $clock
            ),
            new RecordTaskStateHandler(
                $repository,
                $transaction,
                new AgentTaskUpdateAdmission($auth->authority('TASK_UPDATE'), $auth),
                $payload,
                $events,
                $clock
            ),
            new RequestTaskInputHandler($repository, $transaction, $issuer, $payload, $events, $clock),
            new RetireTaskInputHandler($repository, $transaction, $issuer, $payload, $events, $clock),
            new SupplyTaskInputHandler($repository, $transaction, $input, $payload, $events, $clock),
            new AcquireTaskInputHandler($repository, $transaction, $input, $intent, $payload, $events, $clock)
        ];
        foreach ($handlers as $handler) {
            $router->registerHandler($handler::commandRegistration(), $handler);
        }

        $queryRouter = new InMemoryQueryRouter();
        $read = new GetTaskHandler(
            $repository,
            new AgentTaskReadAdmission($auth->authority('TASK_READ'), $auth),
            $payload,
            $clock
        );
        $observe = new GetTaskInputHandler($repository, $input, $intent, $clock);
        $queryRouter->registerHandler($read::queryRegistration(), $read);
        $queryRouter->registerHandler($observe::queryRegistration(), $observe);

        return [new RoutingCommandBus($router), new RoutingQueryBus($queryRouter), $repository];
    }

    /** @return list<string> */
    private function issue(RoutingCommandBus $commands, DbalTaskRepository $repository, bool $confirm = false): array
    {
        $commands->execute(new CreateTask(TaskAdmissionFixture::definition(), TaskState::create('working')));

        return $this->request($commands, $repository, $confirm, 1);
    }

    /** @return list<string> */
    private function request(
        RoutingCommandBus $commands,
        DbalTaskRepository $repository,
        bool $confirm,
        int $version
    ): array {
        $form = McpInputRequest::form('Approve operation', [
            'type'       => 'object',
            'properties' => (object) ['value' => (object) ['type' => 'integer']],
            'required'   => ['value']
        ], [['field' => 'value', 'label' => 'Private rule label', 'rules' => 'required']]);
        $forms = ['first' => $form, 'second' => $form];
        $input = $confirm ? McpInputRequired::confirmation($forms) : McpInputRequired::fromRequests($forms);
        $commands->execute(new RequestTaskInput(
            'scope',
            $this->id(),
            'producer',
            $version,
            TaskInputForms::batch($input, 'caller', $this->now + 500)
        ));

        return array_keys($repository->read('scope', $this->id())->inputs()->active('caller', $this->now));
    }

    private function observe(RoutingQueryBus $queries): StrictJson
    {
        $view = $queries->fetch(new GetTaskInput('scope', $this->id(), 'caller'));
        self::assertInstanceOf(StrictJson::class, $view);

        return $view;
    }

    private function snapshot(DbalTaskRepository $repository): string
    {
        return StrictJson::fromObject($repository->read('scope', $this->id())->toArray(), 72)->toString();
    }

    private function id(): TaskId
    {
        return TaskId::fromString(TaskAdmissionFixture::ID);
    }

    private function acquire(string $group): AcquireTaskInput
    {
        return new AcquireTaskInput('scope', $this->id(), 'caller', $group);
    }

    /** @param array<string, mixed> $responses */
    private function supply(array $responses): SupplyTaskInput
    {
        return new SupplyTaskInput('scope', $this->id(), 'caller', StrictJson::fromObject($responses));
    }

    private function answer(string $action = 'accept', mixed $value = 7): StrictJson
    {
        if ($action !== 'accept') {
            return StrictJson::fromObject(['action' => $action]);
        }

        return StrictJson::fromObject(['action' => $action, 'content' => (object) ['value' => $value]]);
    }

    private function rejects(Closure $action): void
    {
        try {
            $action();
            self::fail('Expected safe rejection.');
        } catch (TaskRejected $taskRejected) {
            self::assertSame('Task operation rejected.', $taskRejected->getMessage());
            self::assertNull($taskRejected->getPrevious());
        }
    }

    private function conflicts(Closure $action): void
    {
        try {
            $action();
            self::fail('Expected retained-state conflict.');
        } catch (TaskConflict $taskConflict) {
            self::assertSame('Task state conflicts.', $taskConflict->getMessage());
        }
    }
}
