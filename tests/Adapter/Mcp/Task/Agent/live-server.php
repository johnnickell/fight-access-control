<?php

declare(strict_types=1);

use Doctrine\DBAL\DriverManager;
use Fight\Common\Adapter\Mcp\Http\DenyAllMcpOriginPolicy;
use Fight\Common\Adapter\Mcp\Http\McpRequestHandler;
use Fight\Common\Adapter\Mcp\Http\McpResponseEmitter;
use Fight\Common\Adapter\Mcp\Http\McpResponseFactory;
use Fight\Test\AccessControl\Adapter\Mcp\Task\Agent\TaskLiveFixture;
use Slim\Factory\ServerRequestCreatorFactory;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\StreamFactory;

require dirname(__DIR__, 5).'/vendor/autoload.php';

$database = getenv('TASK_LIVE_DATABASE');
$tracking = getenv('TASK_LIVE_TRACKING');
if (!is_string($database) || !is_string($tracking)) {
    throw new RuntimeException('Missing controlled live fixture storage.');
}

// Real request authentication/nonce/current authority over controlled HMAC and policy/fence fixtures.
// This is deliberately not a production all-writer lock, cryptography or HTTP hosting implementation.
$fixture = new TaskLiveFixture(
    DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $database]),
    DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $tracking]),
    // Three separately bootstrapped producers must fit even under coverage and concurrent package gates.
    lifetimeSeconds: 15,
    mixed: ($_SERVER['REQUEST_URI'] ?? '') === '/mixed'
);
$endpoint = new McpRequestHandler(
    $fixture->ordinary->capabilities,
    new DenyAllMcpOriginPolicy(),
    $fixture->ordinary->admission,
    new McpResponseFactory(new ResponseFactory(), new StreamFactory(), $fixture)
);
new McpResponseEmitter()->emit($endpoint->handle(
    ServerRequestCreatorFactory::create()->createServerRequestFromGlobals()
));
