<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent\Maintenance;

use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentOperationRejectedException;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationFailure;
use SensitiveParameter;

/**
 * Class AgentDeliveryKeyVersion
 *
 * Identifies a wrapping version without exposing a provider path or key handle.
 */
final readonly class AgentDeliveryKeyVersion
{
    /**
     * Constructs AgentDeliveryKeyVersion
     */
    public function __construct(#[SensitiveParameter] private string $value)
    {
        if (preg_match('/\A[A-Za-z0-9_.-]{1,64}\z/D', $value) !== 1) {
            throw new AgentOperationRejectedException(AgentOperationFailure::INVALID_REQUEST);
        }
    }

    /**
     * Returns the opaque version
     */
    public function toString(): string
    {
        return $this->value;
    }
}
