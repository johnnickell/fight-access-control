<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\RefreshSession\QueryHandler;

use Fight\AccessControl\Application\AccessControl\RefreshSession\Service\SessionAdministrationAuthorization;
use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\Query\ListActiveSessions;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\Query\SessionView;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionRepository;
use Fight\Common\Application\Messaging\Query\QueryHandler;
use Fight\Common\Domain\Collection\ArrayList;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Query\QueryMessage;
use Fight\Common\Domain\Repository\ResultSet;

/**
 * Class ListActiveSessionsHandler
 *
 * Retrieves safe active-session views for a user.
 */
final readonly class ListActiveSessionsHandler implements QueryHandler
{
    /**
     * Constructs ListActiveSessionsHandler
     *
     * Creates the active-session query handler.
     */
    public function __construct(
        private RefreshSessionRepository $refreshSessionRepository,
        private Clock $clock,
        private SessionAdministrationAuthorization $sessionAdministrationAuthorization
    ) {
    }

    /** @inheritDoc */
    public static function queryRegistration(): string
    {
        return ListActiveSessions::class;
    }

    /**
     * Returns safe active-session views
     *
     * @return ResultSet<SessionView>
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

        $now = $this->clock->now();
        $activeSessions = $this->refreshSessionRepository->getByUserId(
            $query->getUserId(),
            $now,
            $query->getPagination()
        );
        $activeSessions = ResultSet::strict(
            $activeSessions->page(),
            $activeSessions->perPage(),
            $activeSessions->totalRecords(),
            $activeSessions->records()
        );
        if (
            $activeSessions->page() !== $query->getPagination()->page()
            || $activeSessions->perPage() !== $query->getPagination()->perPage()
        ) {
            throw new DomainException('The session page does not match the requested pagination.');
        }

        $views = ArrayList::of(SessionView::class);
        foreach ($activeSessions->records()->toArray() as $refreshSession) {
            if (
                !$refreshSession->getUserId()->equals($query->getUserId())
                || !$refreshSession->isUsableAt($now)
            ) {
                throw new DomainException('The session page contains an unrelated or unusable session.');
            }

            $views->add(SessionView::fromSession($refreshSession, $query->getCurrentSessionId()));
        }

        return ResultSet::strict(
            $activeSessions->page(),
            $activeSessions->perPage(),
            $activeSessions->totalRecords(),
            $views
        );
    }
}
