<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Service\Conformance;

/**
 * Consumer-owned persisted contract/composition fault injection, not a second compatibility implementation
 *
 * Real bindings change actual persisted versions or remove the named local participant, including on restart.
 * A capability flag alone cannot qualify adapter participation. Switches use the real conflicting storage fence;
 * consumer qualification additionally excludes old binaries at storage/trusted admission and tests every external
 * policy, Permission/tier, destination and key writer on independent connections/processes.
 */
interface AgentCohortFixture
{
    /** Removes contract storage/availability, changes a version, or removes the named runtime capability */
    public function breakCohort(string $failure): void;

    /** Switches under the conflicting cohort fence, with a strictly increasing persisted generation */
    public function switchCohort(int $generation): void;
}
