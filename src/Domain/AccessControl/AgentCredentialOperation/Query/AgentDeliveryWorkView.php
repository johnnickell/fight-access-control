<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Query;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentCredentialOperation;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Delivery\AgentDeliveryPolicy;
use SensitiveParameter;

/**
 * Class AgentDeliveryWorkView
 *
 * Carries secret-free selection evidence from one authoritative repository snapshot, not a live authority lease.
 * Handlers return only the operation View; no material, claim, receipt, key or decryption handle is retained here.
 */
final readonly class AgentDeliveryWorkView
{
    /**
     * Constructs AgentDeliveryWorkView
     */
    public function __construct(
        private AgentOperationView $operation,
        private bool $materialRetained,
        private ?DateTimeImmutable $dueAt,
        private DateTimeImmutable $retentionEnd,
        private bool $sinkCleaned,
        private ?int $currentDestinationWriteVersion
    ) {
    }

    /**
     * Creates selection evidence from an already loaded snapshot without retaining its sensitive state
     *
     * Real read repositories project these facts directly without loading material-bearing roots.
     */
    public static function fromOperation(
        #[SensitiveParameter] AgentCredentialOperation $operation,
        ?int $currentDestinationWriteVersion
    ): self {
        return new self(
            AgentOperationView::fromOperation($operation),
            $operation->getMaterial() !== null,
            $operation->getDeliveryDueAt(),
            ($operation->getDeliveryPolicy() ?? new AgentDeliveryPolicy())->retainUntil(
                $operation->getIssuance()->getIssuedAt()
            ),
            $operation->isSinkCleaned(),
            $currentDestinationWriteVersion
        );
    }

    /**
     * Returns the original safe operation snapshot
     */
    public function getOperation(): AgentOperationView
    {
        return $this->operation;
    }

    /**
     * Returns whether the snapshot retains a delivery copy without exposing that copy
     */
    public function hasMaterial(): bool
    {
        return $this->materialRetained;
    }

    /**
     * Returns the recorded next due time including retention-expired terminalization work
     */
    public function getDueAt(): ?DateTimeImmutable
    {
        return $this->dueAt;
    }

    /**
     * Returns the original pinned retention deadline independently of the requested cleanup grace
     */
    public function getRetentionEnd(): DateTimeImmutable
    {
        return $this->retentionEnd;
    }

    /**
     * Returns whether sink cleanup was already acknowledged
     */
    public function isSinkCleaned(): bool
    {
        return $this->sinkCleaned;
    }

    /**
     * Returns the authoritative stable-slot reservation across all scopes and binding revisions
     */
    public function getCurrentDestinationWriteVersion(): ?int
    {
        return $this->currentDestinationWriteVersion;
    }
}
