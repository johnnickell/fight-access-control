<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Mcp\Task\Agent;

use Doctrine\DBAL\DriverManager;
use Fight\AccessControl\Adapter\Mcp\Subscription\Agent\AgentTaskSubscriptionAdmission;
use Fight\AccessControl\Adapter\Mcp\Task\Agent\AgentTaskRequestAdmission;
use Fight\AccessControl\Adapter\Mcp\Task\Agent\AgentTaskServing;
use Fight\AccessControl\Application\AccessControl\Agent\Task\AgentTaskCurrentReadAdmission;
use Fight\AccessControl\Application\AccessControl\Agent\Task\AgentTaskDisclosureGuard;
use Fight\AccessControl\Application\AccessControl\Agent\Task\AgentTaskDisclosureScope;
use Fight\Common\Adapter\Mcp\Persistence\Subscription\Dbal\DbalSubscriptionSchema;
use Fight\Common\Adapter\Mcp\Persistence\Task\Dbal\DbalTaskSchema;
use Fight\Common\Application\Mcp\McpResponder;
use Fight\Common\Application\Mcp\Task\McpTasks;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Task\TaskAdmissionFixture;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Task\TaskCancellationFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

#[CoversClass(AgentTaskServing::class)]
#[CoversClass(AgentTaskRequestAdmission::class)]
#[CoversClass(AgentTaskSubscriptionAdmission::class)]
#[CoversClass(AgentTaskCurrentReadAdmission::class)]
#[CoversClass(AgentTaskDisclosureGuard::class)]
#[CoversClass(AgentTaskDisclosureScope::class)]
final class AgentTaskLiveNativeTest extends TestCase
{
    /** @return iterable<string, array{int|string, bool}> */
    public static function requests(): iterable
    {
        yield 'integer graceful' => [7, false];
        yield 'mixed escaped string abrupt' => ["007\"/é\r\ndata: inert", true];
    }

    #[DataProvider('requests')]
    public function test_native_updates_and_reconnect(int|string $id, bool $abrupt): void
    {
        $database = tempnam(sys_get_temp_dir(), 'task-live-');
        $tracking = tempnam(sys_get_temp_dir(), 'task-live-tracking-');
        self::assertIsString($database);
        self::assertIsString($tracking);
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $database]);
        $trackingConnection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $tracking]);
        new DbalTaskSchema()->install($connection);
        new DbalSubscriptionSchema()->install($trackingConnection);
        $producer = new TaskCancellationFixture($connection);
        $producer->create();

        $listener = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $error);
        self::assertIsResource($listener);
        $address = stream_socket_get_name($listener, false);
        self::assertIsString($address);
        fclose($listener);
        $server = new Process([
            PHP_BINARY, '-d', 'output_buffering=0', '-d', 'zlib.output_compression=0',
            '-S', $address, __DIR__.'/live-server.php'
        ], dirname(__DIR__, 5), ['TASK_LIVE_DATABASE' => $database, 'TASK_LIVE_TRACKING' => $tracking], timeout: 30);
        $server->start();
        try {
            $socket = false;
            for ($attempt = 0; $attempt < 100 && $socket === false; ++$attempt) {
                $socket = @stream_socket_client('tcp://'.$address, $errorCode, $error, 0.05);
                if ($socket === false) {
                    usleep(10000);
                }
            }

            self::assertIsResource($socket, $server->getErrorOutput());
            stream_set_timeout($socket, 20);
            $request = json_decode(TaskServingFixture::request('subscriptions/listen', [
                'notifications' => ['taskIds' => [TaskAdmissionFixture::ID], 'toolsListChanged' => true]
            ]), flags: JSON_THROW_ON_ERROR);
            $request->id = $id;
            $json = json_encode($request, JSON_THROW_ON_ERROR);
            fwrite($socket, implode("\r\n", [
                'POST '.($abrupt ? '/mixed' : '/mcp').' HTTP/1.1', 'Host: '.$address,
                'Accept: application/json, text/event-stream', 'Content-Type: application/json',
                'MCP-Protocol-Version: '.McpResponder::PROTOCOL_VERSION, 'MCP-Method: subscriptions/listen',
                'Content-Length: '.strlen($json), 'Connection: close', '', $json
            ]));
            $wire = '';
            $ack = $this->frame($socket, $wire);
            self::assertSame('notifications/subscriptions/acknowledged', $ack['method']);
            $expectedFilters = ['taskIds' => [TaskAdmissionFixture::ID]];
            if ($abrupt) {
                $expectedFilters['toolsListChanged'] = true;
            }

            self::assertSame($expectedFilters, $ack['params']['notifications']);
            self::assertSame($id, $ack['params']['_meta']['io.modelcontextprotocol/subscriptionId']);
            self::assertStringContainsString('X-Accel-Buffering: no', $wire);
            self::assertStringNotContainsString('Content-Length:', $wire);
            $baseline = $this->frame($socket, $wire);
            self::assertSame('working', $baseline['params']['status']);
            self::assertFalse(feof($socket), 'Frames arrive before finite stream closure.');

            $this->produce($database, 'input');
            $input = $this->frame($socket, $wire);
            self::assertSame('input_required', $input['params']['status']);
            self::assertNotEmpty($input['params']['inputRequests']);
            $this->produce($database, 'cancel');
            self::assertNotNull($producer->intent()->get('cancellation'));
            $this->produce($database, 'finish');
            $terminal = $this->frame($socket, $wire);
            self::assertSame('completed', $terminal['params']['status']);
            self::assertTrue($terminal['params']['result']['structuredContent']['ok']);
            self::assertSame($id, $terminal['params']['_meta']['io.modelcontextprotocol/subscriptionId']);
            self::assertArrayNotHasKey('cancellation', $terminal['params']);
            self::assertGreaterThan(0, strcmp(
                $terminal['params']['_meta'][McpTasks::REVISION]['value'],
                $baseline['params']['_meta'][McpTasks::REVISION]['value']
            ));
            if ($abrupt) {
                $server->signal(9);
                $server->wait();
            } else {
                $wire .= stream_get_contents($socket);
                self::assertStringContainsString('"resultType":"complete"', $wire);
            }

            fclose($socket);
            self::assertStringNotContainsString('notifications/tools', $wire);
            self::assertStringNotContainsString('original', $wire);
            self::assertStringNotContainsString('Private', $wire);
            $reconnect = new TaskServingFixture($connection, ['TASK_READ']);
            $get = $reconnect->call('tasks/get', ['taskId' => TaskAdmissionFixture::ID])['result'];
            self::assertSame('completed', $get['status']);
            self::assertSame($terminal['params']['_meta'][McpTasks::REVISION], $get['_meta'][McpTasks::REVISION]);
            // Tracking contains technical scope/filter context only, never protected state or answers.
            $rows = $trackingConnection->fetchAllAssociative('SELECT * FROM fight_mcp_subscriptions');
            self::assertNotEmpty($rows);
            $stored = json_encode($rows, JSON_THROW_ON_ERROR);
            $privateFields = [
                'structuredContent', 'inputRequests', 'inputResponses', 'encrypted:fixture', McpTasks::REVISION
            ];
            foreach ($privateFields as $private) {
                self::assertStringNotContainsString($private, $stored);
            }

            self::assertSame($abrupt ? 0 : 1, (int) ($rows[0]['closed_at'] > 0));
        } finally {
            $server->stop();
            $connection->close();
            $trackingConnection->close();
            unlink($database);
            unlink($tracking);
        }
    }

    /** @param resource $socket
     * @return array<string, mixed>
     */
    private function frame($socket, string &$wire): array
    {
        while (!feof($socket)) {
            $line = fgets($socket);
            self::assertNotFalse($line, $wire);
            $wire .= $line;
            if (str_starts_with($line, 'data: ')) {
                self::assertLessThanOrEqual(8192, strlen($line) + 1);

                return json_decode(substr($line, 6), true, flags: JSON_THROW_ON_ERROR);
            }
        }

        self::fail('Stream closed before expected frame: '.$wire);
    }

    private function produce(string $database, string $action): void
    {
        $producer = new Process([PHP_BINARY, __DIR__.'/live-producer.php', $database, $action], timeout: 5);
        self::assertSame(0, $producer->run(), $producer->getErrorOutput());
    }
}
