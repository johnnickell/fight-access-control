<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\Task;

use Fight\Common\Application\Mcp\Task\Exception\TaskRejected;
use Fight\Common\Application\Mcp\Task\Service\TaskReadAdmission;
use Fight\Common\Domain\Mcp\Task\TaskDefinition;
use Fight\Common\Domain\Mcp\Task\TaskId;

/**
 * Class AgentTaskReadAdmission
 */
final readonly class AgentTaskReadAdmission implements TaskReadAdmission
{
    /**
     * Constructs AgentTaskReadAdmission
     */
    public function __construct(private AgentTaskAuthority $authority, private AgentTaskReadScope $scope)
    {
    }

    /**
     * @inheritDoc
     */
    public function allowsRead(string $scope, TaskId $id): bool
    {
        $principal = $this->authority->principal();

        return $principal !== null && $this->scope->allowsRead($principal, $scope, $id);
    }

    /**
     * @inheritDoc
     */
    public function comparisonScope(string $scope, TaskId $id): string
    {
        $principal = $this->authority->principal();
        if ($principal === null || !$this->scope->allowsRead($principal, $scope, $id)) {
            throw new TaskRejected();
        }

        return TaskDefinition::reference($this->scope->comparisonScope($principal, $scope, $id));
    }
}
