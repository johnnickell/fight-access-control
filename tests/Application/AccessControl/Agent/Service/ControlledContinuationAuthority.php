<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Service;

use Closure;
use Fight\AccessControl\Application\AccessControl\Agent\Service\AgentContinuationFence;
use Fight\AccessControl\Application\AccessControl\Agent\Service\AgentContinuationScope;
use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\AuthenticatedAgentPrincipal;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationContract;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/**
 * Controlled shared-reader/writer schedule, not a production persistence or distributed lock implementation.
 */
final class ControlledContinuationAuthority implements AgentContinuationFence, AgentContinuationScope
{
    public AgentOperationContract $contract;

    public bool $held = false;

    public bool $scopeAllowed = true;

    public bool $supported = true;

    public int $acquisitions = 0;

    public int $releases = 0;

    public int $scopeChecks = 0;

    public ?Closure $beforeAcquire = null;

    public ?Closure $afterAgentRead = null;

    public ?Closure $afterPermissionRead = null;

    public ?Closure $afterScopeRead = null;

    public ?Throwable $agentFailure = null;

    public ?Throwable $permissionFailure = null;

    public ?Throwable $scopeFailure = null;

    /** @phpstan-param list<Permission> $definitions */
    public function __construct(public ?Agent $agent, public array $definitions)
    {
        $this->contract = new AgentOperationContract(1, 2, 1, 1, AgentOperationContract::REQUIRED_CAPABILITIES, 1);
    }

    public function synchronized(
        AgentId $agentId,
        #[SensitiveParameter] AgentContinuationScope $scope,
        #[SensitiveParameter] Closure $admit
    ): void {
        $this->beforeAcquire?->__invoke();
        if (!$this->supported || $this->held || $scope !== $this) {
            throw new RuntimeException('Private synchronization unavailable.');
        }

        $this->held = true;
        ++$this->acquisitions;
        try {
            $admit();
        } finally {
            $this->held = false;
            ++$this->releases;
        }
    }

    public function readContract(): AgentOperationContract
    {
        $this->assertHeld();

        return $this->contract;
    }

    public function readAgent(): ?Agent
    {
        $this->assertHeld();
        if ($this->agentFailure !== null) {
            throw $this->agentFailure;
        }

        $agent = $this->agent;
        $this->afterAgentRead?->__invoke();

        return $agent;
    }

    /**
     * @phpstan-param list<PermissionId> $ids
     * @return list<Permission>
     */
    public function readPermissions(array $ids): array
    {
        $this->assertHeld();
        if ($this->permissionFailure !== null) {
            throw $this->permissionFailure;
        }

        $definitions = $this->definitions;
        $this->afterPermissionRead?->__invoke();

        return $definitions;
    }

    public function isAllowed(#[SensitiveParameter] AuthenticatedAgentPrincipal $principal): bool
    {
        $this->assertHeld();
        ++$this->scopeChecks;
        if ($this->scopeFailure !== null) {
            throw $this->scopeFailure;
        }

        $allowed = $this->scopeAllowed;
        $this->afterScopeRead?->__invoke();

        return $allowed;
    }

    /** @phpstan-param Closure(): void $write */
    public function write(Closure $write): void
    {
        if ($this->held) {
            throw new RuntimeException('Controlled writer must wait for the shared fence.');
        }

        $write();
    }

    private function assertHeld(): void
    {
        if (!$this->held) {
            throw new RuntimeException('Authoritative reads require the shared fence.');
        }
    }
}
