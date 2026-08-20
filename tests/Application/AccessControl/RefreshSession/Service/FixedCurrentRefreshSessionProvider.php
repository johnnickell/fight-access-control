<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\RefreshSession\Service;

use Fight\AccessControl\Application\AccessControl\RefreshSession\Service\CurrentRefreshSessionProvider;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionId;

final readonly class FixedCurrentRefreshSessionProvider implements CurrentRefreshSessionProvider
{
    public function __construct(private RefreshSessionId $refreshSessionId)
    {
    }

    public function getCurrentRefreshSessionId(): RefreshSessionId
    {
        return $this->refreshSessionId;
    }
}
