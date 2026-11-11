<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Mcp\Task\Agent;

use Closure;
use Fight\Common\Application\Mcp\Task\McpTaskHandle;
use Fight\Common\Application\Mcp\Task\McpTaskTool;
use Fight\Common\Application\Mcp\Tool\McpProgressReporter;
use Fight\Common\Application\Mcp\Tool\McpToolInfo;
use Fight\Common\Application\Mcp\Tool\McpToolOutput;
use Fight\Common\Application\Messaging\Command\CommandBus;
use Fight\Common\Application\Validation\Data\ApplicationData;
use Fight\Common\Domain\Mcp\Task\Command\CreateTask;
use Fight\Common\Domain\Mcp\Task\TaskState;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Task\TaskAdmissionFixture;

final class TaskStartTool implements McpTaskTool
{
    public int $starts = 0;

    public function __construct(private readonly CommandBus $commandBus, private readonly ?Closure $submit = null)
    {
    }

    #[McpToolInfo('example.operation', 'Controlled durable operation', ['type' => 'object'], ['type' => 'object'])]
    public function handle(ApplicationData $input, McpProgressReporter $progress): McpToolOutput
    {
        return McpToolOutput::structured(['ok' => true]);
    }

    public function start(ApplicationData $input, McpProgressReporter $progress): McpTaskHandle
    {
        ++$this->starts;
        $definition = TaskAdmissionFixture::definition();
        $this->commandBus->execute(new CreateTask($definition, TaskState::create('working'), 10000));
        if ($this->submit !== null) {
            ($this->submit)();
        }

        return new McpTaskHandle($definition->id());
    }
}
