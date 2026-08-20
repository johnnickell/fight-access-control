<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\RefreshSession\QueryHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\RefreshSession\QueryHandler\RestoreAuthenticatedSessionHandler;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\Query\AuthenticatedSessionView;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\Query\RestoreAuthenticatedSession;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSession;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionId;
use Fight\AccessControl\Domain\AccessControl\User\PasswordHash;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\AccessControl\Domain\AccessControl\User\UserRepository;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Query\QueryMessage;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use Fight\Test\AccessControl\Application\AccessControl\RefreshSession\Repository\InMemoryRefreshSessionRepository;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryUserRepository;
use Fight\Test\AccessControl\Domain\AccessControl\User\UserFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(RestoreAuthenticatedSessionHandler::class)]
#[CoversClass(RestoreAuthenticatedSession::class)]
#[CoversClass(AuthenticatedSessionView::class)]
final class RestoreAuthenticatedSessionHandlerTest extends TestCase
{
    /**
     * @return array<string, array{?RefreshSession, ?User}>
     */
    public static function rejectedRestorationCases(): array
    {
        $sessionId = RefreshSessionId::generate();
        $ownerId = UserId::generate();

        return [
            'missing session' => [null, null],
            'missing owner' => [RefreshSession::start($sessionId, $ownerId, new DateTimeImmutable()), null],
            'mismatched owner' => [
                RefreshSession::start($sessionId, $ownerId, new DateTimeImmutable()),
                UserFixture::withState('mismatched@example.test', UserState::ACTIVE),
            ],
            'pending owner' => [
                RefreshSession::start($sessionId, $ownerId, new DateTimeImmutable()),
                UserFixture::withState('pending@example.test', UserState::PENDING_ACTIVATION),
            ],
            'disabled owner' => [
                RefreshSession::start($sessionId, $ownerId, new DateTimeImmutable()),
                UserFixture::withState('disabled@example.test', UserState::DISABLED),
            ],
            'deleted owner' => [
                RefreshSession::start($sessionId, $ownerId, new DateTimeImmutable()),
                UserFixture::withState('deleted@example.test', UserState::DELETED),
            ],
        ];
    }

    public function test_that_it_revalidates_an_active_session_owner_and_returns_a_safe_authenticated_view(): void
    {
        $users = new InMemoryUserRepository();
        $sessions = new InMemoryRefreshSessionRepository();
        $user = $this->activeUser();
        $sessionId = RefreshSessionId::generate();
        $users->add($user);
        $session = RefreshSession::start(
            $sessionId,
            $user->getId(),
            new DateTimeImmutable('2026-08-19T12:00:00+00:00')
        );
        $sessions->add($session);
        $handler = new RestoreAuthenticatedSessionHandler($sessions, $users);

        self::assertSame(RestoreAuthenticatedSession::class, RestoreAuthenticatedSessionHandler::queryRegistration());
        self::assertSame($session->getAuthenticationVersion(), $user->getAuthenticationVersion());
        $view = $handler->handle(QueryMessage::create(new RestoreAuthenticatedSession($sessionId)));

        self::assertInstanceOf(AuthenticatedSessionView::class, $view);
        self::assertSame($sessionId, $view->getRefreshSessionId());
        self::assertSame($user->getId(), $view->getUserId());
        self::assertArrayNotHasKey('accessToken', get_object_vars($view));
    }

    public function test_that_it_rejects_a_repository_result_that_does_not_match_the_session_owner(): void
    {
        $sessionId = RefreshSessionId::generate();
        $session = RefreshSession::start($sessionId, UserId::generate(), new DateTimeImmutable());
        $sessions = new InMemoryRefreshSessionRepository();
        $sessions->add($session);

        $mismatchedUser = UserFixture::withState('mismatched@example.test', UserState::ACTIVE);
        $users = new readonly class ($session->getUserId(), $mismatchedUser) implements UserRepository {
            public function __construct(
                private UserId $requestedUserId,
                private User $user
            ) {
            }

            public function add(User $user): void
            {
            }

            public function getByEmail(EmailAddress $email): ?User
            {
                return null;
            }

            public function getById(UserId $id): ?User
            {
                if ($id !== $this->requestedUserId) {
                    return null;
                }

                return $this->user;
            }
        };
        $handler = new RestoreAuthenticatedSessionHandler($sessions, $users);

        $view = $handler->handle(QueryMessage::create(new RestoreAuthenticatedSession($sessionId)));

        self::assertNull($view);
    }

    public function test_that_it_rejects_an_active_owner_when_its_authentication_version_has_changed(): void
    {
        $sessionId = RefreshSessionId::generate();
        $userId = UserId::generate();
        $sessions = new InMemoryRefreshSessionRepository();
        $users = new InMemoryUserRepository();
        $sessions->add(RefreshSession::start($sessionId, $userId, new DateTimeImmutable()));
        $users->add(UserFixture::withIdAndAuthenticationVersion(
            $userId,
            'version-mismatch@example.test',
            UserState::ACTIVE,
            2
        ));
        $handler = new RestoreAuthenticatedSessionHandler($sessions, $users);

        $view = $handler->handle(QueryMessage::create(new RestoreAuthenticatedSession($sessionId)));

        self::assertNull($view);
    }

    #[DataProvider('rejectedRestorationCases')]
    public function test_that_it_returns_no_authenticated_view_when_authority_cannot_be_revalidated(
        ?RefreshSession $session,
        ?User $user
    ): void {
        $sessions = new InMemoryRefreshSessionRepository();
        $users = new InMemoryUserRepository();
        $sessionId = $session?->getId() ?? RefreshSessionId::generate();
        if ($session instanceof RefreshSession) {
            $sessions->add($session);
        }

        if ($user instanceof User) {
            $users->add($user);
        }

        $handler = new RestoreAuthenticatedSessionHandler($sessions, $users);

        $view = $handler->handle(QueryMessage::create(new RestoreAuthenticatedSession($sessionId)));

        self::assertNull($view);
    }

    public function test_that_the_query_round_trips_and_rejects_missing_refresh_session_id(): void
    {
        $query = new RestoreAuthenticatedSession(RefreshSessionId::generate());

        self::assertEquals($query, RestoreAuthenticatedSession::fromArray($query->toArray()));
        $this->expectException(DomainException::class);
        RestoreAuthenticatedSession::fromArray([]);
    }

    private function activeUser(): User
    {
        $user = User::invite(UserId::generate(), EmailAddress::fromString('person@example.test'));
        $user->activate(PasswordHash::fromString(password_hash('correct-secret', PASSWORD_DEFAULT)));

        return $user;
    }
}
