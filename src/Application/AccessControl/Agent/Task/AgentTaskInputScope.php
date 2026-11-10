<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\Task;

use Fight\AccessControl\Domain\AccessControl\Agent\AuthenticatedAgentPrincipal;
use Fight\Common\Domain\Mcp\Task\TaskId;

/**
 * Interface AgentTaskInputScope
 */
interface AgentTaskInputScope
{
    /**
     * Returns current independently bound caller and original-operation submission scope
     *
     * Resolve trusted caller/Task/original operation and arguments, never infer authority from supplied selectors,
     * a prior read or answer. Common separately verifies retained request identities, contracts, groups and expiry.
     * Throw on unavailable policy; do not mutate business state or run protected validators during this check.
     */
    public function allowsSubmission(
        AuthenticatedAgentPrincipal $principal,
        string $scope,
        TaskId $id,
        string $caller
    ): bool;

    /**
     * Returns independent scope for complete protected answers and original definition observation
     *
     * Observation includes original arguments and cancellation intent, not merely public outstanding forms.
     * Bind the trusted consumer observer to the original caller/operation. Submission grants no observation.
     */
    public function allowsObservation(
        AuthenticatedAgentPrincipal $principal,
        string $scope,
        TaskId $id,
        string $caller
    ): bool;

    /**
     * Returns current original caller and exact operation-group acquisition scope
     *
     * Use the existing synchronized original-caller continuation guard for each protected effect. Producer or
     * observer authority, an accepted answer and even successful durable acquisition never replace that guard.
     * A qualified consumer fence coordinates participating scope/authority writers; no nonce is replayed.
     */
    public function allowsContinuation(
        AuthenticatedAgentPrincipal $principal,
        string $scope,
        TaskId $id,
        string $caller,
        string $group
    ): bool;
}
