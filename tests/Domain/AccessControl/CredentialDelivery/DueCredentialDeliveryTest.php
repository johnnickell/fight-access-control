<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\CredentialDelivery;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationDeliveryId;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryStatus;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\DueCredentialDelivery;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DueCredentialDelivery::class)]
final class DueCredentialDeliveryTest extends TestCase
{
    public function test_it_exposes_only_safe_discovery_state(): void
    {
        $id = ActivationDeliveryId::generate();
        $dueAt = new DateTimeImmutable('2026-08-25T12:00:00+00:00');
        $userId = UserId::generate();
        $work = new DueCredentialDelivery(
            'activation',
            $id,
            $userId,
            $dueAt,
            3,
            CredentialDeliveryStatus::RETRY_PENDING
        );

        self::assertSame('activation', $work->getPurpose());
        self::assertSame($id, $work->getDeliveryId());
        self::assertSame($userId, $work->getUserId());
        self::assertSame($dueAt, $work->getDueAt());
        self::assertSame(3, $work->getRevision());
        self::assertSame(CredentialDeliveryStatus::RETRY_PENDING, $work->getStatus());
        self::assertSame([
            'purpose'     => 'activation',
            'delivery_id' => $id->toString(),
            'user_id'     => $userId->toString(),
            'due_at'      => $dueAt->format(DATE_ATOM),
            'revision'    => 3,
            'status'      => 'retry_pending'
        ], $work->toArray());
        self::assertStringNotContainsString('credential', serialize($work->toArray()));
    }
}
