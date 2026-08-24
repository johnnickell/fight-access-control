<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User\QueryHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\User\QueryHandler\GetUserByIdHandler;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\User\Query\GetUserById;
use Fight\AccessControl\Domain\AccessControl\User\Query\UserView;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Query\QueryMessage;
use Fight\Common\Domain\Type\Arrayable;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryUserRepository;
use Fight\Test\AccessControl\Domain\AccessControl\User\UserFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GetUserByIdHandler::class)]
#[CoversClass(GetUserById::class)]
final class GetUserByIdHandlerTest extends TestCase
{
    public function test_that_it_returns_the_safe_arrayable_user_view_for_a_stable_identifier(): void
    {
        $userId = UserId::fromString('018f0000-0000-7000-8000-000000000001');
        $roleId = RoleId::fromString('018f0000-0000-7000-8000-000000000002');
        $user = UserFixture::withIdAndAuthenticationVersion(
            $userId,
            'admin@example.test',
            UserState::ACTIVE,
            1
        );
        $user->assignRole($roleId, new DateTimeImmutable('2026-01-01T00:00:00+00:00'));

        $users = new InMemoryUserRepository();
        $users->add($user);

        $handler = new GetUserByIdHandler($users);

        self::assertSame(GetUserById::class, GetUserByIdHandler::queryRegistration());
        $view = $handler->handle(QueryMessage::create(new GetUserById($userId)));

        self::assertInstanceOf(UserView::class, $view);
        self::assertInstanceOf(Arrayable::class, $view);
        self::assertSame(
            [
                'user_id' => '018f0000-0000-7000-8000-000000000001',
                'email' => 'admin@example.test',
                'state' => 'active',
                'role_ids' => ['018f0000-0000-7000-8000-000000000002'],
                'created_at' => '2026-01-01T00:00:00+00:00',
                'updated_at' => '2026-01-01T00:00:00+00:00',
            ],
            $view->toArray()
        );
    }

    public function test_that_the_query_round_trips_and_rejects_missing_user_id(): void
    {
        $userId = UserId::fromString('018f0000-0000-7000-8000-000000000003');
        $query = new GetUserById($userId);

        self::assertEquals($query, GetUserById::fromArray($query->toArray()));
        self::assertSame($userId, $query->getUserId());
        $this->expectException(DomainException::class);
        GetUserById::fromArray([]);
    }

    public function test_that_it_returns_null_for_an_unknown_stable_identifier(): void
    {
        $handler = new GetUserByIdHandler(new InMemoryUserRepository());

        $view = $handler->handle(QueryMessage::create(new GetUserById(
            UserId::fromString('018f0000-0000-7000-8000-000000000004')
        )));

        self::assertNull($view);
    }
}
