<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\Task;

use Fight\Common\Application\Mcp\Task\Service\TaskUpdateAdmission;
use Fight\Common\Domain\Mcp\Task\TaskId;

/**
 * Class AgentTaskUpdateAdmission
 */
final readonly class AgentTaskUpdateAdmission implements TaskUpdateAdmission
{
    /**
     * Constructs AgentTaskUpdateAdmission
     */
    public function __construct(private AgentTaskAuthority $authority, private AgentTaskUpdateScope $scope)
    {
    }

    /**
     * @inheritDoc
     */
    public function allowsUpdate(string $scope, TaskId $id, string $producer): bool
    {
        $principal = $this->authority->principal();

        return $principal !== null && $this->scope->allowsUpdate($principal, $scope, $id, $producer);
    }
}
