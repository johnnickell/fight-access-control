<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\RefreshSession\QueryHandler;

use Fight\AccessControl\Application\AccessControl\RefreshSession\Service\RefreshSessionClock;
use Fight\AccessControl\Application\AccessControl\RefreshSession\Service\SessionAdministrationAuthorization;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\Query\ListActiveSessions;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\Query\SessionView;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSession;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionRepository;
use Fight\Common\Application\Messaging\Query\QueryHandler;
use Fight\Common\Domain\Messaging\Query\QueryMessage;

/**
 * Retrieves safe active-session views for a user.
 */
final readonly class ListActiveSessionsHandler implements QueryHandler
{
    /**
     * Creates the active-session query handler.
     */
    public function __construct(
        private RefreshSessionRepository $refreshSessionRepository,
        private RefreshSessionClock $refreshSessionClock,
        private SessionAdministrationAuthorization $sessionAdministrationAuthorization
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function queryRegistration(): string
    {
        return ListActiveSessions::class;
    }

    /**
     * @inheritDoc
     *
     * @return list<SessionView>
     */
    public function handle(QueryMessage $queryMessage): array
    {
        /** @var ListActiveSessions $query */
        $query = $queryMessage->payload();

        if (!$query->getActorId()->equals($query->getUserId())) {
            $this->sessionAdministrationAuthorization->assertCanManageSessions(
                $query->getActorId(),
                $query->getUserId()
            );
        }

        $now = $this->refreshSessionClock->now();
        $activeSessions = array_filter(
            $this->refreshSessionRepository->getByUserId($query->getUserId()),
            static fn(RefreshSession $refreshSession): bool => $refreshSession->isUsableAt($now)
        );

        return array_values(array_map(
            fn(RefreshSession $refreshSession): SessionView => SessionView::fromSession(
                $refreshSession,
                $query->getCurrentSessionId()
            ),
            $activeSessions
        ));
    }
}
