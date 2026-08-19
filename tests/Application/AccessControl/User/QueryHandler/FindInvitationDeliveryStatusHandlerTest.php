<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User\QueryHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\User\QueryHandler\FindInvitationDeliveryStatusHandler;
use Fight\AccessControl\Domain\AccessControl\User\InvitationDelivery;
use Fight\AccessControl\Domain\AccessControl\User\InvitationDeliveryStatus;
use Fight\AccessControl\Domain\AccessControl\User\Query\FindInvitationDeliveryStatus;
use Fight\AccessControl\Domain\AccessControl\User\Query\InvitationDeliveryStatusView;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Query\QueryMessage;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryInvitationDeliveryRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FindInvitationDeliveryStatusHandler::class)]
#[CoversClass(FindInvitationDeliveryStatus::class)]
#[CoversClass(InvitationDeliveryStatusView::class)]
final class FindInvitationDeliveryStatusHandlerTest extends TestCase
{
    public function test_that_it_returns_a_safe_status_view_without_credential_material(): void
    {
        $userId = UserId::generate();
        $repository = new InMemoryInvitationDeliveryRepository();
        $repository->add(InvitationDelivery::create(
            $userId,
            'alice@example.test',
            'ciphertext',
            new DateTimeImmutable('2026-08-25T12:00:00+00:00')
        ));
        $handler = new FindInvitationDeliveryStatusHandler($repository);

        self::assertSame(FindInvitationDeliveryStatus::class, FindInvitationDeliveryStatusHandler::queryRegistration());
        $view = $handler->handle(QueryMessage::create(new FindInvitationDeliveryStatus($userId)));

        self::assertInstanceOf(InvitationDeliveryStatusView::class, $view);
        self::assertSame($userId, $view->getUserId());
        self::assertSame(InvitationDeliveryStatus::PENDING, $view->getStatus());
        self::assertSame('2026-08-25T12:00:00+00:00', $view->getExpiresAt()->format(DATE_ATOM));
        self::assertArrayNotHasKey('ciphertext', get_object_vars($view));
    }

    public function test_that_the_query_round_trips_and_rejects_missing_user_id(): void
    {
        $query = new FindInvitationDeliveryStatus(UserId::generate());

        self::assertEquals($query, FindInvitationDeliveryStatus::fromArray($query->toArray()));
        $this->expectException(DomainException::class);
        FindInvitationDeliveryStatus::fromArray([]);
    }

    public function test_that_it_returns_null_when_no_delivery_work_exists(): void
    {
        $handler = new FindInvitationDeliveryStatusHandler(new InMemoryInvitationDeliveryRepository());

        $view = $handler->handle(QueryMessage::create(new FindInvitationDeliveryStatus(UserId::generate())));

        self::assertNull($view);
    }
}
