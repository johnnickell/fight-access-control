<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Authorization\Service;

use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use InvalidArgumentException;

/**
 * Carries transport-neutral authentication authority for one request.
 */
final readonly class AuthenticationContext
{
    /**
     * Creates request authentication claims without authorization data.
     */
    public function __construct(
        private UserId $userId,
        private RefreshSessionId $refreshSessionId,
        private int $authenticationVersion
    ) {
        if ($authenticationVersion < 1) {
            throw new InvalidArgumentException('The authentication version must be positive.');
        }
    }

    /**
     * Returns the claimed authentication version.
     */
    public function getAuthenticationVersion(): int
    {
        return $this->authenticationVersion;
    }

    /**
     * Returns the claimed refresh-session identity.
     */
    public function getRefreshSessionId(): RefreshSessionId
    {
        return $this->refreshSessionId;
    }

    /**
     * Returns the claimed user identity.
     */
    public function getUserId(): UserId
    {
        return $this->userId;
    }
}
