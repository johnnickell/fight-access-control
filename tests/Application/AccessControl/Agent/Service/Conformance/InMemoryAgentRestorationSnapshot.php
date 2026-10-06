<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Service\Conformance;

use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentCredentialOperation;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationContract;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\ProvisioningEnvironment;

/** In-memory persisted checkpoint only; excludes sink, trusted admission, clock, counters and request services */
final readonly class InMemoryAgentRestorationSnapshot
{
    /**
     * @param list<Agent> $agents
     * @param array<string, AgentCredentialOperation> $operations
     * @param array<string, int> $versions
     * @param array<string, bool> $closedKeys
     * @param list<AuditEvidence> $audit
     */
    public function __construct(
        public array $agents,
        public array $operations,
        public array $versions,
        public array $closedKeys,
        public array $audit,
        public ?AgentOperationContract $contract,
        public int $generation
    ) {
    }

    public static function capture(ProvisioningEnvironment $env): self
    {
        return new self(
            $env->agents->all(),
            $env->operations->operations,
            $env->operations->versions,
            $env->operations->closedKeyVersions,
            $env->audit->all(),
            $env->operations->contract->current,
            $env->operations->contract->generation
        );
    }

    public function restore(ProvisioningEnvironment $env): void
    {
        $env->agents->restoreSnapshot($this->agents);
        $env->operations->operations = $this->operations;
        $env->operations->versions = $this->versions;
        $env->operations->closedKeyVersions = $this->closedKeys;
        $env->audit->restoreSnapshot($this->audit);
        $env->operations->contract->current = $this->contract;
        $env->operations->contract->generation = $this->generation;
    }
}
