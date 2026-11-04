<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Credential\Conformance;

use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentCredentialOperation;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationContract;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Credential\ProvisioningEnvironment;

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
            $env->agentRepository->all(),
            $env->agentOperationRepository->operations,
            $env->agentOperationRepository->versions,
            $env->agentOperationRepository->closedKeyVersions,
            $env->auditEvidenceRepository->all(),
            $env->agentOperationRepository->contract->current,
            $env->agentOperationRepository->contract->generation
        );
    }

    public function restore(ProvisioningEnvironment $env): void
    {
        $env->agentRepository->restoreSnapshot($this->agents);
        $env->agentOperationRepository->operations = $this->operations;
        $env->agentOperationRepository->versions = $this->versions;
        $env->agentOperationRepository->closedKeyVersions = $this->closedKeys;
        $env->auditEvidenceRepository->restoreSnapshot($this->audit);
        $env->agentOperationRepository->contract->current = $this->contract;
        $env->agentOperationRepository->contract->generation = $this->generation;
    }
}
