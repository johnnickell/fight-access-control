<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Mcp\Prompt\Agent\Fixture;

use Closure;
use Fight\Common\Application\Mcp\Prompt\McpPrompt;
use Fight\Common\Application\Mcp\Prompt\McpPromptInfo;
use Fight\Common\Application\Mcp\Prompt\McpPromptOutput;

final class PromptProvider implements McpPrompt
{
    public int $definitions = 0;

    public int $calls = 0;

    /** @var list<array<string, string>> */
    public array $arguments = [];

    /** @param Closure(array<string, string>): McpPromptOutput $content */
    public function __construct(public McpPromptInfo $definition, public Closure $content)
    {
    }

    public function info(): McpPromptInfo
    {
        ++$this->definitions;

        return $this->definition;
    }

    public function get(array $arguments): McpPromptOutput
    {
        ++$this->calls;
        $this->arguments[] = $arguments;

        return ($this->content)($arguments);
    }
}
