<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\Task;

use Fight\Common\Application\Mcp\Task\Service\TaskCreationAdmission;
use Fight\Common\Domain\Mcp\Task\TaskDefinition;

/**
 * Class AgentTaskCreationAdmission
 */
final readonly class AgentTaskCreationAdmission implements TaskCreationAdmission
{
    /**
     * Constructs AgentTaskCreationAdmission
     */
    public function __construct(private AgentTaskAuthority $authority, private AgentTaskCreationScope $scope)
    {
    }

    /**
     * @inheritDoc
     */
    public function allowsCreation(TaskDefinition $definition): bool
    {
        $principal = $this->authority->principal();

        return $principal !== null && $this->scope->allowsCreation($principal, $definition);
    }
}
