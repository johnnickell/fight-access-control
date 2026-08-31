<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\RefreshSession\QueryHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\RefreshSession\QueryHandler\ListActiveSessionsHandler;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\Exception\SessionAdministrationAuthorizationException;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\Query\ListActiveSessions;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\Query\SessionView;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshCredential;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSession;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Query\QueryMessage;
use Fight\Common\Domain\Repository\Pagination;
use Fight\Common\Domain\Repository\ResultSet;
use Fight\Common\Domain\Type\Arrayable;
use Fight\Test\AccessControl\Application\AccessControl\RefreshSession\Repository\InMemoryRefreshSessionRepository;
use Fight\Test\AccessControl\Application\AccessControl\RefreshSession\Service\FixedSessionAdministrationAuthorization;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

#[CoversClass(ListActiveSessionsHandler::class)]
#[CoversClass(ListActiveSessions::class)]
#[CoversClass(SessionView::class)]
final class ListActiveSessionsHandlerTest extends TestCase
{
    public function test_that_it_returns_only_the_users_currently_usable_sessions_as_safe_views(): void
    {
        $userId = UserId::fromString('018f0000-0000-7000-8000-000000000001');
        $currentSessionId = RefreshSessionId::fromString('018f0000-0000-7000-8000-000000000002');
        $otherSessionId = RefreshSessionId::fromString('018f0000-0000-7000-8000-000000000003');
        $repository = new InMemoryRefreshSessionRepository();
        $repository->add($this->session(
            $currentSessionId,
            $userId,
            '2026-08-19T08:00:00+00:00',
            '2026-08-21T08:00:00+00:00',
            '2026-08-22T08:00:00+00:00',
            false
        ));
        $repository->add($this->session(
            $otherSessionId,
            $userId,
            '2026-08-18T07:00:00+00:00',
            '2026-08-21T07:00:00+00:00',
            '2026-09-18T07:00:00+00:00',
            true
        ));
        $repository->add($this->session(
            RefreshSessionId::fromString('018f0000-0000-7000-8000-000000000004'),
            $userId,
            '2026-08-17T06:00:00+00:00',
            '2026-08-20T11:59:59+00:00',
            '2026-08-22T06:00:00+00:00',
            false
        ));
        $repository->add($this->session(
            RefreshSessionId::fromString('018f0000-0000-7000-8000-000000000005'),
            $userId,
            '2026-08-17T05:00:00+00:00',
            '2026-08-21T05:00:00+00:00',
            '2026-08-22T05:00:00+00:00',
            false
        )->revoke());
        $repository->add($this->session(
            RefreshSessionId::fromString('018f0000-0000-7000-8000-000000000006'),
            UserId::fromString('018f0000-0000-7000-8000-000000000007'),
            '2026-08-19T04:00:00+00:00',
            '2026-08-21T04:00:00+00:00',
            '2026-08-22T04:00:00+00:00',
            false
        ));
        $handler = new ListActiveSessionsHandler(
            $repository,
            new FixedClock(new DateTimeImmutable('2026-08-20T12:00:00+00:00')),
            $authorization = new FixedSessionAdministrationAuthorization(true)
        );

        self::assertSame(ListActiveSessions::class, ListActiveSessionsHandler::queryRegistration());
        $resultSet = $handler->handle(QueryMessage::create(new ListActiveSessions(
            $userId,
            $userId,
            $currentSessionId,
            new Pagination(1, 25, ['created_at' => Pagination::DESC])
        )));
        $views = $resultSet->records();

        self::assertInstanceOf(ResultSet::class, $resultSet);
        self::assertSame(1, $resultSet->page());
        self::assertSame(25, $resultSet->perPage());
        self::assertSame(2, $resultSet->totalRecords());
        self::assertCount(2, $views);
        self::assertSame($currentSessionId, $views[0]->getSessionId());
        self::assertSame($userId, $views[0]->getUserId());
        self::assertSame('2026-08-19T08:00:00+00:00', $views[0]->getCreatedAt()->format(DATE_ATOM));
        self::assertSame('2026-08-19T08:00:00+00:00', $views[0]->getLastActivityAt()->format(DATE_ATOM));
        self::assertSame('2026-08-21T08:00:00+00:00', $views[0]->getIdleExpiresAt()->format(DATE_ATOM));
        self::assertSame('2026-08-22T08:00:00+00:00', $views[0]->getAbsoluteExpiresAt()->format(DATE_ATOM));
        self::assertFalse($views[0]->isRemembered());
        self::assertTrue($views[0]->isCurrent());
        self::assertSame($otherSessionId, $views[1]->getSessionId());
        self::assertTrue($views[1]->isRemembered());
        self::assertFalse($views[1]->isCurrent());
        self::assertInstanceOf(Arrayable::class, $views[0]);
        self::assertSame(
            [
                'session_id' => '018f0000-0000-7000-8000-000000000002',
                'user_id' => '018f0000-0000-7000-8000-000000000001',
                'created_at' => '2026-08-19T08:00:00+00:00',
                'last_activity_at' => '2026-08-19T08:00:00+00:00',
                'idle_expires_at' => '2026-08-21T08:00:00+00:00',
                'absolute_expires_at' => '2026-08-22T08:00:00+00:00',
                'remembered' => false,
                'current' => true,
            ],
            $views[0]->toArray()
        );
        self::assertSame(0, $authorization->calls());
        self::assertSame(
            [
                'sessionId',
                'userId',
                'createdAt',
                'lastActivityAt',
                'idleExpiresAt',
                'absoluteExpiresAt',
                'remembered',
                'current',
            ],
            array_map(
                static fn(ReflectionProperty $property): string => $property->getName(),
                new ReflectionClass(SessionView::class)->getProperties()
            )
        );
    }

    public function test_that_the_query_round_trips_and_rejects_missing_required_data(): void
    {
        $query = new ListActiveSessions(
            UserId::fromString('018f0000-0000-7000-8000-000000000008'),
            UserId::fromString('018f0000-0000-7000-8000-000000000001'),
            RefreshSessionId::fromString('018f0000-0000-7000-8000-000000000002'),
            new Pagination(2, 25, ['created_at' => Pagination::DESC])
        );

        self::assertEquals($query, ListActiveSessions::fromArray($query->toArray()));
        self::assertSame(2, $query->getPagination()->page());
        self::assertSame(25, $query->getPagination()->perPage());
        self::assertSame(['created_at' => Pagination::DESC], $query->getPagination()->orderings());

        $rejectedPayloads = 0;
        foreach (array_keys($query->toArray()) as $requiredKey) {
            $incompleteData = $query->toArray();
            unset($incompleteData[$requiredKey]);

            try {
                ListActiveSessions::fromArray($incompleteData);
                self::fail('Incomplete query data must be rejected.');
            } catch (DomainException) {
                ++$rejectedPayloads;
            }
        }

        self::assertSame(6, $rejectedPayloads);
    }

    public function test_that_an_authorized_administrator_can_inspect_another_users_sessions(): void
    {
        $actorId = UserId::fromString('018f0000-0000-7000-8000-000000000008');
        $userId = UserId::fromString('018f0000-0000-7000-8000-000000000001');
        $sessionId = RefreshSessionId::fromString('018f0000-0000-7000-8000-000000000002');
        $repository = new InMemoryRefreshSessionRepository();
        $repository->add($this->session(
            $sessionId,
            $userId,
            '2026-08-19T08:00:00+00:00',
            '2026-08-21T08:00:00+00:00',
            '2026-08-22T08:00:00+00:00',
            false
        ));
        $authorization = new FixedSessionAdministrationAuthorization(true);
        $handler = new ListActiveSessionsHandler(
            $repository,
            new FixedClock(new DateTimeImmutable('2026-08-20T12:00:00+00:00')),
            $authorization
        );

        $resultSet = $handler->handle(QueryMessage::create(new ListActiveSessions(
            $actorId,
            $userId,
            $sessionId,
            new Pagination(1, 10)
        )));
        $views = $resultSet->records();

        self::assertInstanceOf(ResultSet::class, $resultSet);
        self::assertCount(1, $views);
        self::assertSame($sessionId, $views[0]->getSessionId());
        self::assertSame(1, $authorization->calls());
        self::assertSame($actorId, $authorization->lastActorId());
        self::assertSame($userId, $authorization->lastUserId());
        self::assertSame(1, $repository->getByUserIdCalls());
    }

    public function test_that_denied_administrative_inspection_does_not_read_sessions(): void
    {
        $actorId = UserId::fromString('018f0000-0000-7000-8000-000000000008');
        $userId = UserId::fromString('018f0000-0000-7000-8000-000000000001');
        $repository = new InMemoryRefreshSessionRepository();
        $authorization = new FixedSessionAdministrationAuthorization(false);
        $handler = new ListActiveSessionsHandler(
            $repository,
            new FixedClock(new DateTimeImmutable('2026-08-20T12:00:00+00:00')),
            $authorization
        );

        try {
            $handler->handle(QueryMessage::create(new ListActiveSessions(
                $actorId,
                $userId,
                RefreshSessionId::fromString('018f0000-0000-7000-8000-000000000002'),
                new Pagination()
            )));
            self::fail('Unauthorized administrative inspection must be rejected.');
        } catch (SessionAdministrationAuthorizationException $sessionAdministrationAuthorizationException) {
            self::assertSame(
                'Session administration is not authorized.',
                $sessionAdministrationAuthorizationException->getMessage()
            );
        }

        self::assertSame(1, $authorization->calls());
        self::assertSame(0, $repository->getByUserIdCalls());
    }

    public function test_that_pagination_is_applied_after_active_session_filtering(): void
    {
        $userId = UserId::fromString('018f0000-0000-7000-8000-000000000001');
        $currentSessionId = RefreshSessionId::fromString('018f0000-0000-7000-8000-000000000002');
        $expectedSessionId = RefreshSessionId::fromString('018f0000-0000-7000-8000-000000000004');
        $repository = new InMemoryRefreshSessionRepository();
        $repository->add($this->session(
            RefreshSessionId::fromString('018f0000-0000-7000-8000-000000000003'),
            $userId,
            '2026-08-17T06:00:00+00:00',
            '2026-08-20T11:59:59+00:00',
            '2026-08-22T06:00:00+00:00',
            false
        ));
        $repository->add($this->session(
            $currentSessionId,
            $userId,
            '2026-08-19T08:00:00+00:00',
            '2026-08-21T08:00:00+00:00',
            '2026-08-22T08:00:00+00:00',
            false
        ));
        $repository->add($this->session(
            $expectedSessionId,
            $userId,
            '2026-08-18T07:00:00+00:00',
            '2026-08-21T07:00:00+00:00',
            '2026-08-22T07:00:00+00:00',
            false
        ));
        $handler = new ListActiveSessionsHandler(
            $repository,
            new FixedClock(new DateTimeImmutable('2026-08-20T12:00:00+00:00')),
            new FixedSessionAdministrationAuthorization(true)
        );

        $resultSet = $handler->handle(QueryMessage::create(new ListActiveSessions(
            $userId,
            $userId,
            $currentSessionId,
            new Pagination(2, 1, ['created_at' => Pagination::DESC])
        )));

        self::assertSame(2, $resultSet->page());
        self::assertSame(1, $resultSet->perPage());
        self::assertSame(2, $resultSet->totalRecords());
        self::assertSame(2, $resultSet->totalPages());
        self::assertCount(1, $resultSet->records());
        self::assertSame($expectedSessionId, $resultSet->records()->get(0)->getSessionId());
    }

    private function session(
        RefreshSessionId $refreshSessionId,
        UserId $userId,
        string $createdAt,
        string $idleExpiresAt,
        string $absoluteExpiresAt,
        bool $remembered
    ): RefreshSession {
        return RefreshSession::start(
            $refreshSessionId,
            $userId,
            RefreshCredential::fromString(str_repeat('a', 64)),
            new DateTimeImmutable($createdAt),
            new DateTimeImmutable($idleExpiresAt),
            new DateTimeImmutable($absoluteExpiresAt),
            1,
            $remembered
        );
    }
}
