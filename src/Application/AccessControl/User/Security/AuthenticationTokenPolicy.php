<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\User\Security;

use DateInterval;
use DateTimeImmutable;

/**
 * Defines tested access and refresh lifetime policy without making it a Domain constant.
 */
final readonly class AuthenticationTokenPolicy
{
    /**
     * Constructs a configured authentication-token policy.
     */
    public function __construct(
        private DateInterval $accessLifetime,
        private DateInterval $ordinaryIdleLifetime,
        private DateInterval $ordinaryAbsoluteLifetime,
        private DateInterval $rememberedIdleLifetime,
        private DateInterval $rememberedAbsoluteLifetime,
        private DateInterval $refreshConflictWindow
    ) {
    }

    /**
     * Creates the certified starter lifetime policy.
     */
    public static function starterDefaults(DateInterval $refreshConflictWindow): self
    {
        return new self(
            new DateInterval('PT15M'),
            new DateInterval('P1D'),
            new DateInterval('P2D'),
            new DateInterval('P15D'),
            new DateInterval('P30D'),
            $refreshConflictWindow
        );
    }

    /**
     * Returns the access-token deadline for one issuance.
     */
    public function accessExpiresAt(DateTimeImmutable $issuedAt): DateTimeImmutable
    {
        return $issuedAt->add($this->accessLifetime);
    }

    /**
     * Returns the refresh-session idle deadline.
     */
    public function refreshIdleExpiresAt(DateTimeImmutable $issuedAt, bool $remembered): DateTimeImmutable
    {
        return $issuedAt->add($remembered ? $this->rememberedIdleLifetime : $this->ordinaryIdleLifetime);
    }

    /**
     * Returns the refresh-session absolute deadline.
     */
    public function refreshAbsoluteExpiresAt(DateTimeImmutable $issuedAt, bool $remembered): DateTimeImmutable
    {
        return $issuedAt->add($remembered ? $this->rememberedAbsoluteLifetime : $this->ordinaryAbsoluteLifetime);
    }

    /**
     * Returns the explicitly configured bounded refresh-conflict interval.
     */
    public function refreshConflictWindow(): DateInterval
    {
        return $this->refreshConflictWindow;
    }
}
