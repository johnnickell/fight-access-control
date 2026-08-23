<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\RefreshSession\Service;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\Exception\RefreshSessionNotFoundException;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSession;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionRepository;
use Fight\AccessControl\Domain\AccessControl\User\UserId;

/**
 * Revokes one or all authoritative refresh sessions through their repository contract.
 */
final readonly class SessionRevocationService
{
    private const int REVOCATION_RETRY_LIMIT = 3;

    /**
     * Creates the session-revocation service.
     */
    public function __construct(private RefreshSessionRepository $refreshSessionRepository)
    {
    }

    /**
     * Replaces the latest authoritative session state with an immutable revocation.
     */
    public function revoke(RefreshSession $refreshSession): RefreshSession
    {
        $attempts = 0;
        while (!$refreshSession->isRevoked() && $attempts < self::REVOCATION_RETRY_LIMIT) {
            ++$attempts;
            $revokedSession = $refreshSession->revoke();
            if ($this->refreshSessionRepository->replace($refreshSession, $revokedSession)) {
                return $revokedSession;
            }

            $refreshSession = $this->refreshSessionRepository->getById($refreshSession->getId());
            if (!$refreshSession instanceof RefreshSession) {
                throw new RefreshSessionNotFoundException('The refresh session is not authoritative.');
            }
        }

        if ($refreshSession->isRevoked()) {
            return $refreshSession;
        }

        throw new RefreshSessionNotFoundException('The refresh session is not authoritative.');
    }

    /**
     * Revokes every currently usable refresh session owned by a user.
     */
    public function revokeAllActiveFor(UserId $userId, DateTimeImmutable $at): void
    {
        foreach ($this->refreshSessionRepository->getAllActiveByUserId($userId, $at) as $refreshSession) {
            $this->revoke($refreshSession);
        }
    }
}
