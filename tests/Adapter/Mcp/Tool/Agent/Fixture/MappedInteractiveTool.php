<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Mcp\Tool\Agent\Fixture;

use Closure;
use Fight\Common\Application\Mcp\Tool\Interaction\McpInputRequest;
use Fight\Common\Application\Mcp\Tool\Interaction\McpInputRequired;
use Fight\Common\Application\Mcp\Tool\Interaction\McpInputResponses;
use Fight\Common\Application\Mcp\Tool\Interaction\McpInteractiveTool;
use Fight\Common\Application\Mcp\Tool\McpProgressReporter;
use Fight\Common\Application\Mcp\Tool\McpToolInfo;
use Fight\Common\Application\Mcp\Tool\McpToolOutput;
use Fight\Common\Application\Validation\Data\ApplicationData;

final class MappedInteractiveTool implements McpInteractiveTool
{
    public int $calls = 0;

    public int $resumes = 0;

    public function __construct(private readonly Closure $dispatch)
    {
    }

    #[McpToolInfo('agents.confirm', 'Mapped interaction fixture', ['type' => 'object'], ['type' => 'object'], true)]
    public function handle(ApplicationData $input, McpProgressReporter $progress): McpInputRequired
    {
        ++$this->calls;

        return McpInputRequired::confirmation(['confirm' => McpInputRequest::form(
            'Confirm the fixture action',
            ['type' => 'object', 'properties' => ['label' => ['type' => 'string']], 'required' => ['label']],
            [['field' => 'label', 'label' => 'Private retained label', 'rules' => 'not_blank']]
        )]);
    }

    public function resume(
        ApplicationData $input,
        McpInputResponses $responses,
        McpProgressReporter $progress
    ): McpToolOutput {
        ++$this->resumes;
        ($this->dispatch)();
        $progress->report(1.0, 1.0, 'Complete');

        return McpToolOutput::structured(['label' => $responses->get('confirm')->content()?->get('label')]);
    }
}
