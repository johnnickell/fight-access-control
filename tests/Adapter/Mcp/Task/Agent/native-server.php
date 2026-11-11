<?php

declare(strict_types=1);

use Doctrine\DBAL\DriverManager;
use Fight\Common\Adapter\Mcp\Http\DenyAllMcpOriginPolicy;
use Fight\Common\Adapter\Mcp\Http\McpRequestHandler;
use Fight\Common\Adapter\Mcp\Http\McpResponseEmitter;
use Fight\Common\Adapter\Mcp\Http\McpResponseFactory;
use Fight\Common\Application\Mcp\Http\McpInvocationGuard;
use Fight\Common\Application\Mcp\McpDiagnostics;
use Fight\Test\AccessControl\Adapter\Mcp\Task\Agent\TaskServingFixture;
use Slim\Factory\ServerRequestCreatorFactory;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\StreamFactory;

require dirname(__DIR__, 5).'/vendor/autoload.php';

$database = getenv('TASK_SERVING_DATABASE');
$marker = getenv('TASK_SERVING_MARKER');
if (!is_string($database) || !is_string($marker)) {
    throw new RuntimeException('Missing controlled fixture storage.');
}

// Controlled request authentication/crypto inputs; real authority, nonce and independently admitted
// Common paths are composed below. The owning guard verifies the selected mirror before dispatch.
$method = $_SERVER['HTTP_MCP_METHOD'] ?? '';
$start = $method === 'tools/call';
$guard = new readonly class ($method) implements McpInvocationGuard {
    public function __construct(private string $method)
    {
    }

    public function allows(string $method, ?string $name): bool
    {
        return $method === $this->method && in_array($method, ['tools/call', 'tasks/get'], true)
            && ($method !== 'tools/call' || $name === 'example.operation');
    }
};
$fixture = new TaskServingFixture(
    DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $database]),
    $start ? ['TOOL_START', 'TASK_CREATE', 'TASK_ACK'] : ['TASK_READ'],
    submit: $start ? static function () use ($marker): void {
        file_put_contents($marker, 'committed');
        usleep(300000);
    } : null,
    guard: $guard
);
$endpoint = new McpRequestHandler(
    $fixture->capabilities,
    new DenyAllMcpOriginPolicy(),
    $fixture->admission,
    new McpResponseFactory(new ResponseFactory(), new StreamFactory(), new class implements McpDiagnostics {
        public function record(Throwable $failure): void
        {
            error_log('Controlled Task failure: '.$failure::class);
        }
    })
);
new McpResponseEmitter()->emit($endpoint->handle(
    ServerRequestCreatorFactory::create()->createServerRequestFromGlobals()
));
