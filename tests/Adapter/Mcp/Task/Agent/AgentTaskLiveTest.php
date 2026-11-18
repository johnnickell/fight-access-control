<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Mcp\Task\Agent;

use Doctrine\DBAL\DriverManager;
use Fight\AccessControl\Adapter\Mcp\Subscription\Agent\AgentTaskSubscriptionAdmission;
use Fight\AccessControl\Adapter\Mcp\Task\Agent\AgentTaskRequestAdmission;
use Fight\AccessControl\Adapter\Mcp\Task\Agent\AgentTaskServing;
use Fight\AccessControl\Application\AccessControl\Agent\Exception\AgentContinuationRejectedException;
use Fight\AccessControl\Application\AccessControl\Agent\Task\AgentTaskCurrentReadAdmission;
use Fight\AccessControl\Application\AccessControl\Agent\Task\AgentTaskDisclosureGuard;
use Fight\AccessControl\Application\AccessControl\Agent\Task\AgentTaskDisclosureScope;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\Common\Adapter\Mcp\Persistence\Subscription\Dbal\DbalSubscriptionSchema;
use Fight\Common\Adapter\Mcp\Persistence\Task\Dbal\DbalTaskSchema;
use Fight\Common\Application\Mcp\Subscription\McpSubscriptionAdmission;
use Fight\Common\Application\Mcp\Subscription\McpSubscriptionDelivery;
use Fight\Common\Application\Mcp\Task\Exception\TaskRejected;
use Fight\Common\Application\Mcp\Task\McpTasks;
use Fight\Common\Domain\Mcp\Notice\Notice;
use Fight\Common\Domain\Mcp\Task\Command\RecordTaskState;
use Fight\Common\Domain\Mcp\Task\TaskState;
use Fight\Common\Domain\Value\Basic\StrictJson;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Task\TaskAdmissionFixture;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Task\TaskCancellationFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(AgentTaskServing::class)]
#[CoversClass(AgentTaskRequestAdmission::class)]
#[CoversClass(AgentTaskSubscriptionAdmission::class)]
#[CoversClass(AgentTaskCurrentReadAdmission::class)]
#[CoversClass(AgentTaskDisclosureGuard::class)]
#[CoversClass(AgentTaskDisclosureScope::class)]
final class AgentTaskLiveTest extends TestCase
{
    public function test_acknowledgment_precedes_complete_terminal_baseline_and_exact_typed_correlation(): void
    {
        $fixture = $this->fixture();
        $fixture->ordinary->lifecycle->create();
        $fixture->ordinary->lifecycle->record('completed');

        $expected = $fixture->ordinary->call('tasks/get', ['taskId' => TaskAdmissionFixture::ID])['result'];
        $before = $fixture->ordinary->lifecycle->snapshot();
        $execution = $fixture->execution(7);
        $frames = [];
        $execution->run(function (string $frame) use ($fixture, $execution, &$frames): void {
            self::assertTrue($fixture->held, 'Actual byte handoff runs inside the required guard.');
            $frames[] = $this->decode($frame);
            if (count($frames) === 2) {
                $execution->cancel();
            }
        }, $fixture);
        self::assertSame('notifications/subscriptions/acknowledged', $frames[0]['method']);
        self::assertSame(['taskIds' => [TaskAdmissionFixture::ID]], $frames[0]['params']['notifications']);
        self::assertSame('notifications/tasks', $frames[1]['method']);
        self::assertSame(7, $frames[1]['params']['_meta']['io.modelcontextprotocol/subscriptionId']);
        $actual = $frames[1]['params'];
        unset($actual['_meta']['io.modelcontextprotocol/subscriptionId']);
        // Ordinary get additionally owns central server metadata; state/revision remain identical.
        unset($expected['resultType']);
        $expected['_meta'] = [McpTasks::REVISION => $expected['_meta'][McpTasks::REVISION]];
        self::assertSame($expected, $actual);
        self::assertSame($before, $fixture->ordinary->lifecycle->snapshot());
        self::assertSame([], $fixture->failures);
        self::assertSame($fixture->guards, $fixture->releases);
        self::assertFalse($fixture->held);
        self::assertSame(1, $fixture->auth->nonce->consumptionCalls());
        self::assertSame($fixture->ordinary->serving->tasks(), $fixture->subscriptions->tasks());
    }

    #[DataProvider('denials')]
    public function test_queued_baseline_rechecks_current_authority(string $change): void
    {
        $fixture = $this->fixture();
        $fixture->ordinary->lifecycle->create();

        $execution = $fixture->execution();
        $wire = '';
        $ack = false;
        $execution->run(static function (string $frame) use (&$wire): void {
            $wire .= $frame;
        }, $fixture, function (McpSubscriptionDelivery $delivery) use ($fixture, $change, &$ack, &$wire): void {
            if ($ack) {
                $this->change($fixture, $change);
            }

            $delivery->send(static function (string $frame) use (&$wire): void {
                $wire .= $frame;
            });
            $ack = true;
        });
        self::assertStringContainsString('acknowledged', $wire);
        self::assertStringNotContainsString('notifications\\/tasks', $wire);
        self::assertStringNotContainsString('resultType', $wire);
        self::assertNotEmpty($fixture->failures);
        self::assertSame('working', $fixture->ordinary->lifecycle->task()->state()->status());
        self::assertSame(1, $fixture->auth->nonce->consumptionCalls());
        self::assertSame($fixture->guards, $fixture->releases);
    }

    /** @return iterable<string, array{string}> */
    public static function denials(): iterable
    {
        foreach (
            ['signal', 'read', 'state', 'permission', 'listen', 'revoke', 'rotate', 'fault', 'epoch', 'fence'] as $case
        ) {
            yield $case => [$case];
        }
    }

    public function test_shared_get_revisions_order_delayed_reads_equal_timestamps_coalescing_and_reconnect(): void
    {
        $fixture = $this->fixture();
        $fixture->ordinary->lifecycle->create();

        $old = $fixture->ordinary->serving->tasks()->current(TaskAdmissionFixture::ID);
        $execution = $fixture->execution();
        $frames = [];
        $execution->run(function (string $frame) use ($fixture, $execution, &$frames): void {
            $frames[] = $this->decode($frame);
            if (count($frames) === 1) {
                // Equal clock: baseline is freshly sourced after acknowledgment, not its opening snapshot.
                $fixture->ordinary->lifecycle->issue();
                $fixture->ordinary->lifecycle->cancel();
            }

            if (count($frames) === 2) {
                $execution->cancel();
            }
        }, $fixture);
        $new = $frames[1]['params'];
        self::assertSame('input_required', $new['status']);
        self::assertNotEmpty($new['inputRequests']);
        self::assertArrayNotHasKey('cancellation', $new);
        $prior = $this->array($old['_meta'][McpTasks::REVISION]);
        $revision = $new['_meta'][McpTasks::REVISION];
        self::assertSame($prior['scope'], $revision['scope']);
        self::assertGreaterThan(0, strcmp($revision['value'], $prior['value']));
        self::assertSame($old['lastUpdatedAt'], $new['lastUpdatedAt']);
        $again = $fixture->ordinary->serving->tasks()->current(TaskAdmissionFixture::ID);
        self::assertSame($revision, $this->array($again['_meta'][McpTasks::REVISION]));
        self::assertSame(1, $fixture->auth->nonce->consumptionCalls());

        $fixture->auth->comparison = 'reader-visibility-v2';
        $incomparable = $fixture->ordinary->serving->tasks()->current(TaskAdmissionFixture::ID);
        self::assertNotSame($revision['scope'], $this->array($incomparable['_meta'][McpTasks::REVISION])['scope']);
        self::assertNotSame($revision['value'], $this->array($incomparable['_meta'][McpTasks::REVISION])['value']);
    }

    public function test_changed_visibility_between_query_and_guard_rejects_old_projection(): void
    {
        $fixture = $this->fixture();
        $fixture->ordinary->lifecycle->create();
        $fixture->beforeAcquire = static function () use ($fixture): void {
            $fixture->auth->comparison = 'rotated-'.($fixture->guards + 1);
        };
        $wire = '';
        $fixture->execution()->run(static function (string $frame) use (&$wire): void {
            $wire .= $frame;
        }, $fixture);
        self::assertSame('', $wire);
        self::assertNotEmpty($fixture->failures);
    }

    public function test_denied_or_missing_filters_acknowledge_identical_empty_subset_without_notice_source(): void
    {
        $fixture = $this->fixture();
        foreach ([false, true] as $create) {
            if ($create) {
                $fixture->ordinary->lifecycle->create();
                $fixture->signal = false;
            }

            $execution = $fixture->execution();
            $frames = [];
            $execution->run(function (string $frame) use ($execution, &$frames): void {
                $frames[] = $this->decode($frame);
                $execution->cancel();
            }, $fixture);
            self::assertSame([], $frames[0]['params']['notifications']);
        }

        self::assertSame([], $fixture->failures);
    }

    public function test_adapter_denies_foreign_malformed_notice_and_uncomposed_generic_paths(): void
    {
        $fixture = $this->fixture();
        self::assertFalse($fixture->admission->allows('foreign', 'taskIds', TaskAdmissionFixture::ID, null));
        self::assertFalse($fixture->admission->allows('reader', 'toolsListChanged', null, null));
        foreach ([null, 'bad'] as $id) {
            self::assertFalse($fixture->admission->allows('reader', 'taskIds', $id, null));
        }

        $generic = new class implements McpSubscriptionAdmission {
            public string $reference = 'reader';

            public function subscriber(): string
            {
                return $this->reference;
            }

            public function allows(string $subscriber, string $filter, ?string $uri, ?Notice $notice): bool
            {
                return $filter === 'toolsListChanged';
            }
        };
        $admission = new AgentTaskSubscriptionAdmission(
            'reader',
            $fixture->authority(['TASK_SIGNAL']),
            $fixture,
            $generic
        );
        self::assertTrue($admission->allows('reader', 'toolsListChanged', null, null));
        $generic->reference = 'other';
        self::assertFalse($admission->allows('reader', 'toolsListChanged', null, null));
        $fixture->signal = false;
        self::assertFalse($admission->allows('reader', 'taskIds', TaskAdmissionFixture::ID, null));
        $this->expectException(AgentContinuationRejectedException::class);
        $fixture->guard->run('foreign', [], static fn(): never => self::fail('Foreign subscriber disclosed.'));
    }

    public function test_current_read_comparison_denies_missing_authority_and_scope_instead_of_empty_success(): void
    {
        $fixture = $this->fixture();
        $fixture->auth->read = false;
        self::assertFalse($fixture->read->allowsRead('scope', TaskCancellationFixture::id()));
        try {
            $fixture->read->comparisonScope('scope', TaskCancellationFixture::id());
            self::fail('Denied comparison succeeded.');
        } catch (TaskRejected $taskRejected) {
            self::assertSame(TaskRejected::class, $taskRejected::class);
        }

        $fixture->auth->read = true;
        $this->change($fixture, 'revoke');
        self::assertFalse($fixture->read->allowsRead('scope', TaskCancellationFixture::id()));
        $this->expectException(TaskRejected::class);
        $fixture->read->comparisonScope('scope', TaskCancellationFixture::id());
    }

    #[DataProvider('faults')]
    public function test_post_ack_faults_close_without_completion_and_preserve_durable_state(string $fault): void
    {
        $fixture = $this->fixture();
        $fixture->ordinary->lifecycle->create();

        $before = $fixture->ordinary->lifecycle->snapshot();
        $wire = '';
        $fixture->execution()->run(static function (string $frame) use ($fixture, $fault, &$wire): void {
            $wire .= $frame;
            if (str_contains($frame, 'acknowledged')) {
                if ($fault === 'source') {
                    $fixture->ordinary->lifecycle->connection->executeStatement('DROP TABLE fight_mcp_tasks');
                } elseif ($fault === 'tracking') {
                    $fixture->tracking->beforeHeartbeat = static function (): never {
                        throw new RuntimeException('Private tracking');
                    };
                } elseif ($fault === 'transport') {
                    throw new RuntimeException('Private transport');
                } else {
                    $fixture->fault = true;
                }
            }
        }, $fixture);
        self::assertStringContainsString('acknowledged', $wire);
        self::assertStringNotContainsString('resultType', $wire);
        self::assertStringNotContainsString('Private', $wire);
        self::assertNotEmpty($fixture->failures);
        if ($fault !== 'source') {
            self::assertSame($before, $fixture->ordinary->lifecycle->snapshot());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function faults(): iterable
    {
        foreach (['source', 'tracking', 'transport', 'policy'] as $case) {
            yield $case => [$case];
        }
    }

    public function test_stored_valid_result_obeys_final_encoded_frame_budget(): void
    {
        $fixture = $this->fixture();
        $fixture->ordinary->lifecycle->create();

        $result = StrictJson::fromObject([
            'content'           => [(object) ['type' => 'text', 'text' => str_repeat("é\"\\\\", 1800)]],
            'structuredContent' => (object) ['ok' => true],
            'isError'           => false
        ]);
        $fixture->ordinary->lifecycle->commandBus->execute(new RecordTaskState(
            'scope',
            TaskCancellationFixture::id(),
            'producer',
            $fixture->ordinary->lifecycle->task()->version(),
            TaskState::create('completed', result: $result)
        ));
        self::assertSame('completed', $fixture->ordinary->lifecycle->task()->state()->status());
        $wire = '';
        $fixture->execution()->run(static function (string $frame) use (&$wire): void {
            self::assertLessThanOrEqual(8192, strlen($frame));
            $wire .= $frame;
        }, $fixture);
        self::assertStringContainsString('acknowledged', $wire);
        self::assertStringNotContainsString('notifications\\/tasks', $wire);
        self::assertStringNotContainsString('resultType', $wire);
        self::assertNotEmpty($fixture->failures);
        self::assertSame('completed', $fixture->ordinary->lifecycle->task()->state()->status());
    }

    public function test_duplicates_remain_guarded_and_idle_completion_closes_tracking(): void
    {
        $fixture = $this->fixture();
        $fixture->ordinary->lifecycle->create();

        $wire = '';
        $fixture->execution()->run(static function (string $frame) use (&$wire): void {
            $wire .= $frame;
        }, $fixture);
        self::assertSame(1, substr_count($wire, 'notifications\\/tasks'));
        self::assertStringContainsString('"resultType":"complete"', $wire);
        self::assertGreaterThan(2, $fixture->states);
        self::assertSame($fixture->guards, $fixture->releases);
        self::assertSame([], $fixture->failures);
    }

    public function test_visibility_change_after_baseline_stops_instead_of_claiming_healthy_coverage(): void
    {
        $fixture = $this->fixture();
        $fixture->ordinary->lifecycle->create();

        $wire = '';
        $fixture->execution()->run(static function (string $frame) use ($fixture, &$wire): void {
            $wire .= $frame;
            if (str_contains($frame, 'notifications\\/tasks')) {
                $fixture->auth->comparison = 'discontinuous-visibility';
            }
        }, $fixture);
        self::assertSame(1, substr_count($wire, 'notifications\\/tasks'));
        self::assertStringNotContainsString('resultType', $wire);
        self::assertNotEmpty($fixture->failures);
    }

    public function test_request_snapshot_admission_cannot_enable_live_reading(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        new DbalTaskSchema()->install($connection);
        $ordinary = new TaskServingFixture($connection);
        self::assertTrue($ordinary->admission->allows('subscriptions/listen', null));
        self::assertFalse($ordinary->admission->allowsRead('scope', TaskCancellationFixture::id()));
        $this->expectException(TaskRejected::class);
        $ordinary->admission->comparisonScope('scope', TaskCancellationFixture::id());
    }

    private function fixture(): TaskLiveFixture
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        new DbalTaskSchema()->install($connection);
        $tracking = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        new DbalSubscriptionSchema()->install($tracking);

        return new TaskLiveFixture($connection, $tracking);
    }

    private function change(TaskLiveFixture $fixture, string $change): void
    {
        $agent = $fixture->auth->agentRepository->getById($fixture->auth->agent->getId());
        match ($change) {
            'signal' => $fixture->signal = false,
            'read' => $fixture->auth->read = false,
            'state' => $fixture->state = false,
            'fault' => $fixture->fault = true,
            'fence' => $fixture->supported = false,
            'epoch' => $fixture->beforeAcquire = static function () use ($fixture): void {
                $fixture->auth->comparison = 'new-visibility';
            },
            'permission', 'listen' => $fixture->auth->agentRepository->restoreSnapshot([$agent->revokePermission(
                $fixture->auth->permissionRepository->getByName(PermissionName::fromString(
                    $change === 'listen' ? 'TASK_LISTEN' : 'TASK_READ'
                ))->getId(),
                TaskAdmissionFixture::now()
            )]),
            'revoke' => $fixture->auth->agentRepository->restoreSnapshot([$agent->revoke(TaskAdmissionFixture::now())]),
            'rotate' => $fixture->auth->agentRepository->restoreSnapshot([$agent->rotateRecoverableCredential(
                $agent->getCredentialId(),
                $agent->getCredentialRevision(),
                AgentCredentialId::fromString('018f0000-0000-7000-8000-000000009999'),
                'encrypted:successor',
                TaskAdmissionFixture::now()
            )]),
            default => throw new RuntimeException('Unknown test change.')
        };
    }

    /** @return array<string, mixed> */
    private function decode(string $frame): array
    {
        return json_decode(substr($frame, 6), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function array(mixed $value): array
    {
        return json_decode(json_encode($value, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    }
}
