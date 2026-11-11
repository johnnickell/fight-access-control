<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\Task;

use Fight\Common\Application\Mcp\Task\Service\TaskCancellationAdmission;
use Fight\Common\Domain\Mcp\Task\TaskId;

/**
 * Class AgentTaskCancellationAdmission
 */
final readonly class AgentTaskCancellationAdmission implements TaskCancellationAdmission
{
    /**
     * Constructs AgentTaskCancellationAdmission
     */
    public function __construct(
        private AgentTaskAuthority $cancellationAuthority,
        private AgentTaskCancellationScope $cancellationScope,
        private AgentTaskAuthority $observationAuthority,
        private AgentTaskCancellationObservationScope $observationScope
    ) {
    }

    /**
     * @inheritDoc
     */
    public function allowsCancellation(string $scope, TaskId $id, string $caller): bool
    {
        $principal = $this->cancellationAuthority->principal();

        return $principal !== null && $this->cancellationScope->allowsCancellation($principal, $scope, $id, $caller);
    }

    /**
     * @inheritDoc
     */
    public function allowsCancellationObservation(string $scope, TaskId $id, string $caller): bool
    {
        $principal = $this->observationAuthority->principal();

        return $principal !== null
            && $this->observationScope->allowsCancellationObservation($principal, $scope, $id, $caller);
    }
}
