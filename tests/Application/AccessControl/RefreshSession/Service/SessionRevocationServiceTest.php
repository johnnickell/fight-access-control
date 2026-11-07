<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\RefreshSession\Service;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\RefreshSession\Service\SessionRevocationService;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshCredential;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSession;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionId;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionRepository;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Application\Error\Exception\StateConflict as RefreshSessionNotFoundException;
use Fight\Common\Domain\Collection\ArrayList;
use Fight\Common\Domain\Repository\Pagination;
use Fight\Common\Domain\Repository\ResultSet;
use Fight\Test\AccessControl\Application\AccessControl\RefreshSession\Repository\InMemoryRefreshSessionRepository;
use Fight\Test\AccessControl\Application\AccessControl\RefreshSession\Repository\InMemoryRefreshSessionRepositoryState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SessionRevocationService::class)]
final class SessionRevocationServiceTest extends TestCase
{
    public function test_that_revoke_persists_an_immutable_revocation(): void
    {
        $refreshSessionRepository = new InMemoryRefreshSessionRepository();
        $session = $this->session(UserId::generate());
        $refreshSessionRepository->add($session);
        $service = new SessionRevocationService($refreshSessionRepository);

        $revoked = $service->revoke($session);

        self::assertTrue($revoked->isRevoked());
        self::assertTrue($refreshSessionRepository->getById($session->getId())?->isRevoked());
    }

    public function test_that_revoke_returns_an_already_revoked_session_without_replacement(): void
    {
        $refreshSessionRepository = new InMemoryRefreshSessionRepository();
        $revoked = $this->session(UserId::generate())->revoke();
        $refreshSessionRepository->add($revoked);
        $service = new SessionRevocationService($refreshSessionRepository);

        $result = $service->revoke($revoked);

        self::assertSame($revoked, $result);
    }

    public function test_that_revoke_retries_after_a_stale_revision(): void
    {
        $state = new InMemoryRefreshSessionRepositoryState();
        $refreshSessionRepository = new InMemoryRefreshSessionRepository(state: $state);
        $session = $this->session(UserId::generate());
        $refreshSessionRepository->add($session);
        $state->refreshSessions[0] = $session->rotate(
            RefreshCredential::fromString(str_repeat('b', 64)),
            new DateTimeImmutable('2026-08-20T10:00:00+00:00'),
            new DateTimeImmutable('2026-08-21T10:00:00+00:00')
        );
        $service = new SessionRevocationService($refreshSessionRepository);

        $revoked = $service->revoke($session);

        self::assertTrue($revoked->isRevoked());
        self::assertTrue($refreshSessionRepository->getById($session->getId())?->isRevoked());
    }

    public function test_that_revoke_fails_when_the_session_disappears_during_retry(): void
    {
        $state = new InMemoryRefreshSessionRepositoryState();
        $refreshSessionRepository = new InMemoryRefreshSessionRepository(state: $state);
        $session = $this->session(UserId::generate());
        $refreshSessionRepository->add($session);
        $state->refreshSessions = [];
        $service = new SessionRevocationService($refreshSessionRepository);

        $this->expectException(RefreshSessionNotFoundException::class);
        $service->revoke($session);
    }

    public function test_that_revoke_fails_after_the_retry_limit(): void
    {
        $session = $this->session(UserId::generate());
        $service = new SessionRevocationService($this->alwaysFailingRepository($session));

        $this->expectException(RefreshSessionNotFoundException::class);
        $service->revoke($session);
    }

    public function test_that_revoke_all_active_for_only_revokes_usable_sessions(): void
    {
        $refreshSessionRepository = new InMemoryRefreshSessionRepository();
        $userId = UserId::generate();
        $active = $this->session($userId);
        $expired = RefreshSession::start(
            RefreshSessionId::generate(),
            $userId,
            RefreshCredential::fromString(str_repeat('c', 64)),
            new DateTimeImmutable('2026-08-19T08:00:00+00:00'),
            new DateTimeImmutable('2026-08-20T11:00:00+00:00'),
            new DateTimeImmutable('2026-08-22T08:00:00+00:00'),
            1,
            false
        );
        $alreadyRevoked = $this->session($userId)->revoke();
        $refreshSessionRepository->add($active);
        $refreshSessionRepository->add($expired);
        $refreshSessionRepository->add($alreadyRevoked);

        $service = new SessionRevocationService($refreshSessionRepository);

        $service->revokeAllActiveFor($userId, new DateTimeImmutable('2026-08-20T12:00:00+00:00'));

        self::assertTrue($refreshSessionRepository->all()[0]->isRevoked());
        self::assertFalse($refreshSessionRepository->all()[1]->isRevoked());
        self::assertTrue($refreshSessionRepository->all()[2]->isRevoked());
    }

    private function alwaysFailingRepository(RefreshSession $session): RefreshSessionRepository
    {
        return new readonly class ($session) implements RefreshSessionRepository {
            public function __construct(private RefreshSession $session)
            {
            }

            public function getById(RefreshSessionId $id): RefreshSession
            {
                return $this->session;
            }

            /** @return ResultSet<RefreshSession> */
            public function getByUserId(UserId $userId, DateTimeImmutable $at, Pagination $pagination): ResultSet
            {
                return new ResultSet(1, 1, 0, ArrayList::of(RefreshSession::class));
            }

            public function getAllActiveByUserId(UserId $userId, DateTimeImmutable $at): array
            {
                return [];
            }

            public function getByCredential(RefreshCredential $refreshCredential): ?RefreshSession
            {
                return null;
            }

            public function getByUsedCredential(RefreshCredential $refreshCredential): ?RefreshSession
            {
                return null;
            }

            public function replace(RefreshSession $expected, RefreshSession $replacement): bool
            {
                return false;
            }
        };
    }

    private function session(UserId $userId): RefreshSession
    {
        return RefreshSession::start(
            RefreshSessionId::generate(),
            $userId,
            RefreshCredential::fromString(str_repeat('a', 64)),
            new DateTimeImmutable('2026-08-19T08:00:00+00:00'),
            new DateTimeImmutable('2026-08-21T08:00:00+00:00'),
            new DateTimeImmutable('2026-08-22T08:00:00+00:00'),
            1,
            false
        );
    }
}
