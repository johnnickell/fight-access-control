<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Task;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Fight\AccessControl\Application\AccessControl\Agent\Task\AgentTaskCancellationAdmission;
use Fight\Common\Adapter\Mcp\Persistence\Task\Dbal\DbalTaskSchema;
use Fight\Common\Adapter\Mcp\Persistence\Task\Dbal\DbalTaskTransaction;
use Fight\Common\Adapter\Messaging\Command\Sync\Routing\InMemoryCommandRouter;
use Fight\Common\Adapter\Messaging\Command\Sync\RoutingCommandBus;
use Fight\Common\Adapter\Messaging\Event\Sync\SimpleEventDispatcher;
use Fight\Common\Application\Mcp\Protocol\Exception\McpProtocolException;
use Fight\Common\Application\Mcp\Protocol\McpRequest;
use Fight\Common\Application\Mcp\Protocol\McpRequestMetadata;
use Fight\Common\Application\Mcp\Task\CommandHandler\RequestTaskCancellationHandler;
use Fight\Common\Application\Mcp\Task\Exception\CommittedTaskDispatchFailed;
use Fight\Common\Application\Mcp\Task\Exception\TaskConflict;
use Fight\Common\Application\Mcp\Task\Exception\TaskRejected;
use Fight\Common\Application\Mcp\Task\Exception\TaskStorageFailed;
use Fight\Common\Application\Mcp\Task\McpTaskCancellation;
use Fight\Common\Application\Mcp\Task\Service\CommittedTaskReader;
use Fight\Common\Domain\Mcp\Task\Command\RequestTaskCancellation;
use Fight\Common\Domain\Mcp\Task\Query\GetTaskCancellation;
use Fight\Common\Domain\Mcp\Task\TaskId;
use Fight\Common\Domain\Mcp\Task\TaskRepository;
use Fight\Common\Domain\Value\Basic\StrictJson;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;

#[CoversClass(AgentTaskCancellationAdmission::class)]
final class AgentTaskCancellationAdmissionTest extends TestCase
{
    public function test_that_actual_permissions_are_independent_and_precede_scope(): void
    {
        $grants = ['TASK_CANCEL', 'INTENT_OBSERVE', 'TASK_READ', 'TASK_CREATE', 'TASK_UPDATE', 'INPUT_CONTINUE'];
        foreach ($grants as $grant) {
            $fixture = new TaskCancellationFixture($this->database(), [$grant]);
            $auth = $fixture->input->auth;
            $admission = new AgentTaskCancellationAdmission(
                $auth->authority('TASK_CANCEL'),
                $fixture,
                $auth->authority('INTENT_OBSERVE'),
                $fixture->input
            );
            self::assertSame(
                $grant === 'TASK_CANCEL',
                $admission->allowsCancellation('scope', $fixture::id(), 'caller')
            );
            self::assertSame(
                $grant === 'INTENT_OBSERVE',
                $admission->allowsCancellationObservation('scope', $fixture::id(), 'caller')
            );
            self::assertSame((int) ($grant === 'TASK_CANCEL'), $fixture->checks);
            self::assertSame((int) ($grant === 'INTENT_OBSERVE'), $fixture->input->checks);
            self::assertSame(1, $auth->nonce->consumptionCalls());
        }
    }

    public function test_that_denied_authority_never_reads_or_mutates_protected_state(): void
    {
        $connection = $this->database();
        $fixture = new TaskCancellationFixture($connection, ['TASK_READ', 'INTENT_OBSERVE']);
        $auth = $fixture->input->auth;
        $admission = new AgentTaskCancellationAdmission(
            $auth->authority('TASK_CANCEL'),
            $fixture,
            $auth->authority('INTENT_OBSERVE'),
            $fixture->input
        );
        $repository = $this->createMock(TaskRepository::class);
        $repository->expects(self::never())->method('getById');
        $repository->expects(self::never())->method('save');
        $reader = $this->createMock(CommittedTaskReader::class);
        $reader->expects(self::never())->method('read');
        $handler = new RequestTaskCancellationHandler(
            $repository,
            new DbalTaskTransaction($connection),
            $reader,
            $admission,
            new SimpleEventDispatcher(),
            fn(): int => $fixture->now
        );
        $router = new InMemoryCommandRouter();
        $router->registerHandler($handler::commandRegistration(), $handler);

        $bus = new RoutingCommandBus($router);
        $this->rejects(fn() => $bus->execute(new RequestTaskCancellation('scope', $fixture::id(), 'caller')));
        self::assertSame(0, $fixture->checks);
    }

    public function test_that_adapter_scope_faults_propagate_to_the_owning_common_safe_boundary(): void
    {
        $fixture = new TaskCancellationFixture($this->database());
        $auth = $fixture->input->auth;
        $admission = new AgentTaskCancellationAdmission(
            $auth->authority('TASK_CANCEL'),
            $fixture,
            $auth->authority('INTENT_OBSERVE'),
            $fixture->input
        );
        $fixture->fault = true;
        $fixture->input->fault = true;
        foreach (['allowsCancellation', 'allowsCancellationObservation'] as $method) {
            try {
                $admission->$method('scope', $fixture::id(), 'caller');
                self::fail('Adapter must not convert operational faults into Boolean success or denial.');
            } catch (RuntimeException $failure) {
                self::assertStringContainsString('Private', $failure->getMessage());
            }
        }
    }

    public function test_that_first_repeat_terminal_and_missing_paths_reauthorize_without_disclosure(): void
    {
        foreach (['missing', 'working', 'completed', 'failed', 'cancelled', 'repeat'] as $state) {
            $fixture = new TaskCancellationFixture($this->database());
            if ($state !== 'missing') {
                $fixture->create();
                if ($state === 'repeat') {
                    $fixture->cancel();
                } elseif ($state !== 'working') {
                    $fixture->record($state);
                }
            }

            $before = $fixture->connection->fetchOne('SELECT payload FROM fight_tasks');
            $events = $fixture->events;
            foreach (['deny', 'fault'] as $refusal) {
                $fixture->allowed = $refusal !== 'deny';
                $fixture->fault = $refusal === 'fault';
                $this->rejects($fixture->cancel(...));
                self::assertSame($before, $fixture->connection->fetchOne('SELECT payload FROM fight_tasks'));
                self::assertSame($events, $fixture->events);
            }

            self::assertGreaterThanOrEqual(2, $fixture->checks);
            $fixture->allowed = true;
            $fixture->fault = false;
            if ($state === 'missing') {
                $this->rejects($fixture->cancel(...));
                self::assertSame(0, $fixture->connection->fetchOne('SELECT COUNT(*) FROM fight_tasks'));
            } else {
                $fixture->cancel();
                if ($state !== 'working') {
                    self::assertSame($before, $fixture->snapshot());
                    self::assertSame($events, $fixture->events);
                }
            }
        }
    }

    public function test_that_wrong_scope_caller_identity_and_throwing_observation_are_concealed(): void
    {
        $fixture = new TaskCancellationFixture($this->database());
        $fixture->create();
        $fixture->cancel();

        $before = $fixture->snapshot();
        foreach (
            [['other', $fixture::id(), 'caller'], ['scope', $fixture::id(), 'other'],
                ['scope', TaskId::fromString(str_repeat('a', 32)), 'caller']] as [$scope, $id, $caller]
        ) {
            $this->rejects(fn() => $fixture->commandBus->execute(new RequestTaskCancellation($scope, $id, $caller)));
            self::assertNull($fixture->queryBus->fetch(new GetTaskCancellation($scope, $id, $caller)));
        }

        $fixture->input->intent = false;
        self::assertNull($fixture->intent());
        $fixture->input->intent = true;
        $fixture->input->fault = true;
        self::assertNull($fixture->intent());
        self::assertSame($before, $fixture->snapshot());
        $mutationOnly = new TaskCancellationFixture($fixture->connection, ['TASK_CANCEL']);
        $mutationOnly->cancel();
        self::assertNull($mutationOnly->intent());
        self::assertNull($mutationOnly->answers());
        self::assertSame($before, $fixture->snapshot());
    }

    public function test_that_each_new_request_observes_revocation_but_one_request_retains_its_snapshot(): void
    {
        $fixture = new TaskCancellationFixture($this->database());
        $fixture->create();

        $auth = $fixture->input->auth;
        $fixture->cancel();
        $revoked = $auth->agent->revoke(TaskAdmissionFixture::now());
        $auth->agentRepository->restoreSnapshot([$revoked]);
        $fixture->cancel();
        self::assertNotNull($fixture->intent());
        self::assertSame(1, $auth->nonce->consumptionCalls());
        $fresh = new TaskAdmissionFixture(['TASK_CANCEL', 'INTENT_OBSERVE']);
        $fresh->agentRepository->restoreSnapshot([$fresh->agent->revoke(TaskAdmissionFixture::now())]);

        $admission = new AgentTaskCancellationAdmission(
            $fresh->authority('TASK_CANCEL'),
            $fixture,
            $fresh->authority('INTENT_OBSERVE'),
            $fixture->input
        );
        self::assertFalse($admission->allowsCancellation('scope', $fixture::id(), 'caller'));
        self::assertFalse($admission->allowsCancellationObservation('scope', $fixture::id(), 'caller'));
    }

    public function test_that_intent_is_durable_before_ack_and_repeat_neither_renews_ttl_nor_consumption(): void
    {
        $fixture = new TaskCancellationFixture($this->database());
        $fixture->create(10000);
        [$group] = $fixture->issue();
        $fixture->supply($group);
        $fixture->acquire($group);

        $inputs = $fixture->task()->inputs()->data()->toString();
        $created = $fixture->task()->toArray()['created_at'];
        $fixture->cancel();
        $intent = $fixture->intent()->get('cancellation')->toString();
        self::assertSame($inputs, $fixture->task()->inputs()->data()->toString());
        $before = $fixture->snapshot();
        $events = $fixture->events;
        $fixture->now += 100;
        $fixture->cancel();
        self::assertSame($before, $fixture->snapshot());
        self::assertSame($events, $fixture->events);
        self::assertSame($created, $fixture->task()->toArray()['created_at']);
        self::assertSame(10000, $fixture->task()->toArray()['ttl']);
        $this->conflicts(fn() => $fixture->acquire($group));
        $fixture->record('completed');
        self::assertSame($intent, $fixture->intent()->get('cancellation')->toString());
        self::assertTrue($fixture->answers()->get('groups')->get($group)->get('consumed'));
        $fixture->now += 10000;
        self::assertNull($fixture->intent());
        $this->rejects($fixture->cancel(...));
        self::assertSame('completed', $fixture->task()->state()->status());
    }

    public function test_that_intent_does_not_refuse_later_input_or_issue_new_stopping_obligations(): void
    {
        $fixture = new TaskCancellationFixture($this->database());
        $fixture->create();
        [$first] = $fixture->issue();
        $fixture->cancel();
        $intent = $fixture->intent()->get('cancellation')->toString();
        self::assertSame('input_required', $fixture->task()->state()->status());
        $fixture->supply($first);
        $fixture->acquire($first);
        [$second] = $fixture->issue(false);
        self::assertNotSame($first, $second);
        $fixture->supply($second);
        $fixture->cancel();
        self::assertSame($intent, $fixture->answers()->get('cancellation')->toString());
        self::assertTrue($fixture->answers()->get('groups')->get($first)->get('consumed'));
        self::assertTrue($fixture->answers()->get('groups')->get($second)->get('ready'));
        self::assertSame('working', $fixture->task()->state()->status());
    }

    public function test_that_rollback_and_caller_work_remain_separate_from_intent(): void
    {
        $connection = $this->database();
        $fixture = new TaskCancellationFixture($connection);
        $fixture->create();

        $before = $fixture->snapshot();
        $connection->executeStatement(
            "CREATE TRIGGER fail_intent BEFORE INSERT ON fight_tasks BEGIN SELECT RAISE(ABORT, 'private'); END"
        );
        try {
            $fixture->cancel();
            self::fail('Real write failure cannot acknowledge intent.');
        } catch (TaskStorageFailed $taskStorageFailed) {
            self::assertFalse($taskStorageFailed->outcomeUncertain());
        }

        self::assertSame($before, $fixture->snapshot());
        $connection->executeStatement('DROP TRIGGER fail_intent');
        $connection->executeStatement('CREATE TABLE caller_work (id INTEGER)');
        $connection->beginTransaction();
        $connection->insert('caller_work', ['id' => 7]);
        try {
            $fixture->cancel();
            self::fail('No enclosing caller transaction.');
        } catch (LogicException) {
            self::assertTrue($connection->isTransactionActive());
            self::assertSame(7, $connection->fetchOne('SELECT id FROM caller_work'));
        }

        $connection->commit();
        self::assertSame(7, $connection->fetchOne('SELECT id FROM caller_work'));
        self::assertSame($before, $fixture->snapshot());
    }

    public function test_that_both_lost_commit_outcomes_need_fresh_authorized_reconciliation(): void
    {
        foreach ([false, true] as $committed) {
            $path = tempnam(sys_get_temp_dir(), 'task-cancel-');
            $params = ['driver' => 'pdo_sqlite', 'path' => $path];
            $connection = DriverManager::getConnection($params);
            new DbalTaskSchema()->install($connection);
            $fixture = new TaskCancellationFixture($connection);
            $fixture->create();
            $uncertain = new class ($params, $connection->getDriver()) extends Connection {
                public bool $committed;

                public function commit(): void
                {
                    if ($this->committed) {
                        parent::commit();
                    } else {
                        parent::rollBack();
                    }

                    throw new RuntimeException('Private lost commit response.');
                }
            };
            $uncertain->committed = $committed;
            try {
                $candidate = new TaskCancellationFixture($uncertain);
                try {
                    $candidate->cancel();
                    self::fail('Uncertain commit cannot acknowledge.');
                } catch (TaskStorageFailed $failure) {
                    self::assertTrue($failure->outcomeUncertain());
                }

                $freshConnection = DriverManager::getConnection($params);
                $fresh = new TaskCancellationFixture($freshConnection);
                self::assertSame($committed, $fresh->intent()->get('cancellation') !== null);
                $fresh->cancel();
                self::assertNotNull($fresh->intent()->get('cancellation'));
                self::assertSame($committed ? 0 : 1, $fresh->events);
                $freshConnection->close();
            } finally {
                $uncertain->close();
                $connection->close();
                unlink($path);
            }
        }
    }

    public function test_that_postcommit_read_or_dispatch_failure_withholds_ack_without_erasing_intent(): void
    {
        foreach (['read', 'dispatch', 'missing'] as $failure) {
            $connection = $this->database();
            $fixture = new TaskCancellationFixture($connection);
            $fixture->create();
            $reader = $this->createStub(CommittedTaskReader::class);
            $reader->method('read')->willReturnCallback(static function () use ($failure): null {
                if ($failure === 'read') {
                    throw new RuntimeException('Private committed reader cause.');
                }

                return null;
            });
            $candidate = new TaskCancellationFixture(
                $connection,
                committedReader: $failure === 'dispatch' ? null : $reader
            );
            $candidate->publicationFails = $failure === 'dispatch';
            try {
                $candidate->cancel();
                self::fail('No false acknowledgment after committed fault.');
            } catch (CommittedTaskDispatchFailed) {
                self::assertSame('dispatch', $failure);
            } catch (TaskStorageFailed $fault) {
                self::assertTrue($fault->outcomeUncertain());
            }

            self::assertNotNull($fixture->intent()->get('cancellation'));
            $before = $fixture->snapshot();
            $fixture->cancel();
            self::assertSame($before, $fixture->snapshot());
            self::assertSame(1, $fixture->events, 'Retry does not reconstruct lost publication.');
        }
    }

    public function test_that_actual_method_bounds_and_safe_ack_reveal_no_state_or_intent(): void
    {
        $fixture = new TaskCancellationFixture($this->database());
        $fixture->create();

        $metadata = McpRequestMetadata::fromObject(StrictJson::fromObject([
            McpRequestMetadata::PROTOCOL_VERSION_KEY    => '2025-11-25',
            McpRequestMetadata::CLIENT_CAPABILITIES_KEY => (object) [
                'extensions' => (object) ['io.modelcontextprotocol/tasks' => (object) []]
            ]
        ]));
        $method = new McpTaskCancellation($fixture->commandBus, 'scope', 'caller');
        $invalid = [[], ['taskId' => str_repeat('a', 33)], ['taskId' => TaskAdmissionFixture::ID, 'extra' => true]];
        foreach ($invalid as $params) {
            try {
                $method->handle(new McpRequest(1, 'tasks/cancel', $params, $metadata));
                self::fail('Invalid bounded parameters.');
            } catch (McpProtocolException $failure) {
                self::assertStringNotContainsString('private', $failure->getMessage());
            }
        }

        $request = new McpRequest(1, 'tasks/cancel', ['taskId' => TaskAdmissionFixture::ID], $metadata);
        $fixture->allowed = false;
        try {
            $method->handle($request);
            self::fail('Negotiation grants no authority.');
        } catch (McpProtocolException $mcpProtocolException) {
            self::assertStringNotContainsString('private', $mcpProtocolException->getMessage());
        }

        $fixture->allowed = true;
        self::assertSame(['resultType' => 'complete'], $method->handle($request)->toArray());
        $before = $fixture->snapshot();
        // Simulate response loss: a fresh method instance retries the original request against retained state.
        $retryMethod = new McpTaskCancellation($fixture->commandBus, 'scope', 'caller');
        self::assertSame(['resultType' => 'complete'], $retryMethod->handle($request)->toArray());
        self::assertSame($before, $fixture->snapshot());
        self::assertSame(2, $fixture->events);
        $fixture->record('completed');
        $terminal = $fixture->snapshot();
        self::assertSame(['resultType' => 'complete'], $method->handle($request)->toArray());
        self::assertSame($terminal, $fixture->snapshot());
        foreach (['notifications/cancelled', 'tasks/get'] as $wrongMethod) {
            try {
                $method->handle(new McpRequest(1, $wrongMethod, ['taskId' => TaskAdmissionFixture::ID], $metadata));
                self::fail('Request-progress cancellation cannot mutate durable intent.');
            } catch (McpProtocolException $failure) {
                self::assertStringNotContainsString('private', $failure->getMessage());
            }
        }
    }

    public function test_that_cross_process_observation_is_not_stopping_or_an_actual_outcome(): void
    {
        foreach (['completed', 'failed', 'cancelled'] as $outcome) {
            $path = tempnam(sys_get_temp_dir(), 'task-cancel-observe-');
            $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $path]);
            new DbalTaskSchema()->install($connection);
            $fixture = new TaskCancellationFixture($connection);
            try {
                $fixture->create();
                [$group] = $fixture->issue();
                $fixture->cancel();
                $code = 'require "vendor/autoload.php"; '.TaskCancellationRaceWorker::class.'::observe($argv[1]);';
                $observer = new Process([PHP_BINARY, '-r', $code, $path]);
                $observer->mustRun();
                $observed = json_decode($observer->getOutput(), true, flags: JSON_THROW_ON_ERROR);
                self::assertNotSame(getmypid(), $observed['pid']);
                self::assertNotNull($observed['intent']['cancellation']);
                self::assertSame('input_required', $fixture->task()->state()->status());
                $fixture->supply($group);
                $fixture->acquire($group);
                $attempts = 0;
                $stop = static function () use ($connection, $outcome, &$attempts): string {
                    self::assertFalse($connection->isTransactionActive());
                    ++$attempts;
                    if ($outcome === 'failed') {
                        throw new RuntimeException('Controlled lost external response.');
                    }

                    return 'stopped';
                };
                $effect = 'completed-despite-intent';
                if ($outcome !== 'completed') {
                    try {
                        $effect = $stop();
                    } catch (RuntimeException) {
                        $effect = 'uncertain-external-response';
                    }
                }

                $fixture->record($outcome);
                $before = $fixture->snapshot();
                $fixture->cancel();
                self::assertSame($before, $fixture->snapshot());
                self::assertSame($outcome, $fixture->task()->state()->status());
                self::assertSame($outcome === 'completed' ? 0 : 1, $attempts);
                self::assertSame(match ($outcome) {
                    'completed' => 'completed-despite-intent',
                    'failed' => 'uncertain-external-response',
                    default => 'stopped'
                }, $effect);
                self::assertTrue($fixture->answers()->get('groups')->get($group)->get('consumed'));
                $this->conflicts(fn() => $fixture->acquire($group));
            } finally {
                $connection->close();
                unlink($path);
            }
        }
    }

    #[DataProvider('races')]
    public function test_that_real_lock_order_preserves_intent_answers_consumption_and_terminal_winner(
        string $first,
        string $second,
        bool $priorIntent,
        bool $denyWaitingCancellation = false
    ): void {
        $path = tempnam(sys_get_temp_dir(), 'task-cancel-race-');
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $path]);
        new DbalTaskSchema()->install($connection);
        $fixture = new TaskCancellationFixture($connection);
        $fixture->create();
        [$group] = $fixture->issue();
        if ($priorIntent) {
            $fixture->cancel();
        }

        if (in_array('acquire', [$first, $second], true)) {
            $fixture->supply($group);
        }

        $version = $fixture->task()->version();
        $code = 'require "vendor/autoload.php"; '.TaskCancellationRaceWorker::class;
        $code .= '::run($argv[1], $argv[2], $argv[3], (int) $argv[4], $argv[5]);';
        $one = new Process([PHP_BINARY, '-r', $code, $path, $first, $group, (string) $version, $path.'.first']);
        $two = new Process([PHP_BINARY, '-r', $code, $path, $second, $group, (string) $version, $path.'.second']);
        try {
            $one->start();
            $this->waitFor($path.'.first.precommit', $one);
            $two->start();
            $this->waitFor($path.'.second.started', $two);
            usleep(100000);
            self::assertTrue($two->isRunning(), $two->getErrorOutput());
            self::assertFileDoesNotExist($path.'.second.precommit');
            if ($denyWaitingCancellation) {
                self::assertFileDoesNotExist($path.'.cancel-admitted');
                file_put_contents($path.'.deny', 'denied');
            }

            file_put_contents($path.'.first.release', 'release');
            self::assertSame(0, $one->wait(), $one->getErrorOutput());
            self::assertSame(0, $two->wait(), $two->getErrorOutput());
            $a = json_decode($one->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $b = json_decode($two->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            self::assertNotSame($a['pid'], $b['pid']);
            self::assertNotSame(getmypid(), $a['pid']);
            self::assertSame('committed', $a['status']);
            $terminalFirst = in_array($first, ['completed', 'failed', 'cancelled'], true);
            $terminalSecond = in_array($second, ['completed', 'failed', 'cancelled'], true);
            $conflict = $terminalSecond || $terminalFirst && $second === 'acquire'
                || $first === 'cancel' && $second === 'issue';
            self::assertSame(
                $denyWaitingCancellation ? TaskRejected::class : ($conflict ? TaskConflict::class : 'committed'),
                $b['status']
            );
            $intent = $fixture->intent()->get('cancellation');
            self::assertSame($priorIntent || !$terminalFirst && !$denyWaitingCancellation, $intent !== null);
            self::assertSame($intent?->toString(), $fixture->answers()->get('cancellation')?->toString());
            $observed = $fixture->answers()->get('groups')->get($group);
            $acquired = $first === 'acquire' || $second === 'acquire' && !$terminalFirst;
            self::assertSame($acquired, $observed->get('consumed'));
            $answered = in_array('acquire', [$first, $second], true)
                || $first === 'supply' || $second === 'supply' && !$terminalFirst;
            self::assertSame($answered ? 1 : 0, count($observed->get('answers')->properties()));
            if ($answered) {
                self::assertTrue($observed->get('answers')->get($group)->get('content')->get('ok'));
            }

            if ($terminalFirst) {
                self::assertSame($first, $fixture->task()->state()->status());
            } elseif ($terminalSecond) {
                $fixture->record($second);
                self::assertSame($second, $fixture->task()->state()->status());
                self::assertSame($intent?->toString(), $fixture->intent()->get('cancellation')?->toString());
            }

            if ($acquired) {
                $this->conflicts(fn() => $fixture->acquire($group));
            }
        } finally {
            file_put_contents($path.'.first.release', 'release');
            $one->stop();
            $two->stop();
            $connection->close();
            foreach (glob($path.'*') as $file) {
                unlink($file);
            }
        }
    }

    /** @return iterable<string, array{0: string, 1: string, 2: bool, 3?: bool}> */
    public static function races(): iterable
    {
        foreach (['supply', 'acquire', 'issue', 'completed', 'failed', 'cancelled', 'cancel'] as $operation) {
            yield 'cancel-'.$operation => ['cancel', $operation, false];
            if ($operation !== 'cancel') {
                yield $operation.'-cancel' => [$operation, 'cancel', false];
            }
        }

        foreach (['completed', 'failed', 'cancelled'] as $terminal) {
            foreach (['supply', 'acquire'] as $input) {
                yield 'prior-intent-'.$terminal.'-'.$input => [$terminal, $input, true];
                yield 'prior-intent-'.$input.'-'.$terminal => [$input, $terminal, true];
            }
        }

        yield 'waiting-cancel-current-scope' => ['supply', 'cancel', false, true];
    }

    private function waitFor(string $path, Process $process): void
    {
        $deadline = microtime(true) + 5;
        while (!is_file($path) && $process->isRunning() && microtime(true) < $deadline) {
            usleep(1000);
        }

        self::assertFileExists($path, $process->getErrorOutput());
    }

    private function database(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        new DbalTaskSchema()->install($connection);

        return $connection;
    }

    private function rejects(Closure $action): void
    {
        try {
            $action();
            self::fail('Expected safe current admission rejection.');
        } catch (TaskRejected $taskRejected) {
            self::assertSame('Task operation rejected.', $taskRejected->getMessage());
            self::assertNull($taskRejected->getPrevious());
        }
    }

    private function conflicts(Closure $action): void
    {
        try {
            $action();
            self::fail('Expected retained admission conflict.');
        } catch (TaskConflict $taskConflict) {
            self::assertSame('Task state conflicts.', $taskConflict->getMessage());
        }
    }
}
