<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Task;

use Fight\AccessControl\Application\AccessControl\Agent\Task\AgentTaskCancellationObservationAdmission;
use Fight\AccessControl\Application\AccessControl\Agent\Task\AgentTaskCancellationObservationScope;
use Fight\AccessControl\Application\AccessControl\Agent\Task\AgentTaskInputAdmission;
use Fight\AccessControl\Application\AccessControl\Agent\Task\AgentTaskInputScope;
use Fight\AccessControl\Domain\AccessControl\Agent\AuthenticatedAgentPrincipal;
use Fight\Common\Domain\Mcp\Task\TaskId;
use RuntimeException;

final class TaskInputAdmissionFixture implements AgentTaskInputScope, AgentTaskCancellationObservationScope
{
    public const array GRANTS = [
        'TASK_CREATE', 'TASK_UPDATE', 'TASK_READ', 'INPUT_ISSUE', 'INPUT_SUBMIT', 'INPUT_OBSERVE',
        'INPUT_CONTINUE', 'INTENT_OBSERVE'
    ];

    public bool $submission = true;

    public bool $observation = true;

    public bool $continuation = true;

    public bool $intent = true;

    public bool $fault = false;

    public int $checks = 0;

    public ?string $group = null;

    public readonly TaskAdmissionFixture $auth;

    /** @param list<string> $grants */
    public function __construct(array $grants = self::GRANTS, bool $complete = true)
    {
        $this->auth = new TaskAdmissionFixture($grants, complete: $complete);
    }

    public function input(): AgentTaskInputAdmission
    {
        return new AgentTaskInputAdmission(
            $this->auth->authority('INPUT_SUBMIT'),
            $this->auth->authority('INPUT_OBSERVE'),
            $this->auth->authority('INPUT_CONTINUE'),
            $this
        );
    }

    public function cancellation(): AgentTaskCancellationObservationAdmission
    {
        return new AgentTaskCancellationObservationAdmission($this->auth->authority('INTENT_OBSERVE'), $this);
    }

    public function allowsSubmission(
        AuthenticatedAgentPrincipal $principal,
        string $scope,
        TaskId $id,
        string $caller
    ): bool {
        return $this->bound($principal, $scope, $id, $caller) && $this->submission;
    }

    public function allowsObservation(
        AuthenticatedAgentPrincipal $principal,
        string $scope,
        TaskId $id,
        string $caller
    ): bool {
        return $this->bound($principal, $scope, $id, $caller) && $this->observation;
    }

    public function allowsContinuation(
        AuthenticatedAgentPrincipal $principal,
        string $scope,
        TaskId $id,
        string $caller,
        string $group
    ): bool {
        return $this->bound($principal, $scope, $id, $caller) && $this->continuation
            && ($this->group === null || $this->group === $group);
    }

    public function allowsCancellationObservation(
        AuthenticatedAgentPrincipal $principal,
        string $scope,
        TaskId $id,
        string $caller
    ): bool {
        return $this->bound($principal, $scope, $id, $caller) && $this->intent;
    }

    private function bound(AuthenticatedAgentPrincipal $principal, string $scope, TaskId $id, string $caller): bool
    {
        ++$this->checks;
        if ($this->fault) {
            throw new RuntimeException('Private input scope fault.');
        }

        return $principal->getAgentId()->equals($this->auth->agent->getId())
            && $scope === 'scope' && $id->toString() === TaskAdmissionFixture::ID && $caller === 'caller';
    }
}
