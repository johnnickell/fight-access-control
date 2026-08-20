<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\RefreshSession\QueryHandler;

use Fight\AccessControl\Domain\AccessControl\RefreshSession\Query\AuthenticatedSessionView;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\Query\RestoreAuthenticatedSession;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSession;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionRepository;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserRepository;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Application\Messaging\Query\QueryHandler;
use Fight\Common\Domain\Messaging\Query\QueryMessage;

/**
 * Revalidates authoritative session and identity state for cold authentication restoration.
 */
final readonly class RestoreAuthenticatedSessionHandler implements QueryHandler
{
    /**
     * Creates the authenticated-session restoration handler.
     */
    public function __construct(
        private RefreshSessionRepository $refreshSessionRepository,
        private UserRepository $userRepository
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function queryRegistration(): string
    {
        return RestoreAuthenticatedSession::class;
    }

    /**
     * @inheritDoc
     */
    public function handle(QueryMessage $queryMessage): ?AuthenticatedSessionView
    {
        /** @var RestoreAuthenticatedSession $query */
        $query = $queryMessage->payload();
        $refreshSession = $this->refreshSessionRepository->getById($query->getRefreshSessionId());

        if (!$refreshSession instanceof RefreshSession || $refreshSession->isRevoked()) {
            return null;
        }

        $user = $this->userRepository->getById($refreshSession->getUserId());
        if (
            !$user instanceof User
            || !$user->getId()->equals($refreshSession->getUserId())
            || $user->getState() !== UserState::ACTIVE
            || $user->getAuthenticationVersion() !== $refreshSession->getAuthenticationVersion()
        ) {
            return null;
        }

        return AuthenticatedSessionView::fromRefreshSession($refreshSession);
    }
}
