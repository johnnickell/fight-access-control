<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Mcp\Task\Agent;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Fight\AccessControl\Adapter\Mcp\Task\Agent\AgentTaskRequestAdmission;
use Fight\AccessControl\Adapter\Mcp\Task\Agent\AgentTaskServing;
use Fight\AccessControl\Application\AccessControl\Agent\Task\AgentTaskReadAdmission;
use Fight\Common\Adapter\Mcp\Persistence\Task\Dbal\DbalTaskSchema;
use Fight\Common\Application\Mcp\Http\McpInvocationGuard;
use Fight\Common\Application\Mcp\McpResponder;
use Fight\Common\Application\Mcp\Protocol\McpServerInfo;
use Fight\Common\Application\Mcp\Task\Exception\TaskRejected;
use Fight\Common\Application\Mcp\Task\McpTasks;
use Fight\Common\Application\Mcp\Tool\McpToolRegistry;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Mcp\Task\Command\RecordTaskState;
use Fight\Common\Domain\Mcp\Task\Query\GetTask;
use Fight\Common\Domain\Mcp\Task\TaskState;
use Fight\Common\Domain\Value\Basic\StrictJson;
use Fight\Test\AccessControl\Adapter\Mcp\Tool\Agent\Fixture\ProtectedTool;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Task\TaskAdmissionFixture;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Task\TaskCancellationFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(AgentTaskServing::class)]
#[CoversClass(AgentTaskRequestAdmission::class)]
final class AgentTaskServingTest extends TestCase
{
    public function test_complete_composition_advertises_only_real_common_tasks_and_keeps_grants_independent(): void
    {
        $connection = $this->database();
        $start = new TaskServingFixture($connection, ['TOOL_START', 'TASK_CREATE', 'TASK_ACK']);
        $capabilities = $start->call('server/discover')['result']['capabilities'];
        self::assertArrayHasKey(McpTasks::EXTENSION, $capabilities['extensions']);
        self::assertArrayNotHasKey('subscriptions', $capabilities);
        $ack = $start->call('tools/call', ['name' => 'example.operation']);
        self::assertSame('task', $ack['result']['resultType']);
        self::assertSame(TaskAdmissionFixture::ID, $ack['result']['taskId']);
        self::assertArrayNotHasKey('result', $ack['result']);
        self::assertArrayNotHasKey('inputRequests', $ack['result']);
        self::assertSame('working', $start->lifecycle->task()->state()->status());
        self::assertSame(1, $start->lifecycle->input->auth->nonce->consumptionCalls());

        $later = new TaskServingFixture($connection, ['TOOL_START', 'TASK_CREATE', 'TASK_ACK']);
        foreach (['tasks/get', 'tasks/cancel'] as $method) {
            self::assertArrayHasKey('error', $later->call($method, ['taskId' => TaskAdmissionFixture::ID]));
        }

        self::assertArrayHasKey('error', $later->call('tasks/update', [
            'taskId' => TaskAdmissionFixture::ID, 'inputResponses' => (object) []
        ]));
        $reader = new TaskServingFixture($connection, ['TASK_READ']);
        $state = $reader->call('tasks/get', ['taskId' => TaskAdmissionFixture::ID]);
        self::assertSame('working', $state['result']['status']);
        self::assertNotNull($reader->serving->queryBus()->fetch(new GetTask('scope', TaskCancellationFixture::id())));
        self::assertArrayHasKey('error', $reader->call('tools/call', ['name' => 'example.operation']));
    }

    public function test_disconnect_reconstruction_input_confirmation_and_cancel_preserve_actual_outcomes(): void
    {
        $connection = $this->database();
        $start = new TaskServingFixture($connection);
        self::assertSame('task', $start->call('tools/call', ['name' => 'example.operation'])['result']['resultType']);
        unset($start);
        $producer = new TaskCancellationFixture($connection);
        $keys = $producer->issue();
        $reconnect = new TaskServingFixture($connection);
        $state = $reconnect->call('tasks/get', ['taskId' => TaskAdmissionFixture::ID])['result'];
        self::assertSame('input_required', $state['status']);
        self::assertSame($keys, array_keys($state['inputRequests']));
        self::assertSame('complete', $reconnect->call('tasks/update', [
            'taskId'         => TaskAdmissionFixture::ID,
            'inputResponses' => [$keys[0] => ['action' => 'accept', 'content' => ['ok' => true]]]
        ])['result']['resultType']);
        $producer->acquire($keys[0]);
        self::assertSame('complete', $reconnect->call('tasks/cancel', [
            'taskId' => TaskAdmissionFixture::ID
        ])['result']['resultType']);
        self::assertNotNull($producer->intent()->get('cancellation'));
        $producer->record('completed');
        $terminal = new TaskServingFixture($connection, ['TASK_READ']);
        $state = $terminal->call('tasks/get', ['taskId' => TaskAdmissionFixture::ID])['result'];
        self::assertSame('completed', $state['status']);
        self::assertTrue($state['result']['structuredContent']['ok']);
        self::assertArrayNotHasKey('cancellation', $state);
        self::assertSame(1, $reconnect->lifecycle->input->auth->nonce->consumptionCalls());
    }

    /** @param list<string> $grants */
    #[DataProvider('missingGrants')]
    public function test_missing_grants_and_support_precede_creation_or_submission(array $grants, bool $support): void
    {
        $submissions = 0;
        $fixture = new TaskServingFixture($this->database(), $grants, submit: static function () use (
            &$submissions
        ): void {
            ++$submissions;
        });
        self::assertArrayHasKey('error', $fixture->call('tools/call', ['name' => 'example.operation'], $support));
        self::assertSame(0, $submissions);
        self::assertNull($fixture->lifecycle->repository->read('scope', TaskCancellationFixture::id()));
    }

    /** @return iterable<string, array{list<string>, bool}> */
    public static function missingGrants(): iterable
    {
        yield 'no declaration' => [[], true];
        yield 'no create' => [['TOOL_START', 'TASK_ACK'], true];
        yield 'no negotiation' => [TaskServingFixture::GRANTS, false];
    }

    #[DataProvider('postCommitFaults')]
    public function test_response_submission_and_publication_faults_do_not_disguise_durable_state(string $fault): void
    {
        $grants = TaskServingFixture::GRANTS;
        if ($fault === 'response-denied') {
            $grants = array_values(array_diff($grants, ['TASK_ACK']));
        }

        $fixture = new TaskServingFixture(
            $this->database(),
            $grants,
            $fault === 'response-budget' ? 1 : McpTasks::MAX_RESPONSE_BYTES,
            $fault === 'submission' ? static function (): void {
                throw new RuntimeException('Private consumer submission failure.');
            } : null,
            $fault === 'publication'
        );
        $response = $fixture->call('tools/call', ['name' => 'example.operation']);
        self::assertArrayHasKey('error', $response);
        self::assertStringNotContainsString('Private', json_encode($response, JSON_THROW_ON_ERROR));
        self::assertSame('working', $fixture->lifecycle->task()->state()->status());
        self::assertSame(1, $fixture->tool->starts);
    }

    /** @return iterable<string, array{string}> */
    public static function postCommitFaults(): iterable
    {
        foreach (['response-denied', 'response-budget', 'submission', 'publication'] as $fault) {
            yield $fault => [$fault];
        }
    }

    /** @param array<string, mixed> $params */
    #[DataProvider('invalidMethods')]
    public function test_current_methods_require_fresh_declaration_and_exact_parameters(
        string $method,
        array $params
    ): void {
        $fixture = new TaskServingFixture($this->database());
        self::assertArrayHasKey('error', $fixture->call($method, $params));
        self::assertSame(0, $fixture->tool->starts);
    }

    /** @return iterable<string, array{string, array<string, mixed>}> */
    public static function invalidMethods(): iterable
    {
        yield 'old alias' => ['tasks/result', ['taskId' => TaskAdmissionFixture::ID]];
        yield 'bad id' => ['tasks/get', ['taskId' => 'private']];
        yield 'extra read' => ['tasks/get', ['taskId' => TaskAdmissionFixture::ID, 'status' => 'completed']];
        yield 'result upload' => ['tasks/update', ['taskId' => TaskAdmissionFixture::ID, 'result' => ['ok' => true]]];
        yield 'extra cancel' => ['tasks/cancel', ['taskId' => TaskAdmissionFixture::ID, 'caller' => 'forged']];
    }

    public function test_final_encoded_bound_covers_escaped_multibyte_content_metadata_and_outer_envelope(): void
    {
        $connection = $this->database();
        $fixture = new TaskServingFixture($connection);
        $fixture->lifecycle->create();

        $result = StrictJson::fromObject([
            'content'           => [(object) ['type' => 'text', 'text' => str_repeat('雪"\\', 100)]],
            'structuredContent' => (object) ['ok' => true],
            'isError'           => true
        ]);
        $fixture->lifecycle->commandBus->execute(new RecordTaskState(
            'scope',
            TaskCancellationFixture::id(),
            'producer',
            $fixture->lifecycle->task()->version(),
            TaskState::create('completed', result: $result)
        ));
        $params = ['taskId' => TaskAdmissionFixture::ID];
        $response = $fixture->call('tasks/get', $params);
        self::assertSame('completed', $response['result']['status']);
        self::assertTrue($response['result']['result']['isError']);
        self::assertArrayNotHasKey('error', $response['result']);
        self::assertSame(json_decode($result->toString(), true), $response['result']['result']);
        $encoded = new McpResponder($fixture->capabilities)->respond(TaskServingFixture::request('tasks/get', $params));
        $bytes = strlen($encoded->toJson());
        $equal = new TaskServingFixture($connection, maxResponseBytes: $bytes);
        self::assertSame($response, $equal->call('tasks/get', $params));
        $over = new TaskServingFixture($connection, maxResponseBytes: $bytes - 1);
        $failure = $over->call('tasks/get', $params);
        self::assertSame(-32603, $failure['error']['code']);
        self::assertArrayNotHasKey('result', $failure);
        self::assertStringNotContainsString('雪', json_encode($failure, JSON_THROW_ON_ERROR));
        self::assertSame($result->toString(), $fixture->lifecycle->task()->state()->data()->get('result')->toString());
    }

    public function test_ordinary_tools_work_but_missing_or_foreign_declarations_cannot_activate_serving(): void
    {
        $fixture = new TaskServingFixture($this->database(), ['VIEW_AGENTS', 'UPDATE_AGENTS']);
        $auth = $fixture->lifecycle->input->auth;
        $calls = 0;
        $ordinary = new ProtectedTool(static function () use (&$calls): void {
            ++$calls;
        });
        $capabilities = $fixture->serving->capabilities(
            new McpServerInfo('Ordinary fixture', '1'),
            new McpToolRegistry([$ordinary]),
            $auth->provider,
            $auth->request,
            'ordinary',
            str_repeat('k', 32)
        );
        $response = new McpResponder($capabilities)->respond(TaskServingFixture::request(
            'tools/call',
            ['name' => 'agents.inspect', 'arguments' => ['label' => 'ordinary']],
            false
        ))->toArray();
        self::assertSame('complete', $response['result']['resultType']);
        self::assertSame(1, $calls);
        foreach ([[], ['unknown' => ['TOOL_START']]] as $bindings) {
            try {
                $fixture->serving->capabilities(
                    new McpServerInfo('Invalid fixture', '1'),
                    $fixture->registry,
                    $auth->provider,
                    $auth->request,
                    'invalid',
                    str_repeat('k', 32),
                    $bindings
                );
                self::fail('Unbound Task Tool cannot activate serving.');
            } catch (DomainException) {
                self::assertSame(0, $fixture->tool->starts);
            }
        }
    }

    public function test_response_purpose_is_closed_before_guard_and_after_refusal_or_other_methods(): void
    {
        $fixture = new TaskServingFixture($this->database());
        $auth = $fixture->lifecycle->input->auth;
        $guard = new class implements McpInvocationGuard {
            public function allows(string $method, ?string $name): bool
            {
                return $method !== 'tools/call';
            }
        };
        $admission = new AgentTaskRequestAdmission(
            $guard,
            new AgentTaskReadAdmission($auth->authority('TASK_ACK'), $auth),
            new AgentTaskReadAdmission($auth->authority('TASK_READ'), $auth)
        );
        $id = TaskCancellationFixture::id();
        self::assertFalse($admission->allowsRead('scope', $id));
        try {
            $admission->comparisonScope('scope', $id);
            self::fail('Unbound comparison must reject.');
        } catch (TaskRejected) {
            self::assertSame(0, $auth->nonce->consumptionCalls());
        }

        self::assertTrue($admission->allows('tasks/get', null));
        self::assertTrue($admission->allowsRead('scope', $id));
        self::assertSame($auth->comparison, $admission->comparisonScope('scope', $id));
        self::assertFalse($admission->allows('tools/call', 'example.operation'));
        self::assertFalse($admission->allowsRead('scope', $id));
        self::assertTrue($admission->allows('tasks/cancel', null));
        self::assertFalse($admission->allowsRead('scope', $id));
    }

    private function database(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        new DbalTaskSchema()->install($connection);

        return $connection;
    }
}
