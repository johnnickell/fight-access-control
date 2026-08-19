<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User\CommandHandler;

use Fight\AccessControl\Application\AccessControl\User\CommandHandler\InvitePendingUserHandler;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\User\ActivationGrant;
use Fight\AccessControl\Domain\AccessControl\User\Command\InvitePendingUser;
use Fight\AccessControl\Domain\AccessControl\User\Event\UserInvited;
use Fight\AccessControl\Domain\AccessControl\User\Exception\DuplicateEmailException;
use Fight\AccessControl\Domain\AccessControl\User\InvitationDelivery;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use Fight\Test\AccessControl\Application\AccessControl\Audit\Repository\InMemoryAuditEvidenceRepository;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryActivationGrantRepository;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryInvitationDeliveryRepository;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryUserRepository;
use Fight\Test\AccessControl\Application\AccessControl\User\Service\FixedCredentialGenerator;
use Fight\Test\AccessControl\Application\AccessControl\User\Service\FixedInvitationClock;
use Fight\Test\AccessControl\Application\AccessControl\User\Service\PrefixInvitationDeliveryCipher;
use Fight\Test\AccessControl\Domain\AccessControl\User\UserFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(InvitePendingUserHandler::class)]
#[CoversClass(InvitationDelivery::class)]
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
        $userRepository = new InMemoryUserRepository();
        $activationGrantRepository = new InMemoryActivationGrantRepository();
        $invitationDeliveryRepository = new InMemoryInvitationDeliveryRepository();
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository();
        $unitOfWork = new InMemoryUnitOfWork();
        $events = new InMemoryEventDispatcher();
        $handler = $this->handler(
            $userRepository,
            $activationGrantRepository,
            $invitationDeliveryRepository,
            $auditEvidenceRepository,
            $unitOfWork,
            $events
        );
        $userId = UserId::generate();

        $handler->handle(CommandMessage::create(new InvitePendingUser(
            'Admin-42',
            $userId,
            EmailAddress::fromString('Alice@example.test')
        )));

        self::assertSame(InvitePendingUser::class, InvitePendingUserHandler::commandRegistration());
        self::assertSame(1, $unitOfWork->transactions);
        self::assertCount(1, $userRepository->all());
        self::assertCount(1, $activationGrantRepository->all());
        self::assertCount(1, $invitationDeliveryRepository->all());
        self::assertCount(1, $auditEvidenceRepository->all());
        self::assertCount(1, $events->events());
        self::assertSame($userId, $userRepository->all()[0]->getId());
        self::assertSame('alice@example.test', $userRepository->all()[0]->getEmail()->canonical());
        self::assertSame(UserState::PENDING_ACTIVATION, $userRepository->all()[0]->getState());
        self::assertSame($userId, $activationGrantRepository->all()[0]->getUserId());
        self::assertSame($userId, $invitationDeliveryRepository->all()[0]->userId());
        self::assertSame($userId, $auditEvidenceRepository->all()[0]->userId());
        self::assertInstanceOf(UserInvited::class, $events->events()[0]);
    }

    #[DataProvider('reservingUserStates')]
    public function test_it_rejects_every_existing_lifecycle_email_reservation(UserState $state): void
    {
        $userRepository = new InMemoryUserRepository();
        $userRepository->add(UserFixture::withState('alice@example.test', $state));

        $events = new InMemoryEventDispatcher();
        $handler = $this->handler(
            $userRepository,
            new InMemoryActivationGrantRepository(),
            new InMemoryInvitationDeliveryRepository(),
            new InMemoryAuditEvidenceRepository(),
            new InMemoryUnitOfWork(),
            $events
        );

        $this->expectException(DuplicateEmailException::class);
        try {
            $handler->handle(CommandMessage::create(new InvitePendingUser(
                'Admin-42',
                UserId::generate(),
                EmailAddress::fromString('ALICE@example.test')
            )));
        } finally {
            self::assertCount(1, $userRepository->all());
            self::assertCount(1, $events->events());
        }
    }

    public function test_it_rolls_back_every_durable_invitation_record_when_a_late_save_fails(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $userRepository = new InMemoryUserRepository($unitOfWork);
        $events = new InMemoryEventDispatcher();
        $handler = $this->handler(
            $userRepository,
            new InMemoryActivationGrantRepository($unitOfWork),
            new InMemoryInvitationDeliveryRepository($unitOfWork),
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
            self::assertCount(0, $userRepository->all());
            self::assertCount(1, $events->events());
        }
    }

    public function test_it_reads_the_current_time_for_each_invitation(): void
    {
        $activationGrantRepository = new InMemoryActivationGrantRepository();
        $clock = new FixedInvitationClock(
            '2026-08-18T12:00:00+00:00',
            '2026-08-19T12:00:00+00:00'
        );
        $handler = new InvitePendingUserHandler(
            new InMemoryUserRepository(),
            $activationGrantRepository,
            new InMemoryInvitationDeliveryRepository(),
            new InMemoryAuditEvidenceRepository(),
            new InMemoryUnitOfWork(),
            new FixedCredentialGenerator('activate-once'),
            new PrefixInvitationDeliveryCipher(),
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

        $activationGrants = $activationGrantRepository->all();

        self::assertSame(2, $clock->calls());
        self::assertSame('2026-08-25T12:00:00+00:00', $activationGrants[0]->getExpiresAt()->format(DATE_ATOM));
        self::assertSame('2026-08-26T12:00:00+00:00', $activationGrants[1]->getExpiresAt()->format(DATE_ATOM));
    }

    private function handler(
        InMemoryUserRepository $userRepository,
        InMemoryActivationGrantRepository $activationGrantRepository,
        InMemoryInvitationDeliveryRepository $invitationDeliveryRepository,
        InMemoryAuditEvidenceRepository $auditEvidenceRepository,
        InMemoryUnitOfWork $unitOfWork,
        InMemoryEventDispatcher $events
    ): InvitePendingUserHandler {
        return new InvitePendingUserHandler(
            $userRepository,
            $activationGrantRepository,
            $invitationDeliveryRepository,
            $auditEvidenceRepository,
            $unitOfWork,
            new FixedCredentialGenerator('activate-once'),
            new PrefixInvitationDeliveryCipher(),
            new FixedInvitationClock('2026-08-18T12:00:00+00:00'),
            $events
        );
    }
}
