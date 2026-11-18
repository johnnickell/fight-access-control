<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\Task;

use Fight\AccessControl\Application\AccessControl\Agent\Notice\AgentNoticeAuthority;
use Fight\Common\Application\Mcp\Task\Exception\TaskRejected;
use Fight\Common\Application\Mcp\Task\Service\TaskReadAdmission;
use Fight\Common\Domain\Mcp\Task\TaskDefinition;
use Fight\Common\Domain\Mcp\Task\TaskId;

/**
 * Class AgentTaskCurrentReadAdmission
 *
 * Rechecks original-bound authority for shared get/live Queries without retaining an opening request snapshot.
 */
final readonly class AgentTaskCurrentReadAdmission implements TaskReadAdmission
{
    /**
     * Constructs AgentTaskCurrentReadAdmission
     */
    public function __construct(private AgentNoticeAuthority $authority, private AgentTaskReadScope $scope)
    {
    }

    /**
     * @inheritDoc
     */
    public function allowsRead(string $scope, TaskId $id): bool
    {
        $principal = $this->authority->resolve();

        return $principal !== null && $this->scope->allowsRead($principal, $scope, $id);
    }

    /**
     * @inheritDoc
     */
    public function comparisonScope(string $scope, TaskId $id): string
    {
        $principal = $this->authority->resolve();
        if ($principal === null || !$this->scope->allowsRead($principal, $scope, $id)) {
            throw new TaskRejected();
        }

        return TaskDefinition::reference($this->scope->comparisonScope($principal, $scope, $id));
    }
}
