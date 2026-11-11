<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User\CommandHandler;

use Fight\AccessControl\Application\AccessControl\User\CommandHandler\InvitePendingUserHandler;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrant;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationOrigin;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\User\Command\InvitePendingUser;
use Fight\AccessControl\Domain\AccessControl\User\Event\UserInvited;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Application\Error\Exception\StateConflict;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\Repository\InMemoryActivationGrantRepository;
use Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\Service\FixedCredentialGenerator;
use Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\Service\PrefixInvitationDeliveryCipher;
use Fight\Test\AccessControl\Application\AccessControl\Audit\Repository\InMemoryAuditEvidenceRepository;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryUserRepository;
use Fight\Test\AccessControl\Domain\AccessControl\User\UserFixture;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(InvitePendingUserHandler::class)]
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
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository();
        $unitOfWork = new InMemoryUnitOfWork();
        $eventDispatcher = new InMemoryEventDispatcher();
        $handler = $this->handler(
            $userRepository,
            $activationGrantRepository,
            $auditEvidenceRepository,
            $unitOfWork,
            $eventDispatcher
        );
        $userId = UserId::generate();
        $actorId = UserId::generate();

        $handler->handle(CommandMessage::create(new InvitePendingUser(
            $actorId,
            $userId,
            EmailAddress::fromString('Alice@example.test')
        )));

        self::assertSame(InvitePendingUser::class, InvitePendingUserHandler::commandRegistration());
        self::assertSame(1, $unitOfWork->transactions);
        self::assertCount(1, $userRepository->all());
        self::assertCount(1, $activationGrantRepository->all());
        self::assertCount(1, $auditEvidenceRepository->all());
        self::assertCount(1, $eventDispatcher->events());
        self::assertSame($userId, $userRepository->all()[0]->getId());
        self::assertSame('alice@example.test', $userRepository->all()[0]->getEmail()->canonical());
        self::assertSame(UserState::PENDING_ACTIVATION, $userRepository->all()[0]->getState());
        self::assertSame($userId, $activationGrantRepository->all()[0]->getUserId());
        self::assertSame(ActivationOrigin::PRIVATE_INVITATION, $activationGrantRepository->all()[0]->getOrigin());
        self::assertSame($userId, $activationGrantRepository->all()[0]->getDelivery()->getUserId());
        self::assertSame($userId, $auditEvidenceRepository->all()[0]->getSubject()->getId());
        self::assertSame($actorId, $auditEvidenceRepository->all()[0]->actorId());
        self::assertInstanceOf(UserInvited::class, $eventDispatcher->events()[0]);
    }

    #[DataProvider('reservingUserStates')]
    public function test_it_rejects_every_existing_lifecycle_email_reservation(UserState $state): void
    {
        $userRepository = new InMemoryUserRepository();
        $userRepository->add(UserFixture::withState('alice@example.test', $state));

        $eventDispatcher = new InMemoryEventDispatcher();
        $handler = $this->handler(
            $userRepository,
            new InMemoryActivationGrantRepository(),
            new InMemoryAuditEvidenceRepository(),
            new InMemoryUnitOfWork(),
            $eventDispatcher
        );

        $this->expectException(StateConflict::class);
        try {
            $handler->handle(CommandMessage::create(new InvitePendingUser(
                UserId::generate(),
                UserId::generate(),
                EmailAddress::fromString('ALICE@example.test')
            )));
        } finally {
            self::assertCount(1, $userRepository->all());
            self::assertCount(1, $eventDispatcher->events());
        }
    }

    public function test_it_rolls_back_every_durable_invitation_record_when_a_late_save_fails(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $userRepository = new InMemoryUserRepository($unitOfWork);
        $eventDispatcher = new InMemoryEventDispatcher();
        $handler = $this->handler(
            $userRepository,
            new InMemoryActivationGrantRepository($unitOfWork),
            new InMemoryAuditEvidenceRepository($unitOfWork, failAfterSave: true),
            $unitOfWork,
            $eventDispatcher
        );

        $this->expectException(RuntimeException::class);
        try {
            $handler->handle(CommandMessage::create(new InvitePendingUser(
                UserId::generate(),
                UserId::generate(),
                EmailAddress::fromString('alice@example.test')
            )));
        } finally {
            self::assertCount(0, $userRepository->all());
            self::assertCount(1, $eventDispatcher->events());
        }
    }

    public function test_it_reads_the_current_time_for_each_invitation(): void
    {
        $activationGrantRepository = new InMemoryActivationGrantRepository();
        $clock = new FixedClock(
            '2026-08-18T12:00:00+00:00',
            '2026-08-19T12:00:00+00:00'
        );
        $handler = new InvitePendingUserHandler(
            new InMemoryUserRepository(),
            $activationGrantRepository,
            new InMemoryUnitOfWork(),
            new FixedCredentialGenerator('activate-once'),
            new PrefixInvitationDeliveryCipher(),
            $clock,
            new InMemoryEventDispatcher(),
            new InMemoryAuditEvidenceRepository()
        );

        $handler->handle(CommandMessage::create(new InvitePendingUser(
            UserId::generate(),
            UserId::generate(),
            EmailAddress::fromString('alice@example.test')
        )));
        $handler->handle(CommandMessage::create(new InvitePendingUser(
            UserId::generate(),
            UserId::generate(),
            EmailAddress::fromString('bob@example.test')
        )));

        $activationGrants = $activationGrantRepository->all();

        self::assertSame(2, $clock->calls());
        self::assertSame('2026-08-25T12:00:00+00:00', $activationGrants[0]->getExpiresAt()->format(DATE_ATOM));
        self::assertSame('2026-08-26T12:00:00+00:00', $activationGrants[1]->getExpiresAt()->format(DATE_ATOM));
    }

    public function test_it_rejects_an_initial_activation_generation_race(): void
    {
        $eventDispatcher = new InMemoryEventDispatcher();
        $handler = $this->handler(
            new InMemoryUserRepository(),
            new InMemoryActivationGrantRepository(addSucceeds: false),
            new InMemoryAuditEvidenceRepository(),
            new InMemoryUnitOfWork(),
            $eventDispatcher
        );

        $this->expectException(LogicException::class);
        try {
            $handler->handle(CommandMessage::create(new InvitePendingUser(
                UserId::generate(),
                UserId::generate(),
                EmailAddress::fromString('alice@example.test')
            )));
        } finally {
            self::assertInstanceOf(CommandFailedEvent::class, $eventDispatcher->events()[0]);
        }
    }

    public function test_notification_failure_preserves_atomic_and_committed_invitation_outcomes(): void
    {
        foreach (['audit', 'commit', 'publication'] as $phase) {
            $unitOfWork = new InMemoryUnitOfWork();
            $userRepository = new InMemoryUserRepository($unitOfWork);
            $activationGrantRepository = new InMemoryActivationGrantRepository($unitOfWork);
            $auditEvidenceRepository = new InMemoryAuditEvidenceRepository(
                $unitOfWork,
                failAfterSave: $phase === 'audit'
            );
            $primary = new RuntimeException('Synthetic operation detail. activate-once ciphertext:activate-once');
            if ($phase === 'commit') {
                $unitOfWork->beforeCommit = static fn(): never => throw $primary;
            }

            $attempts = [];
            $eventDispatcher = new InMemoryEventDispatcher(static function ($event) use (&$attempts, $primary): void {
                $attempts[] = $event;
                if ($event instanceof UserInvited) {
                    throw $primary;
                }

                throw new RuntimeException('Synthetic notification failure.');
            });
            $command = new InvitePendingUser(
                UserId::generate(),
                UserId::generate(),
                EmailAddress::fromString('person@example.test')
            );
            $caught = null;
            try {
                $this->handler(
                    $userRepository,
                    $activationGrantRepository,
                    $auditEvidenceRepository,
                    $unitOfWork,
                    $eventDispatcher
                )->handle(
                    CommandMessage::create($command)
                );
            } catch (RuntimeException $exception) {
                $caught = $exception;
            }

            $expected = $primary;
            if ($phase === 'audit') {
                $expected = $auditEvidenceRepository->failure();
            }

            self::assertSame($expected, $caught);
            $committed = $phase === 'publication';
            self::assertCount((int) $committed, $userRepository->all());
            self::assertCount((int) $committed, $activationGrantRepository->all());
            self::assertCount((int) $committed, $auditEvidenceRepository->all());
            self::assertSame($committed, $unitOfWork->transactionCompleted);
            self::assertFalse($unitOfWork->transactionActive);
            self::assertCount(1 + (int) $committed, $attempts);
            $failure = $attempts[array_key_last($attempts)];
            self::assertInstanceOf(CommandFailedEvent::class, $failure);
            self::assertSame($command, $failure->getCommand());
            self::assertSame('User invitation failed.', $failure->getErrorMessage());
            self::assertStringNotContainsString('activate-once', serialize($failure->toArray()));
            if ($committed) {
                self::assertSame(UserState::PENDING_ACTIVATION, $userRepository->all()[0]->getState());
                self::assertTrue($activationGrantRepository->all()[0]->isIssued());
                self::assertTrue($activationGrantRepository->all()[0]->getDelivery()->hasRecoverableMaterial());
                self::assertSame('user.invited', $auditEvidenceRepository->all()[0]->action()->value);
            }
        }
    }

    private function handler(
        InMemoryUserRepository $userRepository,
        InMemoryActivationGrantRepository $activationGrantRepository,
        InMemoryAuditEvidenceRepository $auditEvidenceRepository,
        InMemoryUnitOfWork $unitOfWork,
        InMemoryEventDispatcher $eventDispatcher
    ): InvitePendingUserHandler {
        return new InvitePendingUserHandler(
            $userRepository,
            $activationGrantRepository,
            $unitOfWork,
            new FixedCredentialGenerator('activate-once'),
            new PrefixInvitationDeliveryCipher(),
            new FixedClock('2026-08-18T12:00:00+00:00'),
            $eventDispatcher,
            $auditEvidenceRepository
        );
    }
}
