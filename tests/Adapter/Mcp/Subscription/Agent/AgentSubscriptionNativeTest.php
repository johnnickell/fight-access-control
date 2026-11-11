<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Mcp\Subscription\Agent;

use Fight\AccessControl\Adapter\Mcp\Subscription\Agent\AgentSubscriptionAdmission;
use Fight\AccessControl\Adapter\Mcp\Subscription\Agent\AgentSubscriptionFamilies;
use Fight\Common\Application\Mcp\McpResponder;
use Fight\Common\Application\Mcp\Protocol\McpRequestMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Process\Process;

#[CoversClass(AgentSubscriptionAdmission::class)]
#[CoversClass(AgentSubscriptionFamilies::class)]
final class AgentSubscriptionNativeTest extends TestCase
{
    public function test_native_http_delivers_ack_and_signal_before_finite_completion_and_preserves_guards(): void
    {
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $error);
        self::assertIsResource($listener);
        $address = stream_socket_get_name($listener, false);
        self::assertIsString($address);
        fclose($listener);
        $process = new Process([
            PHP_BINARY,
            '-d',
            'output_buffering=0',
            '-d',
            'zlib.output_compression=0',
            '-S',
            $address,
            __DIR__.'/native-server.php'
        ], dirname(__DIR__, 5), timeout: 10);
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
            stream_set_timeout($socket, 3);
            $json = json_encode([
                'jsonrpc' => '2.0',
                'id'      => '007',
                'method'  => 'subscriptions/listen',
                'params'  => [
                    '_meta'         => [
                        McpRequestMetadata::PROTOCOL_VERSION_KEY    => McpResponder::PROTOCOL_VERSION,
                        McpRequestMetadata::CLIENT_CAPABILITIES_KEY => new stdClass()
                    ],
                    'notifications' => ['toolsListChanged' => true, 'promptsListChanged' => true]
                ]
            ], JSON_THROW_ON_ERROR);
            $request = implode("\r\n", [
                'POST /mcp HTTP/1.1',
                'Host: '.$address,
                'Accept: application/json, text/event-stream',
                'Content-Type: application/json',
                'MCP-Protocol-Version: '.McpResponder::PROTOCOL_VERSION,
                'MCP-Method: subscriptions/listen',
                'Content-Length: '.strlen($json),
                'Connection: close',
                '',
                $json
            ]);
            $started = hrtime(true);
            fwrite($socket, $request);
            $wire = '';
            $frames = [];
            while (!feof($socket) && count($frames) < 2) {
                $line = fgets($socket);
                self::assertNotFalse($line, $process->getErrorOutput());
                $wire .= $line;
                if (str_starts_with($line, 'data: ')) {
                    $frames[] = json_decode(substr($line, 6), true, flags: JSON_THROW_ON_ERROR);
                }
            }

            $early = (hrtime(true) - $started) / 1000000000;
            // Allow cold Composer/bootstrap time while still requiring frames well before finite closure.
            self::assertLessThan(2.5, $early, $wire.$process->getErrorOutput());
            self::assertFalse(feof($socket));
            self::assertSame('notifications/subscriptions/acknowledged', $frames[0]['method']);
            self::assertSame(['toolsListChanged' => true], $frames[0]['params']['notifications']);
            self::assertSame('007', $frames[0]['params']['_meta']['io.modelcontextprotocol/subscriptionId']);
            self::assertSame('notifications/tools/list_changed', $frames[1]['method']);
            self::assertStringContainsString('HTTP/1.1 200 OK', $wire);
            self::assertStringContainsString('X-Accel-Buffering: no', $wire);
            $wire .= stream_get_contents($socket);
            fclose($socket);
            self::assertGreaterThanOrEqual($early + 1.0, (hrtime(true) - $started) / 1000000000);
            self::assertStringContainsString('"resultType":"complete"', $wire);
            self::assertStringNotContainsString('private-', $wire);
            self::assertStringNotContainsString('Controlled native failure', $process->getErrorOutput());

            $socket = stream_socket_client('tcp://'.$address, $errorCode, $error, 1);
            self::assertIsResource($socket);
            $rejectedRequest = str_replace(
                'Accept: application/json, text/event-stream',
                'Accept: application/json',
                $request
            );
            fwrite($socket, $rejectedRequest);
            $rejection = stream_get_contents($socket);
            fclose($socket);
            self::assertStringContainsString('406 Not Acceptable', $rejection);
            self::assertStringNotContainsString('acknowledged', $rejection);
        } finally {
            $process->stop();
        }
    }
}
