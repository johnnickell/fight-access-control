<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Task;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Fight\Common\Application\Mcp\Task\Exception\TaskConflict;
use Fight\Common\Application\Mcp\Task\Exception\TaskRejected;
use RuntimeException;

final class TaskCancellationRaceWorker
{
    public static function observe(string $path): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $path]);
        $fixture = new TaskCancellationFixture($connection);
        print json_encode(['pid' => getmypid(), 'intent' => $fixture->intent()], JSON_THROW_ON_ERROR);
        $connection->close();
    }

    public static function run(string $path, string $operation, string $group, int $version, string $marker): void
    {
        $params = ['driver' => 'pdo_sqlite', 'path' => $path];
        $native = DriverManager::getConnection($params);
        $connection = new class ($params, $native->getDriver()) extends Connection {
            public string $marker;

            public function commit(): void
            {
                file_put_contents($this->marker.'.precommit', 'ready');
                if (str_ends_with($this->marker, '.first')) {
                    $deadline = microtime(true) + 10;
                    while (!is_file($this->marker.'.release')) {
                        if (microtime(true) > $deadline) {
                            throw new RuntimeException('Controlled race barrier timed out.');
                        }

                        usleep(1000);
                    }
                }

                parent::commit();
            }
        };
        $connection->marker = $marker;

        $fixture = new TaskCancellationFixture($connection);
        $fixture->beforeCancellation = static function () use ($fixture, $path): void {
            $fixture->allowed = !is_file($path.'.deny');
            file_put_contents($path.'.cancel-admitted', 'checked');
        };
        file_put_contents($marker.'.started', 'started');
        try {
            match ($operation) {
                'cancel' => $fixture->cancel(),
                'supply' => $fixture->supply($group),
                'acquire' => $fixture->acquire($group),
                'issue' => $fixture->issue(),
                default => $fixture->record($operation, $version)
            };
            $status = 'committed';
        } catch (TaskConflict | TaskRejected $failure) {
            $status = $failure::class;
        }

        print json_encode([
            'pid' => getmypid(), 'status' => $status, 'events' => $fixture->events
        ], JSON_THROW_ON_ERROR);
        $connection->close();
        $native->close();
    }
}
