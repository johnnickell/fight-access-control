<?php

declare(strict_types=1);

namespace Fight\AccessControl\Adapter\Mcp\Task\Agent;

use Fight\AccessControl\Application\AccessControl\Agent\Task\AgentTaskReadAdmission;
use Fight\Common\Application\Mcp\Http\McpInvocationGuard;
use Fight\Common\Application\Mcp\Task\Exception\TaskRejected;
use Fight\Common\Application\Mcp\Task\Service\TaskReadAdmission;
use Fight\Common\Domain\Mcp\Task\TaskId;

/**
 * Class AgentTaskRequestAdmission
 *
 * Binds response purpose only after Common's Origin, transport, decoding and mirror validation.
 * Share this request-scoped instance between the guarded HTTP endpoint and its GetTaskHandler.
 */
final class AgentTaskRequestAdmission implements McpInvocationGuard, TaskReadAdmission
{
    private ?AgentTaskReadAdmission $selectedAdmission = null;

    /**
     * Constructs AgentTaskRequestAdmission
     */
    public function __construct(
        private readonly McpInvocationGuard $invocationGuard,
        private readonly AgentTaskReadAdmission $acknowledgmentAdmission,
        private readonly AgentTaskReadAdmission $stateAdmission
    ) {
    }

    /**
     * @inheritDoc
     */
    public function allows(string $method, ?string $name): bool
    {
        $this->selectedAdmission = null;
        if (!$this->invocationGuard->allows($method, $name)) {
            return false;
        }

        $this->selectedAdmission = match ($method) {
            'tools/call' => $this->acknowledgmentAdmission,
            'tasks/get' => $this->stateAdmission,
            default => null
        };

        return true;
    }

    /**
     * @inheritDoc
     */
    public function allowsRead(string $scope, TaskId $id): bool
    {
        return $this->selectedAdmission?->allowsRead($scope, $id) ?? false;
    }

    /**
     * @inheritDoc
     */
    public function comparisonScope(string $scope, TaskId $id): string
    {
        if ($this->selectedAdmission === null) {
            throw new TaskRejected();
        }

        return $this->selectedAdmission->comparisonScope($scope, $id);
    }
}
