<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\QueryHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\ActivationGrant\QueryHandler\FindInvitationDeliveryStatusHandler;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationCredential;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationDeliveryStatus;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrant;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Query\FindInvitationDeliveryStatus;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Query\InvitationDeliveryStatusView;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Query\QueryMessage;
use Fight\Common\Domain\Type\Arrayable;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\Repository\InMemoryActivationGrantRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FindInvitationDeliveryStatusHandler::class)]
#[CoversClass(FindInvitationDeliveryStatus::class)]
#[CoversClass(InvitationDeliveryStatusView::class)]
final class FindInvitationDeliveryStatusHandlerTest extends TestCase
{
    public function test_that_it_returns_a_safe_status_view_without_credential_material(): void
    {
        $userId = UserId::fromString('018f0000-0000-7000-8000-000000000001');
        $repository = new InMemoryActivationGrantRepository();
        $repository->add(ActivationGrant::issue(
            $userId,
            ActivationCredential::fromString('activate-once'),
            new DateTimeImmutable('2026-08-18T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-25T12:00:00+00:00'),
            EmailAddress::fromString('alice@example.test'),
            'ciphertext'
        ));
        $handler = new FindInvitationDeliveryStatusHandler($repository);

        self::assertSame(FindInvitationDeliveryStatus::class, FindInvitationDeliveryStatusHandler::queryRegistration());
        $view = $handler->handle(QueryMessage::create(new FindInvitationDeliveryStatus($userId)));

        self::assertInstanceOf(InvitationDeliveryStatusView::class, $view);
        self::assertSame($userId, $view->getUserId());
        self::assertSame(ActivationDeliveryStatus::PENDING, $view->getStatus());
        self::assertSame('2026-08-25T12:00:00+00:00', $view->getExpiresAt()->format(DATE_ATOM));
        self::assertArrayNotHasKey('ciphertext', get_object_vars($view));
        self::assertInstanceOf(Arrayable::class, $view);
        self::assertSame(
            [
                'user_id' => '018f0000-0000-7000-8000-000000000001',
                'status' => 'pending',
                'expires_at' => '2026-08-25T12:00:00+00:00',
            ],
            $view->toArray()
        );
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
        $handler = new FindInvitationDeliveryStatusHandler(new InMemoryActivationGrantRepository());

        $view = $handler->handle(QueryMessage::create(new FindInvitationDeliveryStatus(UserId::generate())));

        self::assertNull($view);
    }
}
