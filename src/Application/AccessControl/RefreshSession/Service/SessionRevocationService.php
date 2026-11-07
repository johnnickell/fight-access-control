<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\RefreshSession\Service;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSession;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionRepository;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Application\Error\Exception\StateConflict;
use LogicException;

/**
 * Class SessionRevocationService
 *
 * Revokes one or all authoritative refresh sessions through their repository contract.
 */
final readonly class SessionRevocationService
{
    private const int REVOCATION_RETRY_LIMIT = 3;

    /**
     * Constructs SessionRevocationService
     *
     * Creates the session-revocation service.
     */
    public function __construct(private RefreshSessionRepository $refreshSessionRepository)
    {
    }

    /**
     * Validates immutable session correlation and monotonic revision on an authoritative retry
     */
    public static function assertSameFamily(RefreshSession $expected, RefreshSession $current): void
    {
        if (
            !$current->getId()->equals($expected->getId())
            || !$current->getUserId()->equals($expected->getUserId())
            || $current->getAuthenticationVersion() !== $expected->getAuthenticationVersion()
            || $current->getCreatedAt() != $expected->getCreatedAt()
            || $current->getAbsoluteExpiresAt() != $expected->getAbsoluteExpiresAt()
            || $current->isRemembered() !== $expected->isRemembered()
            || $current->getRevision() < $expected->getRevision()
        ) {
            throw new LogicException('The session retry returned unrelated or regressed authority.');
        }
    }

    /**
     * Replaces the latest authoritative session state with an immutable revocation
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

            $currentSession = $this->refreshSessionRepository->getById($refreshSession->getId());
            if (!$currentSession instanceof RefreshSession) {
                throw new StateConflict('The refresh session lost authority during revocation.');
            }

            self::assertSameFamily($refreshSession, $currentSession);
            $refreshSession = $currentSession;
        }

        if ($refreshSession->isRevoked()) {
            return $refreshSession;
        }

        throw new StateConflict('The refresh session changed during bounded revocation.');
    }

    /**
     * Revokes every currently usable refresh session owned by a user
     */
    public function revokeAllActiveFor(UserId $userId, DateTimeImmutable $at): void
    {
        // Native array does not enforce the adapter's promised list or member types.
        /** @var array<array-key, mixed> $refreshSessions */
        $refreshSessions = $this->refreshSessionRepository->getAllActiveByUserId($userId, $at);
        if (!array_is_list($refreshSessions)) {
            throw new LogicException('The active-session lookup must return a list.');
        }

        $seen = [];
        foreach ($refreshSessions as $refreshSession) {
            if (
                !$refreshSession instanceof RefreshSession
                || !$refreshSession->getUserId()->equals($userId)
                || !$refreshSession->isUsableAt($at)
                || isset($seen[$refreshSession->getId()->toString()])
            ) {
                throw new LogicException('The active-session lookup returned an invalid member.');
            }

            $seen[$refreshSession->getId()->toString()] = true;
        }

        foreach ($refreshSessions as $refreshSession) {
            $this->revoke($refreshSession);
        }
    }
}
