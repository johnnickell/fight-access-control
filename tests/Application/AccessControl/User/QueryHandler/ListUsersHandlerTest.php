<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User\QueryHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\User\QueryHandler\ListUsersHandler;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\User\Query\ListUsers;
use Fight\AccessControl\Domain\AccessControl\User\Query\UserView;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Query\QueryMessage;
use Fight\Common\Domain\Repository\Pagination;
use Fight\Common\Domain\Repository\ResultSet;
use Fight\Common\Domain\Type\Arrayable;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryUserRepository;
use Fight\Test\AccessControl\Domain\AccessControl\User\UserFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

#[CoversClass(ListUsersHandler::class)]
#[CoversClass(ListUsers::class)]
#[CoversClass(UserView::class)]
final class ListUsersHandlerTest extends TestCase
{
    public function test_that_it_returns_a_typed_page_of_safe_user_views(): void
    {
        $users = new InMemoryUserRepository();
        $roleId = RoleId::fromString('018f0000-0000-7000-8000-000000000002');
        $active = UserFixture::withIdAndAuthenticationVersion(
            UserId::fromString('018f0000-0000-7000-8000-000000000001'),
            'active@example.test',
            UserState::ACTIVE,
            1
        );
        $active->assignRole($roleId, new DateTimeImmutable('2026-01-01T00:00:00+00:00'));

        $disabled = UserFixture::withState('disabled@example.test', UserState::DISABLED);
        $users->add($active);
        $users->add($disabled);

        $handler = new ListUsersHandler($users);

        self::assertSame(ListUsers::class, ListUsersHandler::queryRegistration());
        $resultSet = $handler->handle(QueryMessage::create(new ListUsers(new Pagination(1, 25))));
        $views = $resultSet->records();

        self::assertInstanceOf(ResultSet::class, $resultSet);
        self::assertSame(1, $resultSet->page());
        self::assertSame(25, $resultSet->perPage());
        self::assertSame(2, $resultSet->totalRecords());
        self::assertCount(2, $views);
        self::assertInstanceOf(UserView::class, $views->get(0));
        self::assertSame($active->getId(), $views->get(0)->getUserId());
        self::assertSame($active->getEmail(), $views->get(0)->getEmail());
        self::assertSame(UserState::ACTIVE, $views->get(0)->getState());
        self::assertSame([$roleId], $views->get(0)->getRoleIds());
        self::assertInstanceOf(DateTimeImmutable::class, $views->get(0)->getCreatedAt());
        self::assertEquals(new DateTimeImmutable('2026-01-01T00:00:00+00:00'), $views->get(0)->getCreatedAt());
        self::assertInstanceOf(DateTimeImmutable::class, $views->get(0)->getUpdatedAt());
        self::assertEquals(new DateTimeImmutable('2026-01-01T00:00:00+00:00'), $views->get(0)->getUpdatedAt());
        self::assertSame($disabled->getId(), $views->get(1)->getUserId());
        self::assertSame(UserState::DISABLED, $views->get(1)->getState());
        self::assertSame([], $views->get(1)->getRoleIds());
        self::assertInstanceOf(Arrayable::class, $views->get(0));
        self::assertSame(
            [
                'user_id' => '018f0000-0000-7000-8000-000000000001',
                'email' => 'active@example.test',
                'state' => 'active',
                'role_ids' => ['018f0000-0000-7000-8000-000000000002'],
                'created_at' => '2026-01-01T00:00:00+00:00',
                'updated_at' => '2026-01-01T00:00:00+00:00',
            ],
            $views->get(0)->toArray()
        );

        $properties = array_map(
            static fn(ReflectionProperty $property): string => $property->getName(),
            new ReflectionClass(UserView::class)->getProperties()
        );
        sort($properties);

        self::assertSame(['createdAt', 'email', 'roleIds', 'state', 'updatedAt', 'userId'], $properties);
    }

    public function test_that_the_query_round_trips_and_rejects_missing_required_data(): void
    {
        $query = new ListUsers(new Pagination(2, 10, ['created_at' => Pagination::DESC]));

        self::assertEquals($query, ListUsers::fromArray($query->toArray()));
        self::assertSame(2, $query->getPagination()->page());
        self::assertSame(10, $query->getPagination()->perPage());
        self::assertSame(['created_at' => Pagination::DESC], $query->getPagination()->orderings());

        $rejectedPayloads = 0;
        foreach (array_keys($query->toArray()) as $requiredKey) {
            $incompleteData = $query->toArray();
            unset($incompleteData[$requiredKey]);

            try {
                ListUsers::fromArray($incompleteData);
                self::fail('Incomplete query data must be rejected.');
            } catch (DomainException) {
                ++$rejectedPayloads;
            }
        }

        self::assertSame(3, $rejectedPayloads);
    }

    public function test_that_pagination_is_applied(): void
    {
        $users = new InMemoryUserRepository();
        $first = UserFixture::withState('first@example.test', UserState::ACTIVE);
        $second = UserFixture::withState('second@example.test', UserState::ACTIVE);
        $users->add($first);
        $users->add($second);

        $handler = new ListUsersHandler($users);

        $resultSet = $handler->handle(QueryMessage::create(new ListUsers(new Pagination(2, 1))));
        $views = $resultSet->records();

        self::assertSame(2, $resultSet->page());
        self::assertSame(1, $resultSet->perPage());
        self::assertSame(2, $resultSet->totalRecords());
        self::assertSame(2, $resultSet->totalPages());
        self::assertCount(1, $views);
        self::assertSame($second->getId(), $views->get(0)->getUserId());
    }

    public function test_that_an_unknown_user_identifier_is_not_exposed(): void
    {
        $users = new InMemoryUserRepository();
        $handler = new ListUsersHandler($users);

        $resultSet = $handler->handle(QueryMessage::create(new ListUsers(new Pagination())));

        self::assertSame(0, $resultSet->totalRecords());
        self::assertCount(0, $resultSet->records());
    }
}
