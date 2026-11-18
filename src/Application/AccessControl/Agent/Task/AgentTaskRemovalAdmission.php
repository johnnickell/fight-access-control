<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\Task;

use Fight\Common\Application\Mcp\Task\Service\TaskRemovalAdmission;
use Fight\Common\Domain\Mcp\Task\TaskId;

/**
 * Class AgentTaskRemovalAdmission
 */
final readonly class AgentTaskRemovalAdmission implements TaskRemovalAdmission
{
    /**
     * Constructs AgentTaskRemovalAdmission
     */
    public function __construct(private AgentTaskAuthority $authority, private AgentTaskRemovalScope $scope)
    {
    }

    /**
     * @inheritDoc
     */
    public function allowsRemoval(string $scope, TaskId $id): bool
    {
        $principal = $this->authority->principal();

        return $principal !== null && $this->scope->allowsRemoval($principal, $scope, $id);
    }
}
