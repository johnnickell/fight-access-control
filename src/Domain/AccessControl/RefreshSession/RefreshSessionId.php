<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\RefreshSession;

use Fight\Common\Domain\Identity\UniqueId;

/**
 * Represents a stable refresh-session identifier.
 */
final readonly class RefreshSessionId extends UniqueId
{
}
