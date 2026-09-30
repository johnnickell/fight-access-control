<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Service\Conformance;

use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentIssuance;

/**
 * Consumer-owned persisted-state controls, separate from production normalization and lifecycle policy
 *
 * Restart must retain durable state; actual process/database qualification remains a consumer obligation.
 */
interface AgentCanonicalFixture
{
    /** Expires operation retry/read delegation through the current authority writer */
    public function expireOperationDelegation(): void;

    /** Corrupts persisted version/binding for denial tests while preserving all other operation state */
    public function corruptBinding(AgentIssuance $issuance, int $version, ?string $binding = null): void;
}
