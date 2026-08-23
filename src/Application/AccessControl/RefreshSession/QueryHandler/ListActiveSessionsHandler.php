<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\RefreshSession\QueryHandler;

use Fight\AccessControl\Application\AccessControl\RefreshSession\Service\SessionAdministrationAuthorization;
use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\Query\ListActiveSessions;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\Query\SessionView;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSession;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionRepository;
use Fight\Common\Application\Messaging\Query\QueryHandler;
use Fight\Common\Domain\Messaging\Query\QueryMessage;
use Fight\Common\Domain\Repository\ResultSet;

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
        private Clock $refreshSessionClock,
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
     */
    public function handle(QueryMessage $queryMessage): ResultSet
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
        $activeSessions = $this->refreshSessionRepository->getByUserId(
            $query->getUserId(),
            $now,
            $query->getPagination()
        );
        $views = $activeSessions->records()->map(
            fn(RefreshSession $refreshSession): SessionView => SessionView::fromSession(
                $refreshSession,
                $query->getCurrentSessionId()
            ),
            SessionView::class
        );

        return new ResultSet(
            $activeSessions->page(),
            $activeSessions->perPage(),
            $activeSessions->totalRecords(),
            $views
        );
    }
}
