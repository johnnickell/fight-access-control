<?php

declare(strict_types=1);

use Doctrine\DBAL\DriverManager;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Task\TaskCancellationFixture;

require dirname(__DIR__, 5).'/vendor/autoload.php';

$path = ($argv ?? [])[1] ?? null;
if (!is_string($path)) {
    throw new RuntimeException('Missing controlled fixture storage.');
}

$fixture = new TaskCancellationFixture(DriverManager::getConnection([
    'driver' => 'pdo_sqlite', 'path' => $path
]));
$fixture->record('completed');
