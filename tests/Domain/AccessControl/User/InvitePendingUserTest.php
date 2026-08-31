<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\User;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationDeliveryId;
use Fight\AccessControl\Domain\AccessControl\User\Command\InvitePendingUser;
use Fight\AccessControl\Domain\AccessControl\User\Event\UserInvited;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(InvitePendingUser::class)]
#[CoversClass(UserInvited::class)]
final class InvitePendingUserTest extends TestCase
{
    public function test_that_the_invitation_command_round_trips_its_canonical_payload(): void
    {
        $userId = UserId::generate();
        $command = new InvitePendingUser('Admin-42', $userId, EmailAddress::fromString('Alice@example.test'));

        self::assertSame('Admin-42', $command->getActorId());
        self::assertSame($userId, $command->getUserId());
        self::assertSame('Alice@example.test', $command->getEmail()->toString());
        self::assertSame(
            $command->toArray(),
            InvitePendingUser::fromArray($command->toArray())->toArray()
        );
    }

    public function test_that_the_invitation_command_rejects_a_missing_payload_field(): void
    {
        $this->expectException(DomainException::class);

        InvitePendingUser::fromArray([]);
    }

    public function test_that_the_user_invited_event_round_trips_its_payload(): void
    {
        $event = new UserInvited(
            'Admin-42',
            UserId::generate(),
            ActivationDeliveryId::generate(),
            EmailAddress::fromString('Alice@example.test'),
            new DateTimeImmutable('2026-08-18T12:00:00+00:00')
        );

        self::assertSame('Admin-42', $event->getActorId());
        self::assertInstanceOf(UserId::class, $event->getUserId());
        self::assertInstanceOf(ActivationDeliveryId::class, $event->getActivationDeliveryId());
        self::assertSame('Alice@example.test', $event->getEmail()->toString());
        self::assertSame('2026-08-18T12:00:00+00:00', $event->getIssuedAt()->format(DATE_ATOM));
        self::assertSame($event->toArray(), UserInvited::fromArray($event->toArray())->toArray());
    }

    public function test_that_the_user_invited_event_rejects_a_missing_payload_field(): void
    {
        $this->expectException(DomainException::class);

        UserInvited::fromArray([]);
    }
}
