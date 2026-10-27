<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\Service;

use Closure;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use SensitiveParameter;

/**
 * Interface AgentContinuationFence
 *
 * Coordinates authoritative reads, scope and one bounded protected action with all relevant writers.
 */
interface AgentContinuationFence
{
    /**
     * Executes the callback exactly once synchronously while holding a qualified composite admission fence
     *
     * Acquire before any callback read. Coordinate the Agent credential/assignment/cohort fences, Permission
     * definition/reference fences and the supplied scope's relationship authorities with every writer, including
     * direct writes, deletion, restoration and external policy changes. Reads inside the callback must observe
     * authoritative current state after acquisition, never pre-lock or identity-map snapshots. Hold all protection
     * through the callback's bounded action and its real effect admission/completion boundary, including exceptions.
     * No release between checks and action, deferred callback, nested admission, callback retry or partial fence.
     * Unsupported/unverifiable participation must throw before invoking the callback. Propagate callback faults;
     * release in finally. Do not commit business work, consume nonces or claim rollback of external effects.
     *
     * The consumer qualifies lock ordering, connection/cohort participation, scope binding, timeouts, restoration
     * and the action's finite duration. Sequential repository reads alone do not implement this capability.
     *
     * @phpstan-param Closure(): void $admit
     */
    public function synchronized(
        AgentId $agentId,
        #[SensitiveParameter] AgentContinuationScope $scope,
        #[SensitiveParameter] Closure $admit
    ): void;
}
