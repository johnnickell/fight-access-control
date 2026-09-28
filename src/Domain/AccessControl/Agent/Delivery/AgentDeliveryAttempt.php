<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent\Delivery;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentOperationRejectedException;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationFailure;

/**
 * Class AgentDeliveryAttempt
 *
 * Owns one leased claim and its separately committed admission evidence.
 */
final readonly class AgentDeliveryAttempt
{
    /**
     * Constructs AgentDeliveryAttempt
     */
    public function __construct(
        private int $fence,
        private AgentDeliveryClaimId $claimId,
        private DateTimeImmutable $leaseUntil,
        private ?AgentDeliveryAuthority $authority = null,
        private ?DateTimeImmutable $deadline = null
    ) {
        if (
            $fence < 1 || ($authority === null) !== ($deadline === null)
            || ($deadline !== null && ($deadline > $leaseUntil || $deadline > $authority?->getExpiresAt()))
        ) {
            throw new AgentOperationRejectedException(AgentOperationFailure::INVALID_REQUEST);
        }
    }

    /**
     * Returns the monotonic attempt fence retained through retry and retirement
     */
    public function getFence(): int
    {
        return $this->fence;
    }

    /**
     * Returns the opaque claim identity which is never a sink idempotency key
     */
    public function getClaimId(): AgentDeliveryClaimId
    {
        return $this->claimId;
    }

    /**
     * Returns the persisted lease deadline
     */
    public function getLeaseUntil(): DateTimeImmutable
    {
        return $this->leaseUntil;
    }

    /**
     * Returns persisted authorizing epochs for expected-state acknowledgement
     */
    public function getAuthority(): ?AgentDeliveryAuthority
    {
        return $this->authority;
    }

    /**
     * Returns the earliest admitted deadline or no materialization authority
     */
    public function getDeadline(): ?DateTimeImmutable
    {
        return $this->deadline;
    }

    /**
     * Creates admission capped by claim, policy, retention and current authorization expiry
     */
    public function admit(
        AgentDeliveryAuthority $authority,
        AgentDeliveryPolicy $policy,
        DateTimeImmutable $retainUntil,
        DateTimeImmutable $now
    ): self {
        $deadline = min($this->leaseUntil, $authority->getExpiresAt(), $policy->admitUntil($now), $retainUntil);
        if ($this->authority !== null || $now >= $deadline) {
            throw new AgentOperationRejectedException(AgentOperationFailure::CONFLICT);
        }

        return new self($this->fence, $this->claimId, $this->leaseUntil, $authority, $deadline);
    }

    /**
     * Validates that materialization may still begin after a confirmed admission commit
     */
    public function assertAdmittedAt(DateTimeImmutable $now): void
    {
        if ($this->deadline === null || $now >= $this->deadline) {
            throw new AgentOperationRejectedException(AgentOperationFailure::CONFLICT);
        }
    }

    /**
     * Validates exact claim and admission identity without accepting renewed authority after an ABA change
     */
    public function assertCurrent(self $expected, AgentDeliveryAuthority $authority, DateTimeImmutable $now): void
    {
        $this->assertAdmittedAt($now);
        if (
            $this->fence !== $expected->fence
            || !$this->claimId->equals($expected->claimId)
            || $this->leaseUntil != $expected->leaseUntil
            || $this->deadline != $expected->deadline
            || $this->authority != $expected->authority
            || $this->authority?->getEpoch() !== $authority->getEpoch()
            || $now >= $authority->getExpiresAt()
        ) {
            throw new AgentOperationRejectedException(AgentOperationFailure::CONFLICT);
        }
    }
}
