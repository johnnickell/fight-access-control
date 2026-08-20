<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\RefreshSession\Service;

use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionId;

/**
 * Provides the refresh session authenticated by consumer composition.
 */
interface CurrentRefreshSessionProvider
{
    /**
     * Returns the authenticated caller's current refresh session identifier.
     */
    public function getCurrentRefreshSessionId(): RefreshSessionId;
}
