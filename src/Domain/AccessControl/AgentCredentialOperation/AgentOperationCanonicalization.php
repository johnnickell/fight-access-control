<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation;

use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Exception\AgentOperationRejectedException;

/**
 * Class AgentOperationCanonicalization
 *
 * Owns the single supported operation request marker.
 */
final class AgentOperationCanonicalization
{
    public const int VERSION = 2;

    /**
     * Validates the persisted contract marker without migration or fallback
     */
    public static function assertSupported(int $version): void
    {
        if ($version !== self::VERSION) {
            throw new AgentOperationRejectedException(AgentOperationFailure::UNSUPPORTED_VERSION);
        }
    }
}
