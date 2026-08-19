<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User\QueryHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\User\QueryHandler\GetActivationDeliveryStatusHandler;
use Fight\AccessControl\Domain\AccessControl\User\ActivationDeliveryStatus;
use Fight\AccessControl\Domain\AccessControl\User\ActivationDeliveryWork;
use Fight\AccessControl\Domain\AccessControl\User\Query\ActivationDeliveryStatusView;
use Fight\AccessControl\Domain\AccessControl\User\Query\GetActivationDeliveryStatus;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Query\QueryMessage;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryActivationDeliveryWorkRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GetActivationDeliveryStatusHandler::class)]
#[CoversClass(GetActivationDeliveryStatus::class)]
#[CoversClass(ActivationDeliveryStatusView::class)]
final class GetActivationDeliveryStatusHandlerTest extends TestCase
{
    public function test_that_it_returns_a_safe_status_view_without_credential_material(): void
    {
        $userId = UserId::generate();
        $repository = new InMemoryActivationDeliveryWorkRepository();
        $repository->add(ActivationDeliveryWork::create(
            $userId,
            'alice@example.test',
            'ciphertext',
            new DateTimeImmutable('2026-08-25T12:00:00+00:00')
        ));
        $handler = new GetActivationDeliveryStatusHandler($repository);

        self::assertSame(GetActivationDeliveryStatus::class, GetActivationDeliveryStatusHandler::queryRegistration());
        $view = $handler->handle(QueryMessage::create(new GetActivationDeliveryStatus($userId)));

        self::assertInstanceOf(ActivationDeliveryStatusView::class, $view);
        self::assertSame($userId, $view->getUserId());
        self::assertSame(ActivationDeliveryStatus::PENDING, $view->getStatus());
        self::assertSame('2026-08-25T12:00:00+00:00', $view->getExpiresAt()->format(DATE_ATOM));
        self::assertArrayNotHasKey('ciphertext', get_object_vars($view));
    }

    public function test_that_the_query_round_trips_and_rejects_missing_user_id(): void
    {
        $query = new GetActivationDeliveryStatus(UserId::generate());

        self::assertEquals($query, GetActivationDeliveryStatus::fromArray($query->toArray()));
        $this->expectException(DomainException::class);
        GetActivationDeliveryStatus::fromArray([]);
    }

    public function test_that_it_returns_null_when_no_delivery_work_exists(): void
    {
        $handler = new GetActivationDeliveryStatusHandler(new InMemoryActivationDeliveryWorkRepository());

        $view = $handler->handle(QueryMessage::create(new GetActivationDeliveryStatus(UserId::generate())));

        self::assertNull($view);
    }
}
