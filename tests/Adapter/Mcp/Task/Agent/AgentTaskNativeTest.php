<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Mcp\Task\Agent;

use Doctrine\DBAL\DriverManager;
use Fight\AccessControl\Adapter\Mcp\Task\Agent\AgentTaskServing;
use Fight\Common\Adapter\Mcp\Persistence\Task\Dbal\DbalTaskSchema;
use Fight\Common\Application\Mcp\McpResponder;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Task\TaskAdmissionFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

#[CoversClass(AgentTaskServing::class)]
final class AgentTaskNativeTest extends TestCase
{
    public function test_real_disconnect_independent_producer_and_new_guarded_http_read(): void
    {
        $database = tempnam(sys_get_temp_dir(), 'task-serving-');
        $marker = $database.'.committed';
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $database]);
        new DbalTaskSchema()->install($connection);
        $connection->close();
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $error);
        self::assertIsResource($listener);
        $address = stream_socket_get_name($listener, false);
        self::assertIsString($address);
        fclose($listener);
        $process = new Process([
            PHP_BINARY, '-S', $address, __DIR__.'/native-server.php'
        ], dirname(__DIR__, 5), [
            'TASK_SERVING_DATABASE' => $database,
            'TASK_SERVING_MARKER'   => $marker
        ], timeout: 10);
        $process->start();
        try {
            $socket = false;
            for ($attempt = 0; $attempt < 100 && $socket === false; ++$attempt) {
                $socket = @stream_socket_client('tcp://'.$address, $errorCode, $error, 0.05);
                if ($socket === false) {
                    usleep(10000);
                }
            }

            self::assertIsResource($socket, $process->getErrorOutput());
            fwrite($socket, $this->request($address, 'tools/call', ['name' => 'example.operation']));
            $deadline = microtime(true) + 3;
            while (!is_file($marker) && microtime(true) < $deadline && $process->isRunning()) {
                usleep(1000);
            }

            if (!is_file($marker)) {
                self::fail($process->getErrorOutput().stream_get_contents($socket));
            }

            // Close the TCP connection while submission is deliberately paused after actual durable commit,
            // before an acknowledgment is emitted. No request provider is available to the new producer.
            fclose($socket);
            $producer = new Process([PHP_BINARY, __DIR__.'/task-producer.php', $database], timeout: 5);
            self::assertSame(0, $producer->run(), $producer->getErrorOutput());
            $wire = $this->exchange($address, $this->request($address, 'tasks/get', [
                'taskId' => TaskAdmissionFixture::ID
            ]));
            self::assertStringContainsString('200 OK', $wire);
            $response = json_decode(explode("\r\n\r\n", $wire, 2)[1], true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('completed', $response['result']['status']);
            self::assertTrue($response['result']['result']['structuredContent']['ok']);
            self::assertArrayNotHasKey('error', $response);
            self::assertStringNotContainsString('original', $wire);
            self::assertStringNotContainsString('JSend', $wire);

            foreach (
                [
                'origin'    => ['Origin: https://untrusted.example'],
                'mirror'    => ['MCP-Method: tasks/cancel'],
                'name'      => ['MCP-Name: forged'],
                'transport' => ['Accept: text/plain']
                ] as $guard => $headers
            ) {
                $wire = $this->exchange($address, $this->request($address, 'tasks/get', [
                    'taskId' => TaskAdmissionFixture::ID
                ], $headers));
                self::assertStringNotContainsString('"status":"completed"', $wire, $guard);
                self::assertStringNotContainsString('"structuredContent"', $wire, $guard);
            }
        } finally {
            $process->stop();
            @unlink($marker);
            unlink($database);
        }
    }

    /** @param array<string, mixed> $parameters
     * @param list<string> $overrides
     */
    private function request(string $address, string $method, array $parameters, array $overrides = []): string
    {
        $json = TaskServingFixture::request($method, $parameters);
        $headers = [
            'Host'                 => $address,
            'Accept'               => 'application/json, text/event-stream',
            'Content-Type'         => 'application/json',
            'MCP-Protocol-Version' => McpResponder::PROTOCOL_VERSION,
            'MCP-Method'           => $method,
            'Content-Length'       => strlen($json),
            'Connection'           => 'close'
        ];
        $headers['MCP-Name'] = $method === 'tools/call' ? 'example.operation' : ($parameters['taskId'] ?? '');

        foreach ($overrides as $override) {
            [$name, $value] = explode(': ', $override, 2);
            $headers[$name] = $value;
        }

        $lines = ['POST /mcp HTTP/1.1'];
        foreach ($headers as $name => $value) {
            $lines[] = $name.': '.$value;
        }

        return implode("\r\n", [...$lines, '', $json]);
    }

    private function exchange(string $address, string $request): string
    {
        $socket = stream_socket_client('tcp://'.$address, $errorCode, $error, 1);
        self::assertIsResource($socket);
        stream_set_timeout($socket, 3);
        fwrite($socket, $request);
        $wire = stream_get_contents($socket);
        fclose($socket);

        return $wire;
    }
}
