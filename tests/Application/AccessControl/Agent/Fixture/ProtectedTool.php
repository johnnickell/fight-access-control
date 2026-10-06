<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Fixture;

use Closure;
use Fight\AccessControl\Application\AccessControl\Agent\Attribute\RequiresAgentPermission;
use Fight\Common\Application\Attribute\Validation;
use Fight\Common\Application\Mcp\McpProgressReporter;
use Fight\Common\Application\Mcp\Tool\McpTool;
use Fight\Common\Application\Mcp\Tool\McpToolInfo;
use Fight\Common\Application\Mcp\Tool\McpToolOutput;
use Fight\Common\Application\Validation\Data\ApplicationData;

final class ProtectedTool implements McpTool
{
    public int $calls = 0;

    public function __construct(private readonly Closure $dispatch)
    {
    }

    #[McpToolInfo(
        'agents.inspect',
        'Protected fixture',
        ['type' => 'object', 'properties' => ['label' => ['type' => 'string']], 'required' => ['label']],
        ['type' => 'object']
    )]
    #[RequiresAgentPermission('VIEW_AGENTS')]
    #[RequiresAgentPermission('UPDATE_AGENTS')]
    #[Validation(rules: [['field' => 'label', 'label' => 'Protected label', 'rules' => 'not_blank']])]
    public function handle(ApplicationData $input, McpProgressReporter $progress): McpToolOutput
    {
        ++$this->calls;
        ($this->dispatch)();
        $progress->report(1.0, 1.0, 'Complete');

        return McpToolOutput::structured(['label' => $input->get('label')]);
    }
}
