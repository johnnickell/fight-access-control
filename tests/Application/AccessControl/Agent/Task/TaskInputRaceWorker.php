<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Task;

use Doctrine\DBAL\DriverManager;
use Fight\AccessControl\Application\AccessControl\Agent\Task\AgentTaskUpdateAdmission;
use Fight\Common\Adapter\Mcp\Persistence\Task\Dbal\DbalTaskRepository;
use Fight\Common\Adapter\Mcp\Persistence\Task\Dbal\DbalTaskTransaction;
use Fight\Common\Adapter\Messaging\Command\Sync\Routing\InMemoryCommandRouter;
use Fight\Common\Adapter\Messaging\Command\Sync\RoutingCommandBus;
use Fight\Common\Adapter\Messaging\Event\Sync\SimpleEventDispatcher;
use Fight\Common\Application\Mcp\Task\CommandHandler\RecordTaskStateHandler;
use Fight\Common\Application\Mcp\Task\CommandHandler\SupplyTaskInputHandler;
use Fight\Common\Application\Mcp\Task\Exception\TaskConflict;
use Fight\Common\Application\Mcp\Task\Service\ToolTaskPayloadPolicy;
use Fight\Common\Domain\Mcp\Task\Command\RecordTaskState;
use Fight\Common\Domain\Mcp\Task\Command\SupplyTaskInput;
use Fight\Common\Domain\Mcp\Task\TaskId;
use Fight\Common\Domain\Mcp\Task\TaskState;
use Fight\Common\Domain\Value\Basic\StrictJson;

final class TaskInputRaceWorker
{
    public static function run(string $path, string $mode, string $key, int $value, string $ready): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $path]);
        $connection->executeStatement('PRAGMA busy_timeout=5000');

        $transaction = new DbalTaskTransaction($connection);
        $repository = new DbalTaskRepository($transaction);
        $fixture = new TaskInputAdmissionFixture();
        $events = new SimpleEventDispatcher();
        $policy = new ToolTaskPayloadPolicy(static fn(): bool => true);
        $clock = static fn(): int => 1794312000000;
        $supply = new SupplyTaskInputHandler($repository, $transaction, $fixture->input(), $policy, $events, $clock);
        $record = new RecordTaskStateHandler(
            $repository,
            $transaction,
            new AgentTaskUpdateAdmission($fixture->auth->authority('TASK_UPDATE'), $fixture->auth),
            $policy,
            $events,
            $clock
        );
        $router = new InMemoryCommandRouter();
        $router->registerHandler($supply::commandRegistration(), $supply);
        $router->registerHandler($record::commandRegistration(), $record);

        $commands = new RoutingCommandBus($router);
        file_put_contents($ready, 'ready');
        try {
            $id = TaskId::fromString(TaskAdmissionFixture::ID);
            if ($mode === 'terminal') {
                $commands->execute(new RecordTaskState('scope', $id, 'producer', 2, TaskState::create('cancelled')));
            } else {
                $commands->execute(new SupplyTaskInput('scope', $id, 'caller', StrictJson::fromObject([
                    $key => (object) ['action' => 'accept', 'content' => (object) ['value' => $value]]
                ])));
            }

            print 'committed';
        } catch (TaskConflict) {
            print 'conflict';
        } finally {
            $connection->close();
        }
    }
}
