<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Credential\Conformance;

use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentCredentialOperation;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationContract;
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
            $env->operationRepository->operations,
            $env->operationRepository->versions,
            $env->operationRepository->closedKeyVersions,
            $env->auditEvidenceRepository->all(),
            $env->operationRepository->contract->current,
            $env->operationRepository->contract->generation
        );
    }

    public function restore(ProvisioningEnvironment $env): void
    {
        $env->agentRepository->restoreSnapshot($this->agents);
        $env->operationRepository->operations = $this->operations;
        $env->operationRepository->versions = $this->versions;
        $env->operationRepository->closedKeyVersions = $this->closedKeys;
        $env->auditEvidenceRepository->restoreSnapshot($this->audit);
        $env->operationRepository->contract->current = $this->contract;
        $env->operationRepository->contract->generation = $this->generation;
    }
}
