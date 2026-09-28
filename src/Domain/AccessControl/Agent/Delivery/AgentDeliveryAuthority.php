<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent\Delivery;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentOperationRejectedException;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationFailure;

/**
 * Class AgentDeliveryAuthority
 *
 * Captures the consumer's fenced authorization epoch, including the actual worker identity and all policy revisions.
 */
final readonly class AgentDeliveryAuthority
{
    /**
     * Constructs AgentDeliveryAuthority
     */
    public function __construct(private string $epoch, private DateTimeImmutable $expiresAt)
    {
        if (preg_match('/\A[A-Za-z0-9_.:-]{1,256}\z/D', $epoch) !== 1) {
            throw new AgentOperationRejectedException(AgentOperationFailure::INVALID_REQUEST);
        }
    }

    /**
     * Returns safe persisted epoch evidence rather than an authorization capability
     */
    public function getEpoch(): string
    {
        return $this->epoch;
    }

    /**
     * Returns the earliest expiry of the authenticated worker and its current delegation
     */
    public function getExpiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }
}
