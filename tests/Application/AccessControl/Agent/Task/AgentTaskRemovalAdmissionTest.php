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
use Fight\AccessControl\Application\AccessControl\Agent\Task\AgentTaskContinuationScope;
use Fight\AccessControl\Application\AccessControl\Agent\Task\AgentTaskRemovalAdmission;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\Common\Adapter\Mcp\Persistence\Task\Dbal\DbalTaskSchema;
use Fight\Common\Application\Mcp\Task\Exception\CommittedTaskDispatchFailed;
use Fight\Common\Application\Mcp\Task\Exception\TaskConflict;
use Fight\Common\Application\Mcp\Task\Exception\TaskRejected;
use Fight\Common\Application\Mcp\Task\Exception\TaskStorageFailed;
use Fight\Common\Application\Mcp\Task\Service\TaskInputForms;
use Fight\Common\Application\Mcp\Tool\Interaction\McpInputRequest;
use Fight\Common\Application\Mcp\Tool\Interaction\McpInputRequired;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Mcp\Task\Command\RemoveTasks;
use Fight\Common\Domain\Mcp\Task\Command\RequestTaskInput;
use Fight\Common\Domain\Mcp\Task\TaskId;
use Fight\Common\Domain\Mcp\Task\TaskSelection;
use Fight\Common\Domain\Mcp\Task\TaskState;
use Fight\Common\Domain\Value\Basic\StrictJson;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Continuation\ControlledContinuationAuthority;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;

#[CoversClass(AgentTaskRemovalAdmission::class)]
final class AgentTaskRemovalAdmissionTest extends TestCase
{
    public function test_that_other_task_grants_and_handle_possession_never_authorize_removal(): void
    {
        $connection = $this->database();
        $owner = new TaskRemovalJourney($connection);
        $owner->create();
        foreach ([['TASK_CREATE'], ['TASK_UPDATE'], ['TASK_READ'], ['TASK_CANCEL'], ['TASK_CONTINUE'], []] as $grants) {
            $journey = new TaskRemovalJourney($connection, new TaskAdmissionFixture($grants));
            $before = $owner->rows();
            $this->rejects(fn() => $journey->remove());
            $this->rejects(fn() => $journey->remove([TaskRemovalJourney::SECOND]));
            self::assertSame([], $journey->checked, 'Permission denial precedes consumer scope and existence.');
            self::assertSame($before, $owner->rows());
            self::assertSame([], $journey->removed);
        }

        $remover = new TaskRemovalJourney($connection, new TaskAdmissionFixture(['TASK_REMOVE'], identity: 701));
        self::assertNull($remover->read(), 'A removal grant confers no read grant.');
        $remover->remove();
        self::assertSame([], $owner->rows());
        self::assertSame(1, $remover->fixture->nonce->consumptionCalls());
    }

    public function test_that_scope_and_authority_faults_remain_fixed_failure_not_absence_or_private_details(): void
    {
        $connection = $this->database();
        $owner = new TaskRemovalJourney($connection);
        $owner->create();
        foreach (
            [
            new TaskAdmissionFixture(['TASK_REMOVE'], validSignature: false),
            new TaskAdmissionFixture(['TASK_REMOVE'], complete: false)
            ] as $fixture
        ) {
            $journey = new TaskRemovalJourney($connection, $fixture);
            $this->rejects(fn() => $journey->remove());
            self::assertSame([], $journey->checked);
            self::assertCount(1, $owner->rows());
        }

        $owner->fault = true;
        // The admission itself propagates faults; actual Common removal deliberately sanitizes every admission fault.
        $admission = new AgentTaskRemovalAdmission($owner->fixture->authority('TASK_REMOVE'), $owner);
        try {
            $admission->allowsRemoval('scope', TaskId::fromString(TaskAdmissionFixture::ID));
            self::fail('Consumer policy faults must not become Boolean allow.');
        } catch (RuntimeException $runtimeException) {
            self::assertSame('Private retention policy details.', $runtimeException->getMessage());
        }

        $this->rejects(fn() => $owner->remove());
        self::assertCount(1, $owner->rows());
        self::assertSame([], $owner->removed);
        $owner->fault = false;
        $this->rejects(fn() => $owner->remove(scope: 'foreign'));
        self::assertCount(1, $owner->rows());
    }

    public function test_that_every_target_is_admitted_before_any_deletion_including_missing_and_repeat(): void
    {
        $journey = new TaskRemovalJourney($this->database());
        $journey->create();
        $journey->create(TaskRemovalJourney::SECOND);

        $before = $journey->rows();
        $journey->denied = [TaskRemovalJourney::SECOND];
        $this->rejects(fn() => $journey->remove([TaskAdmissionFixture::ID, TaskRemovalJourney::SECOND]));
        self::assertSame([TaskAdmissionFixture::ID, TaskRemovalJourney::SECOND], $journey->checked);
        self::assertSame($before, $journey->rows());
        self::assertSame([], $journey->removed);
        $journey->denied = [];
        $missing = str_repeat('a', 32);
        $selection = [TaskAdmissionFixture::ID, $missing, TaskRemovalJourney::SECOND];
        $journey->remove($selection);
        self::assertSame([], $journey->rows());
        self::assertSame([
            ['scope' => 'scope', 'ids' => [TaskAdmissionFixture::ID, TaskRemovalJourney::SECOND]]
        ], $journey->removed);
        $journey->checked = [];
        $journey->remove($selection);
        self::assertSame($selection, $journey->checked);
        self::assertCount(1, $journey->removed, 'All missing is a newly admitted no-op, not a second removal fact.');
        $journey->denied = [$missing];
        $this->rejects(fn() => $journey->remove($selection));
        self::assertSame([], $journey->rows());
        self::assertCount(1, $journey->removed);
    }

    #[DataProvider('retainedStates')]
    public function test_that_all_retained_payload_and_comparison_material_disappears_coherently(string $status): void
    {
        $journey = new TaskRemovalJourney($this->database());
        $journey->create(ttl: 1000);
        if ($status === 'input_required') {
            $input = TaskInputForms::batch(McpInputRequired::fromRequests([
                'request' => McpInputRequest::form('Private outstanding input', [
                    'type' => 'object', 'properties' => (object) []
                ])
            ]), 'caller', $journey->now + 500);
            $journey->commands->execute(new RequestTaskInput(
                'scope',
                TaskId::fromString(TaskAdmissionFixture::ID),
                'producer',
                1,
                $input
            ));
        } elseif ($status !== 'working' && $status !== 'expired') {
            $state = match ($status) {
                'completed' => TaskState::create('completed', result: StrictJson::fromObject([
                    'content'           => [(object) ['type' => 'text', 'text' => 'Private result']],
                    'structuredContent' => (object) ['ok' => true],
                    'isError'           => false
                ])),
                'failed' => TaskState::create('failed', error: StrictJson::fromObject([
                    'code' => -32603, 'message' => 'Internal error'
                ])),
                default => TaskState::create('cancelled')
            };
            $journey->record($state);
        }

        $beforeView = $journey->read();
        self::assertInstanceOf(StrictJson::class, $beforeView);
        self::assertStringContainsString('original', $journey->rows()[0]['payload']);
        if ($status === 'expired') {
            $journey->now += 1000;
            $rows = $journey->rows();
            self::assertNull($journey->read());
            self::assertSame($rows, $journey->rows(), 'No read-side cleanup.');
        }

        $journey->denied = [TaskAdmissionFixture::ID];
        $before = $journey->rows();
        $this->rejects(fn() => $journey->remove());
        self::assertSame($before, $journey->rows());
        $journey->denied = [];
        $journey->remove();
        self::assertSame([], $journey->rows());
        self::assertNull($journey->read());
        self::assertNull($journey->repository->read('scope', TaskId::fromString(TaskAdmissionFixture::ID)));
        self::assertSame(['scope' => 'scope', 'ids' => [TaskAdmissionFixture::ID]], $journey->removed[0]);
        $journey->remove();
        self::assertCount(1, $journey->removed);
        // Common retains no tombstone or permanent identity memory. A create with this identity is new work.
        ++$journey->now;
        $journey->create();
        self::assertSame('working', $journey->read()?->get('state')->get('status'));
        self::assertNotSame($beforeView->toString(), $journey->read()->toString());
        self::assertStringNotContainsString('Private result', $journey->rows()[0]['payload']);
        self::assertStringNotContainsString('Private outstanding input', $journey->rows()[0]['payload']);
        $recreated = $journey->rows();
        $changed = $journey->changed;
        $journey->create();
        self::assertSame($recreated, $journey->rows(), 'Retry compares only the newly retained creation.');
        self::assertSame($changed, $journey->changed);
    }

    /** @return iterable<string, array{string}> */
    public static function retainedStates(): iterable
    {
        foreach (['working', 'input_required', 'completed', 'failed', 'cancelled', 'expired'] as $status) {
            yield $status => [$status];
        }
    }

    public function test_that_a_late_producer_cannot_upsert_removed_data_or_replace_a_terminal_winner(): void
    {
        $journey = new TaskRemovalJourney($this->database());
        $journey->create();
        $journey->record(TaskState::create('cancelled'));

        $terminal = $journey->rows();
        try {
            $journey->record(TaskState::create('working', message: 'stale private body'));
            self::fail('The terminal winner must survive.');
        } catch (TaskConflict) {
            self::assertSame($terminal, $journey->rows());
        }

        $journey->remove();
        $this->rejects(fn() => $journey->record(TaskState::create('working', message: 'late private body')));
        self::assertSame([], $journey->rows());
        self::assertNull($journey->read());
        self::assertSame(2, $journey->changed);
    }

    public function test_that_partial_storage_failure_rolls_back_the_complete_selection_without_dispatch(): void
    {
        $journey = new TaskRemovalJourney($this->database());
        $journey->create();
        $journey->create(TaskRemovalJourney::SECOND);

        $before = $journey->rows();
        $trigger = "CREATE TRIGGER removal_fail BEFORE DELETE ON fight_tasks WHEN OLD.task_id = '";
        $trigger .= TaskRemovalJourney::SECOND."' BEGIN SELECT RAISE(ABORT, 'private'); END";
        $journey->connection->executeStatement($trigger);
        try {
            $journey->remove([TaskAdmissionFixture::ID, TaskRemovalJourney::SECOND]);
            self::fail('The first deletion must roll back when the second fails.');
        } catch (TaskStorageFailed $taskStorageFailed) {
            self::assertFalse($taskStorageFailed->outcomeUncertain());
            self::assertSame('Task storage failed.', $taskStorageFailed->getMessage());
        }

        self::assertSame($before, $journey->rows());
        self::assertSame([], $journey->removed);
        self::assertFalse($journey->connection->isTransactionActive());
    }

    public function test_that_caller_transactions_are_never_committed_or_rolled_back(): void
    {
        $journey = new TaskRemovalJourney($this->database());
        $journey->create();
        $journey->connection->beginTransaction();
        try {
            $journey->connection->executeStatement('CREATE TABLE caller_work (value TEXT)');
            try {
                $journey->remove();
                self::fail('Common must reject nested ownership before admission.');
            } catch (LogicException) {
                self::assertTrue($journey->connection->isTransactionActive());
                self::assertSame([], $journey->checked);
                self::assertCount(1, $journey->rows());
                self::assertNotFalse($journey->connection->fetchOne(
                    "SELECT name FROM sqlite_master WHERE name = 'caller_work'"
                ));
            }
        } finally {
            $journey->connection->rollBack();
        }
    }

    #[DataProvider('commitOutcomes')]
    public function test_that_lost_commit_acknowledgment_requires_fresh_reconciliation(bool $committed): void
    {
        $path = tempnam(sys_get_temp_dir(), 'task-remove-');
        self::assertIsString($path);
        $params = ['driver' => 'pdo_sqlite', 'path' => $path];
        $connection = DriverManager::getConnection($params);
        new DbalTaskSchema()->install($connection);
        $owner = new TaskRemovalJourney($connection);
        $owner->create();

        $uncertain = new class ($params, $connection->getDriver()) extends Connection {
            public bool $committed = false;

            public function commit(): void
            {
                if ($this->committed) {
                    parent::commit();
                }

                throw new RuntimeException('Private lost commit acknowledgment.');
            }
        };
        $uncertain->committed = $committed;
        try {
            $journey = new TaskRemovalJourney($uncertain);
            try {
                $journey->remove();
                self::fail('No success or guessed rollback after lost acknowledgment.');
            } catch (TaskStorageFailed $failure) {
                self::assertTrue($failure->outcomeUncertain());
                self::assertSame([], $journey->removed);
            }

            $uncertain->close();
            $connection->close();
            $fresh = DriverManager::getConnection($params);
            try {
                $retry = new TaskRemovalJourney($fresh);
                self::assertCount($committed ? 0 : 1, $retry->rows());
                $retry->remove();
                self::assertSame([], $retry->rows());
                self::assertCount($committed ? 0 : 1, $retry->removed);
            } finally {
                $fresh->close();
            }
        } finally {
            $uncertain->close();
            $connection->close();
            unlink($path);
        }
    }

    /** @return iterable<string, array{bool}> */
    public static function commitOutcomes(): iterable
    {
        yield 'committed' => [true];
        yield 'not committed' => [false];
    }

    public function test_that_postcommit_dispatch_failure_never_restores_removed_data(): void
    {
        $journey = new TaskRemovalJourney($this->database());
        $journey->create();
        $journey->publicationFails = true;
        try {
            $journey->remove();
            self::fail('Publication failure must remain distinct from persistence failure.');
        } catch (CommittedTaskDispatchFailed) {
            self::assertSame([], $journey->rows());
            self::assertNull($journey->read());
        }

        $journey->remove();
        self::assertCount(1, $journey->removed, 'A no-op retry does not reconstruct a lost dispatch.');
    }

    public function test_that_selection_and_output_are_bounded_before_any_authorized_mutation(): void
    {
        $journey = new TaskRemovalJourney($this->database());
        $journey->create();

        $ids = [];
        for ($index = 0; $index < TaskSelection::MAX_COUNT; ++$index) {
            $ids[] = TaskId::fromString(sprintf('%032x', $index));
        }

        $selection = TaskSelection::fromIds(str_repeat('s', 96), $ids);
        self::assertSame($selection->toArray(), RemoveTasks::fromArray($selection->toArray())->toArray());
        $invalid = [
            [], [$ids[0], $ids[0]], ['key' => $ids[0]], ['not typed'],
            [...$ids, TaskId::fromString(str_repeat('f', 32))]
        ];
        foreach ($invalid as $values) {
            try {
                TaskSelection::fromIds('scope', $values);
                self::fail('Invalid selections must fail before dispatch.');
            } catch (DomainException) {
                self::assertSame([], $journey->checked);
                self::assertCount(1, $journey->rows());
            }
        }

        foreach (['', str_repeat('s', 97)] as $scope) {
            try {
                TaskSelection::fromIds($scope, [$ids[0]]);
                self::fail('Invalid scopes must fail before dispatch.');
            } catch (DomainException) {
                self::assertSame([], $journey->checked);
            }
        }

        try {
            TaskId::fromString('private invalid identity');
            self::fail('Malformed identity must reject.');
        } catch (DomainException) {
            self::assertCount(1, $journey->rows());
        }

        $journey->remove(array_map(static fn(TaskId $id): string => $id->toString(), $ids));
        self::assertCount(100, $journey->checked);
        self::assertSame([], $journey->removed, 'Missing identities do not fabricate removal facts.');
        self::assertCount(1, $journey->rows());
    }

    public function test_that_background_cleanup_reuses_fresh_guard_and_ephemeral_authority_without_nonce_replay(): void
    {
        $fixture = new TaskAdmissionFixture(['TASK_CREATE', 'TASK_REMOVE']);
        $binding = AgentContinuationBinding::fromRequest($fixture->provider, $fixture->request, 'cleanup');
        $state = new ControlledContinuationAuthority(
            $fixture->agent,
            $fixture->permissionRepository->getByIds($fixture->agent->getPermissionIds())
        );
        $fence = $this->createStub(AgentContinuationFence::class);
        $fence->method('synchronized')->willReturnCallback(
            static function (AgentId $id, AgentContinuationScope $scope, Closure $admit) use ($state): void {
                self::assertInstanceOf(AgentTaskContinuationScope::class, $scope);
                self::assertSame($state, $scope->getScope());
                $state->synchronized($id, $state, $admit);
            }
        );
        $guard = new AgentContinuationGuard(
            $fixture->agentRepository,
            $fixture->permissionRepository,
            new FixedClock(TaskAdmissionFixture::now()),
            $fence
        );
        $connection = $this->database();
        $owner = new TaskRemovalJourney($connection, $fixture);
        $owner->create();

        $saved = null;
        $remove = function (AgentTaskAuthority $authority) use ($fixture, $connection, &$saved): void {
            $saved = $authority;
            new TaskRemovalJourney($connection, $fixture, $authority)->remove();
        };
        AgentTaskAuthority::duringContinuation(
            $guard,
            $binding,
            [PermissionName::fromString('TASK_REMOVE')],
            $state,
            $remove
        );
        self::assertSame([], $owner->rows());
        self::assertInstanceOf(AgentTaskAuthority::class, $saved);
        try {
            $saved->principal();
            self::fail('Cleanup authority cannot escape the synchronous guard.');
        } catch (AgentContinuationRejectedException) {
            self::assertFalse($state->held);
        }

        $fixture->agentRepository->restoreSnapshot([$fixture->agent->revoke(TaskAdmissionFixture::now())]);
        try {
            AgentTaskAuthority::duringContinuation(
                $guard,
                $binding,
                [PermissionName::fromString('TASK_REMOVE')],
                $state,
                $remove
            );
            self::fail('A repeat after revocation must not silently succeed.');
        } catch (AgentContinuationRejectedException $agentContinuationRejectedException) {
            self::assertNull($agentContinuationRejectedException->getPrevious());
            self::assertSame([], $owner->rows());
        }

        self::assertSame(1, $fixture->nonce->consumptionCalls());
    }

    #[DataProvider('raceSchedules')]
    public function test_that_real_concurrent_removal_and_read_preserve_coherent_outcomes(string $schedule): void
    {
        $path = tempnam(sys_get_temp_dir(), 'task-removal-race-');
        self::assertIsString($path);
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $path]);
        new DbalTaskSchema()->install($connection);
        $journey = new TaskRemovalJourney($connection);
        $journey->create();

        $process = null;
        $startWaiter = function () use ($path, $schedule, &$process): void {
            // The real owning handler already holds Common's namespace write lock here.
            $script = 'require "vendor/autoload.php"; ';
            $script .= '$c = '.DriverManager::class.'::getConnection(["driver"=>"pdo_sqlite","path"=>$argv[1]]); ';
            $script .= '$c->executeStatement("PRAGMA busy_timeout = 5000"); ';
            $script .= '$j = new Fight\\Test\\AccessControl\\Application\\AccessControl\\Agent\\Task\\';
            $script .= 'TaskRemovalJourney($c); ';
            $script .= 'echo "READY\\n"; flush(); ';
            $script .= 'try { if ($argv[2] === "producer then remove") { $j->remove(); } ';
            $script .= 'else { $j->record('.TaskState::class.'::create("working", ';
            $script .= 'message: "late")); } ';
            $script .= 'echo "success\\n"; } catch (Fight\\Common\\Application\\Mcp\\Task\\Exception\\TaskRejected) ';
            $script .= '{ echo "rejected\\n"; } catch ';
            $script .= '(Fight\\Common\\Application\\Mcp\\Task\\Exception\\TaskConflict) ';
            $script .= '{ echo "conflict\\n"; } echo json_encode($j->read());';
            $process = new Process([PHP_BINARY, '-r', $script, $path, $schedule], dirname(__DIR__, 5));
            $process->start();
            self::assertTrue($process->waitUntil(
                static fn(string $type, string $output): bool => str_contains($output, 'READY')
            ));
            usleep(100000);
            self::assertTrue($process->isRunning(), 'The second real mutation is blocked by the first storage lock.');
        };
        try {
            if ($schedule === 'remove then producer') {
                $journey->beforeAdmission = $startWaiter;
                $journey->remove();
            } else {
                $journey->fixture->beforeUpdate = $startWaiter;
                $journey->record(TaskState::create('cancelled'));
            }

            self::assertInstanceOf(Process::class, $process);
            $exit = $process->wait();
            self::assertSame(0, $exit, $process->getErrorOutput().$process->getOutput());
            $output = $process->getOutput();
            if ($schedule === 'terminal then stale producer') {
                self::assertStringContainsString('conflict', $output);
                self::assertStringContainsString('cancelled', $output);
                self::assertSame('cancelled', $journey->read()?->get('state')->get('status'));
            } else {
                $expected = $schedule === 'remove then producer' ? 'rejected' : 'success';
                self::assertStringContainsString($expected, $output);
                self::assertStringEndsWith('null', $output);
                self::assertSame([], $journey->rows());
                self::assertNull($journey->read());
            }
        } finally {
            if ($process instanceof Process && $process->isRunning()) {
                $process->stop();
            }

            $connection->close();
            unlink($path);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function raceSchedules(): iterable
    {
        foreach (['remove then producer', 'producer then remove', 'terminal then stale producer'] as $schedule) {
            yield $schedule => [$schedule];
        }
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
            self::fail('Expected Common fixed admission rejection.');
        } catch (TaskRejected $taskRejected) {
            self::assertSame('Task operation rejected.', $taskRejected->getMessage());
            self::assertNull($taskRejected->getPrevious());
        }
    }
}
