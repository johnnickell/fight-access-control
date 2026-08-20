<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\User\Security;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshCredential;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;

/**
 * Returns the safe authentication material consumers need to establish browser authentication.
 */
final readonly class TokenSet
{
    /**
     * Constructs an authentication token result.
     */
    public function __construct(
        private UserId $userId,
        private RefreshSessionId $refreshSessionId,
        private RefreshCredential $refreshCredential,
        private DateTimeImmutable $refreshExpiresAt,
        private bool $remembered,
        private AccessToken $accessToken,
        private DateTimeImmutable $accessTokenExpiresAt
    ) {
    }

    /**
     * Returns the authenticated identity.
     */
    public function getUserId(): UserId
    {
        return $this->userId;
    }

    /**
     * Returns the authoritative refresh-session identifier.
     */
    public function getRefreshSessionId(): RefreshSessionId
    {
        return $this->refreshSessionId;
    }

    /**
     * Returns the opaque refresh credential for immediate transport.
     */
    public function getRefreshCredential(): RefreshCredential
    {
        return $this->refreshCredential;
    }

    /**
     * Returns the absolute refresh-session deadline.
     */
    public function getRefreshExpiresAt(): DateTimeImmutable
    {
        return $this->refreshExpiresAt;
    }

    /**
     * Returns whether browser-restart persistence was requested.
     */
    public function isRemembered(): bool
    {
        return $this->remembered;
    }

    /**
     * Returns the encoded access JWT.
     */
    public function getAccessToken(): AccessToken
    {
        return $this->accessToken;
    }

    /**
     * Returns the access-JWT expiration time.
     */
    public function getAccessTokenExpiresAt(): DateTimeImmutable
    {
        return $this->accessTokenExpiresAt;
    }
}
