<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Mcp\Task\Agent;

use Fight\AccessControl\Adapter\Mcp\Task\Agent\AgentTaskRequestAdmission;
use Fight\AccessControl\Adapter\Mcp\Task\Agent\AgentTaskServing;
use Fight\AccessControl\Application\AccessControl\Agent\Task\AgentTaskReadAdmission;
use Fight\Common\Application\Mcp\Http\McpInvocationGuard;
use Fight\Common\Application\Mcp\Protocol\McpServerInfo;
use Fight\Common\Application\Mcp\Task\CommandHandler\CreateTaskHandler;
use Fight\Common\Application\Mcp\Task\CommandHandler\RequestTaskCancellationHandler;
use Fight\Common\Application\Mcp\Task\CommandHandler\SupplyTaskInputHandler;
use Fight\Common\Application\Mcp\Task\Exception\TaskRejected;
use Fight\Common\Application\Mcp\Task\QueryHandler\GetTaskHandler;
use Fight\Common\Application\Mcp\Task\Service\CommittedTaskReader;
use Fight\Common\Application\Mcp\Task\Service\TaskCancellationAdmission;
use Fight\Common\Application\Mcp\Task\Service\TaskCreationAdmission;
use Fight\Common\Application\Mcp\Task\Service\TaskInputAdmission;
use Fight\Common\Application\Mcp\Task\Service\TaskPayloadPolicy;
use Fight\Common\Application\Mcp\Task\Service\TaskReadAdmission;
use Fight\Common\Application\Mcp\Tool\McpToolRegistry;
use Fight\Common\Application\Messaging\Event\EventDispatcher;
use Fight\Common\Application\Repository\TransactionalUnitOfWork;
use Fight\Common\Domain\Mcp\Task\Command\CreateTask;
use Fight\Common\Domain\Mcp\Task\Query\GetTask;
use Fight\Common\Domain\Mcp\Task\TaskId;
use Fight\Common\Domain\Mcp\Task\TaskRepository;
use Fight\Common\Domain\Mcp\Task\TaskState;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Task\TaskAdmissionFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AgentTaskRequestAdmission::class)]
#[CoversClass(AgentTaskServing::class)]
final class AgentTaskServingUnitTest extends TestCase
{
    public function test_response_purpose_is_request_scoped_and_guard_denial_clears_the_previous_selection(): void
    {
        $fixture = new TaskAdmissionFixture(['ACK']);
        $guard = $this->createStub(McpInvocationGuard::class);
        $allowed = true;
        $guard->method('allows')->willReturnCallback(static function () use (&$allowed): bool {
            return $allowed;
        });
        $admission = new AgentTaskRequestAdmission(
            $guard,
            new AgentTaskReadAdmission($fixture->authority('ACK'), $fixture),
            new AgentTaskReadAdmission($fixture->authority('READ'), $fixture)
        );
        $id = TaskId::fromString(TaskAdmissionFixture::ID);
        self::assertFalse($admission->allowsRead('scope', $id));
        self::assertTrue($admission->allows('tools/call', 'tool'));
        self::assertTrue($admission->allowsRead('scope', $id));
        self::assertSame('reader-visibility-v1', $admission->comparisonScope('scope', $id));
        self::assertTrue($admission->allows('tasks/get', null));
        self::assertFalse($admission->allowsRead('scope', $id));
        self::assertTrue($admission->allows('subscriptions/listen', null));
        self::assertFalse($admission->allowsRead('scope', $id));
        self::assertTrue($admission->allows('server/discover', null));
        $allowed = false;
        self::assertFalse($admission->allows('tools/call', 'tool'));
        $this->expectException(TaskRejected::class);
        $admission->comparisonScope('scope', $id);
    }

    public function test_serving_wires_real_handlers_and_denies_protected_storage_work(): void
    {
        $repository = $this->createMock(TaskRepository::class);
        $repository->expects(self::never())->method('getById');
        $transaction = $this->createStub(TransactionalUnitOfWork::class);
        $transaction->method('commitTransactional')->willReturnCallback(static fn(callable $work): mixed => $work());
        $eventDispatcher = $this->createMock(EventDispatcher::class);
        $eventDispatcher->expects(self::never())->method('trigger');
        $reader = $this->createStub(CommittedTaskReader::class);
        $payload = $this->createStub(TaskPayloadPolicy::class);
        $clock = static fn(): int => 1794312000000;
        $serving = new AgentTaskServing(
            new CreateTaskHandler(
                $repository,
                $transaction,
                $this->createStub(TaskCreationAdmission::class),
                $payload,
                $eventDispatcher,
                $clock
            ),
            new GetTaskHandler($reader, $this->createStub(TaskReadAdmission::class), $payload, $clock),
            new SupplyTaskInputHandler(
                $repository,
                $transaction,
                $this->createStub(TaskInputAdmission::class),
                $payload,
                $eventDispatcher,
                $clock
            ),
            new RequestTaskCancellationHandler(
                $repository,
                $transaction,
                $reader,
                $this->createStub(TaskCancellationAdmission::class),
                $eventDispatcher,
                $clock
            ),
            'scope',
            'caller'
        );
        $fixture = new TaskAdmissionFixture();
        $capabilities = $serving->capabilities(
            new McpServerInfo('unit-server', '1'),
            new McpToolRegistry([]),
            $fixture->provider,
            $fixture->request,
            'unit',
            str_repeat('s', 32)
        );

        self::assertSame($serving->tasks(), $capabilities->capabilityFor('tasks/get'));
        $query = new GetTask('scope', TaskId::fromString(TaskAdmissionFixture::ID));
        self::assertNull($serving->queryBus()->fetch($query));
        self::assertArrayHasKey('extensions', $capabilities->advertisedCapabilities());
        $this->expectException(TaskRejected::class);
        $serving->commandBus()->execute(new CreateTask(
            TaskAdmissionFixture::definition(),
            TaskState::create('working')
        ));
    }
}
