<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent\Delivery;

use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentDeliveryFailedException;
use SensitiveParameter;

/**
 * Class AgentDeliveryReceipt
 *
 * Contains an opaque durable sink acknowledgement, never a digest, secret-read capability or provider path.
 */
final readonly class AgentDeliveryReceipt
{
    /**
     * Constructs AgentDeliveryReceipt
     */
    public function __construct(#[SensitiveParameter] private string $id)
    {
        if (preg_match('/\A[A-Za-z0-9_-]{16,128}\z/D', $id) !== 1) {
            throw new AgentDeliveryFailedException(AgentDeliveryFailure::INVALID_RECEIPT);
        }
    }

    /**
     * Returns the opaque identifier for protected receipt verification
     */
    public function toString(): string
    {
        return $this->id;
    }
}
