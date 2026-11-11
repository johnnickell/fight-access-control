<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\Task;

use Fight\Common\Application\Mcp\Task\Service\TaskCancellationAdmission;
use Fight\Common\Domain\Mcp\Task\TaskId;

/**
 * Class AgentTaskCancellationObservationAdmission
 *
 * Supplies Common input observation/acquisition without enabling cancellation mutation.
 */
final readonly class AgentTaskCancellationObservationAdmission implements TaskCancellationAdmission
{
    /**
     * Constructs AgentTaskCancellationObservationAdmission
     */
    public function __construct(
        private AgentTaskAuthority $authority,
        private AgentTaskCancellationObservationScope $scope
    ) {
    }

    /**
     * @inheritDoc
     */
    public function allowsCancellation(string $scope, TaskId $id, string $caller): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public function allowsCancellationObservation(string $scope, TaskId $id, string $caller): bool
    {
        $principal = $this->authority->principal();

        return $principal !== null && $this->scope->allowsCancellationObservation($principal, $scope, $id, $caller);
    }
}
