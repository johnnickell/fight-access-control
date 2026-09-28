<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent\Delivery;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentOperationRejectedException;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationFailure;

/**
 * Class AgentDeliveryPolicy
 *
 * Bounds one original delivery independently of new-operation capacity.
 */
final readonly class AgentDeliveryPolicy
{
    /**
     * Constructs AgentDeliveryPolicy
     */
    public function __construct(
        private int $leaseSeconds = 60,
        private int $admissionSeconds = 15,
        private int $retrySeconds = 30,
        private int $retentionSeconds = 86400,
        private int $maximumAttempts = 100
    ) {
        if (
            $leaseSeconds < 1 || $leaseSeconds > 3600
            || $admissionSeconds < 1 || $admissionSeconds > $leaseSeconds
            || $retrySeconds < 1 || $retrySeconds > $retentionSeconds
            || $retentionSeconds < $leaseSeconds || $retentionSeconds > 604800
            || $maximumAttempts < 1 || $maximumAttempts > 1000
        ) {
            throw new AgentOperationRejectedException(AgentOperationFailure::INVALID_REQUEST);
        }
    }

    /**
     * Returns finite policy settings for persistence alongside the first claim
     *
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            'lease_seconds'     => $this->leaseSeconds,
            'admission_seconds' => $this->admissionSeconds,
            'retry_seconds'     => $this->retrySeconds,
            'retention_seconds' => $this->retentionSeconds,
            'maximum_attempts'  => $this->maximumAttempts
        ];
    }

    /**
     * Returns the bounded claim lease
     */
    public function leaseUntil(DateTimeImmutable $now): DateTimeImmutable
    {
        return $now->modify('+'.$this->leaseSeconds.' seconds');
    }

    /**
     * Returns the local admission deadline before authority and retention caps
     */
    public function admitUntil(DateTimeImmutable $now): DateTimeImmutable
    {
        return $now->modify('+'.$this->admissionSeconds.' seconds');
    }

    /**
     * Returns the next bounded retry time
     */
    public function retryAt(DateTimeImmutable $now): DateTimeImmutable
    {
        return $now->modify('+'.$this->retrySeconds.' seconds');
    }

    /**
     * Returns the immutable lifetime limit measured from original issuance
     */
    public function retainUntil(DateTimeImmutable $issuedAt): DateTimeImmutable
    {
        return $issuedAt->modify('+'.$this->retentionSeconds.' seconds');
    }

    /**
     * Returns whether another attempt is permitted
     */
    public function permitsAttempt(int $attempt): bool
    {
        return $attempt <= $this->maximumAttempts;
    }
}
