<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User\CommandHandler;

use Fight\AccessControl\Application\AccessControl\User\CommandHandler\InvitePendingUserHandler;
use Fight\AccessControl\Domain\AccessControl\User\ActivationDeliveryWork;
use Fight\AccessControl\Domain\AccessControl\User\ActivationGrant;
use Fight\AccessControl\Domain\AccessControl\User\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\User\Command\InvitePendingUser;
use Fight\AccessControl\Domain\AccessControl\User\DuplicateEmail;
use Fight\AccessControl\Domain\AccessControl\User\Event\UserInvited;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryActivationDeliveryWorkRepository;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryActivationGrantRepository;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryAuditEvidenceRepository;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryUserRepository;
use Fight\Test\AccessControl\Domain\AccessControl\User\UserFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(InvitePendingUserHandler::class)]
#[CoversClass(ActivationDeliveryWork::class)]
#[CoversClass(ActivationGrant::class)]
#[CoversClass(AuditEvidence::class)]
#[CoversClass(InvitePendingUser::class)]
#[CoversClass(UserInvited::class)]
#[CoversClass(User::class)]
final class InvitePendingUserHandlerTest extends TestCase
{
    /**
     * @return iterable<string, array{UserState}>
     */
    public static function reservingUserStates(): iterable
    {
        yield 'pending activation' => [UserState::PENDING_ACTIVATION];
        yield 'active' => [UserState::ACTIVE];
        yield 'disabled' => [UserState::DISABLED];
        yield 'deleted' => [UserState::DELETED];
    }

    public function test_it_records_an_invitation_and_emits_a_domain_event_after_commit(): void
    {
        $users = new InMemoryUserRepository();
        $grants = new InMemoryActivationGrantRepository();
        $deliveries = new InMemoryActivationDeliveryWorkRepository();
        $audits = new InMemoryAuditEvidenceRepository();
        $unitOfWork = new InMemoryUnitOfWork();
        $events = new InMemoryEventDispatcher();
        $handler = $this->handler($users, $grants, $deliveries, $audits, $unitOfWork, $events);
        $userId = UserId::generate();

        $handler->handle(CommandMessage::create(new InvitePendingUser(
            'Admin-42',
            $userId,
            EmailAddress::fromString('Alice@example.test')
        )));

        self::assertSame(InvitePendingUser::class, InvitePendingUserHandler::commandRegistration());
        self::assertSame(1, $unitOfWork->transactions);
        self::assertCount(1, $users->all());
        self::assertCount(1, $grants->all());
        self::assertCount(1, $deliveries->all());
        self::assertCount(1, $audits->all());
        self::assertCount(1, $events->events());
        self::assertSame($userId, $users->all()[0]->getId());
        self::assertSame('alice@example.test', $users->all()[0]->getEmail()->canonical());
        self::assertSame(UserState::PENDING_ACTIVATION, $users->all()[0]->getState());
        self::assertSame($userId, $grants->all()[0]->getUserId());
        self::assertSame($userId, $deliveries->all()[0]->userId());
        self::assertSame($userId, $audits->all()[0]->userId());
        self::assertInstanceOf(UserInvited::class, $events->events()[0]);
    }

    #[DataProvider('reservingUserStates')]
    public function test_it_rejects_every_existing_lifecycle_email_reservation(UserState $state): void
    {
        $users = new InMemoryUserRepository();
        $users->add(UserFixture::withState('alice@example.test', $state));

        $events = new InMemoryEventDispatcher();
        $handler = $this->handler(
            $users,
            new InMemoryActivationGrantRepository(),
            new InMemoryActivationDeliveryWorkRepository(),
            new InMemoryAuditEvidenceRepository(),
            new InMemoryUnitOfWork(),
            $events
        );

        $this->expectException(DuplicateEmail::class);
        try {
            $handler->handle(CommandMessage::create(new InvitePendingUser(
                'Admin-42',
                UserId::generate(),
                EmailAddress::fromString('ALICE@example.test')
            )));
        } finally {
            self::assertCount(1, $users->all());
            self::assertCount(1, $events->events());
        }
    }

    public function test_it_rolls_back_every_durable_invitation_record_when_a_late_save_fails(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $users = new InMemoryUserRepository($unitOfWork);
        $events = new InMemoryEventDispatcher();
        $handler = $this->handler(
            $users,
            new InMemoryActivationGrantRepository($unitOfWork),
            new InMemoryActivationDeliveryWorkRepository($unitOfWork),
            new InMemoryAuditEvidenceRepository($unitOfWork, failAfterSave: true),
            $unitOfWork,
            $events
        );

        $this->expectException(RuntimeException::class);
        try {
            $handler->handle(CommandMessage::create(new InvitePendingUser(
                'Admin-42',
                UserId::generate(),
                EmailAddress::fromString('alice@example.test')
            )));
        } finally {
            self::assertCount(0, $users->all());
            self::assertCount(1, $events->events());
        }
    }

    public function test_it_reads_the_current_time_for_each_invitation(): void
    {
        $grants = new InMemoryActivationGrantRepository();
        $clock = new FixedInvitationClock(
            '2026-08-18T12:00:00+00:00',
            '2026-08-19T12:00:00+00:00'
        );
        $handler = new InvitePendingUserHandler(
            new InMemoryUserRepository(),
            $grants,
            new InMemoryActivationDeliveryWorkRepository(),
            new InMemoryAuditEvidenceRepository(),
            new InMemoryUnitOfWork(),
            new FixedCredentialGenerator('activate-once'),
            new PrefixCipher(),
            $clock,
            new InMemoryEventDispatcher()
        );

        $handler->handle(CommandMessage::create(new InvitePendingUser(
            'Admin-42',
            UserId::generate(),
            EmailAddress::fromString('alice@example.test')
        )));
        $handler->handle(CommandMessage::create(new InvitePendingUser(
            'Admin-42',
            UserId::generate(),
            EmailAddress::fromString('bob@example.test')
        )));

        self::assertSame(2, $clock->calls());
        self::assertSame('2026-08-25T12:00:00+00:00', $grants->all()[0]->getExpiresAt()->format(DATE_ATOM));
        self::assertSame('2026-08-26T12:00:00+00:00', $grants->all()[1]->getExpiresAt()->format(DATE_ATOM));
    }

    private function handler(
        InMemoryUserRepository $users,
        InMemoryActivationGrantRepository $grants,
        InMemoryActivationDeliveryWorkRepository $deliveries,
        InMemoryAuditEvidenceRepository $audits,
        InMemoryUnitOfWork $unitOfWork,
        InMemoryEventDispatcher $events
    ): InvitePendingUserHandler {
        return new InvitePendingUserHandler(
            $users,
            $grants,
            $deliveries,
            $audits,
            $unitOfWork,
            new FixedCredentialGenerator('activate-once'),
            new PrefixCipher(),
            new FixedInvitationClock('2026-08-18T12:00:00+00:00'),
            $events
        );
    }
}
