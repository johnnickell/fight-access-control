<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Service\Conformance;

use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentIssuance;

/**
 * Consumer-owned canonical upgrade controls, separate from production normalization and lifecycle policy
 *
 * Switch real persisted cohort settings with writers fenced; restart must retain only durable state.
 * Old-binary exclusion and actual process/database qualification remain additional consumer obligations.
 */
interface AgentCanonicalUpgradeFixture
{
    /** @param list<int> $readers Retained historical-reader obligations of the qualified cohort */
    public function useCanonicalCohort(int $creationVersion, array $readers, int $generation): void;

    /** Expires operation retry/read delegation through the current authority writer */
    public function expireOperationDelegation(): void;

    /** Corrupts persisted version/binding for denial tests while preserving all other operation state */
    public function corruptBinding(AgentIssuance $issuance, int $version, ?string $binding = null): void;
}
