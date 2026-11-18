<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\Task;

use Fight\Common\Application\Mcp\Task\Service\TaskInputAdmission;
use Fight\Common\Domain\Mcp\Task\TaskId;

/**
 * Class AgentTaskInputAdmission
 */
final readonly class AgentTaskInputAdmission implements TaskInputAdmission
{
    /**
     * Constructs AgentTaskInputAdmission
     */
    public function __construct(
        private AgentTaskAuthority $submissionAuthority,
        private AgentTaskAuthority $observationAuthority,
        private AgentTaskAuthority $continuationAuthority,
        private AgentTaskInputScope $scope
    ) {
    }

    /**
     * @inheritDoc
     */
    public function allowsSubmission(string $scope, TaskId $id, string $caller): bool
    {
        $principal = $this->submissionAuthority->principal();

        return $principal !== null && $this->scope->allowsSubmission($principal, $scope, $id, $caller);
    }

    /**
     * @inheritDoc
     */
    public function allowsObservation(string $scope, TaskId $id, string $caller): bool
    {
        $principal = $this->observationAuthority->principal();

        return $principal !== null && $this->scope->allowsObservation($principal, $scope, $id, $caller);
    }

    /**
     * @inheritDoc
     */
    public function allowsContinuation(string $scope, TaskId $id, string $caller, string $group): bool
    {
        $principal = $this->continuationAuthority->principal();

        return $principal !== null && $this->scope->allowsContinuation($principal, $scope, $id, $caller, $group);
    }
}
