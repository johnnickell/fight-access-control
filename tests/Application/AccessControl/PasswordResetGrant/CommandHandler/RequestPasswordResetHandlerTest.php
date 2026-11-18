<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\PasswordResetGrant\CommandHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\PasswordResetGrant\CommandHandler\RequestPasswordResetHandler;
use Fight\AccessControl\Domain\AccessControl\Audit\AnonymousId;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditAction;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditGrantOutcome;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditGrantState;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\Command\RequestPasswordReset;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\Event\PasswordResetRequested;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetCredential;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrant;
use Fight\AccessControl\Domain\AccessControl\User\RecoveryAuthority;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Application\Error\Exception\StateConflict;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use Fight\Test\AccessControl\Application\AccessControl\Audit\Repository\InMemoryAuditEvidenceRepository;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\PasswordResetGrant\Repository\InMemoryPasswordResetGrants;
use Fight\Test\AccessControl\Application\AccessControl\PasswordResetGrant\Service\FixedPasswordResetCredentialGenerator;
use Fight\Test\AccessControl\Application\AccessControl\PasswordResetGrant\Service\PrefixPasswordResetDeliveryCipher;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryUserRepository;
use Fight\Test\AccessControl\Domain\AccessControl\User\UserFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(RequestPasswordResetHandler::class)]
#[CoversClass(PasswordResetGrant::class)]
#[CoversClass(AuditEvidence::class)]
#[CoversClass(PasswordResetRequested::class)]
final class RequestPasswordResetHandlerTest extends TestCase
{
    /** @return iterable<string, array{?UserState}> */
    public static function genericOutcomeStates(): iterable
    {
        yield 'unknown' => [null];
        yield 'pending' => [UserState::PENDING_ACTIVATION];
        yield 'disabled' => [UserState::DISABLED];
        yield 'deleted' => [UserState::DELETED];
    }

    public function test_it_atomically_stages_the_complete_aggregate_audit_and_post_commit_event(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $userRepository = new InMemoryUserRepository();
        $user = UserFixture::withState('alice@example.test', UserState::ACTIVE);
        $userRepository->add($user);
        $passwordResetGrantRepository = new InMemoryPasswordResetGrants($unitOfWork);
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork);
        $eventDispatcher = new InMemoryEventDispatcher(
            static function () use ($auditEvidenceRepository, $passwordResetGrantRepository, $unitOfWork): void {
                self::assertTrue($unitOfWork->transactionCompleted);
                self::assertCount(1, $passwordResetGrantRepository->all());
                self::assertCount(1, $auditEvidenceRepository->all());
            }
        );

        $this->handler(
            $userRepository,
            $passwordResetGrantRepository,
            $auditEvidenceRepository,
            $unitOfWork,
            $eventDispatcher
        )->handle(
            CommandMessage::create(new RequestPasswordReset(EmailAddress::fromString('ALICE@example.test')))
        );

        $grant = $passwordResetGrantRepository->all()[0];
        self::assertSame(RequestPasswordReset::class, RequestPasswordResetHandler::commandRegistration());
        self::assertSame(1, $unitOfWork->transactions);
        self::assertSame($grant, $passwordResetGrantRepository->getById($grant->getId()));
        self::assertSame($grant, $passwordResetGrantRepository->getByDeliveryId($grant->getDelivery()->getId()));
        self::assertSame($user->getId(), $grant->getUserId());
        self::assertSame('ciphertext:reset-once', $grant->getDelivery()->getEncryptedMaterial()?->reveal());
        self::assertSame('alice@example.test', $grant->getDelivery()->getEmail()->canonical());
        self::assertSame($grant->getExpiresAt(), $grant->getDelivery()->getExpiresAt());
        $audit = $auditEvidenceRepository->all()[0];
        self::assertSame(AuditAction::PASSWORD_RESET_REQUESTED, $audit->action());
        self::assertInstanceOf(AnonymousId::class, $audit->actorId());
        self::assertSame($user->getId(), $audit->getSubject()->getId());
        self::assertEquals(new DateTimeImmutable('2026-08-20T12:00:00+00:00'), $audit->getOccurredAt());
        $facts = $audit->getFacts();
        self::assertInstanceOf(AuditGrantOutcome::class, $facts);
        self::assertNull($facts->getBefore());
        self::assertNull($facts->getReplacedPredecessor());
        self::assertEquals(AuditGrantState::fromGrant($grant), $facts->getAfter());
        self::assertInstanceOf(PasswordResetRequested::class, $eventDispatcher->events()[0]);
        self::assertSame($grant->getDelivery()->getId(), $eventDispatcher->events()[0]->getPasswordResetDeliveryId());
    }

    public function test_reissue_atomically_terminalizes_the_predecessor_and_inserts_the_successor(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $userRepository = new InMemoryUserRepository();
        $user = UserFixture::withState('alice@example.test', UserState::ACTIVE);
        $userRepository->add($user);
        $passwordResetGrantRepository = new InMemoryPasswordResetGrants($unitOfWork);
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork);
        $eventDispatcher = new InMemoryEventDispatcher();
        $command = CommandMessage::create(new RequestPasswordReset(EmailAddress::fromString('alice@example.test')));
        $this->handler(
            $userRepository,
            $passwordResetGrantRepository,
            $auditEvidenceRepository,
            $unitOfWork,
            $eventDispatcher,
            'reset-old'
        )->handle(
            $command
        );
        $predecessor = $passwordResetGrantRepository->all()[0];

        $this->handler(
            $userRepository,
            $passwordResetGrantRepository,
            $auditEvidenceRepository,
            $unitOfWork,
            $eventDispatcher,
            'reset-new',
            '2026-08-20T12:15:00+00:00'
        )->handle($command);

        self::assertCount(2, $passwordResetGrantRepository->all());
        self::assertTrue($passwordResetGrantRepository->all()[0]->isRevoked());
        self::assertFalse($passwordResetGrantRepository->all()[0]->getDelivery()->hasRecoverableMaterial());
        self::assertTrue($passwordResetGrantRepository->all()[1]->isIssued());
        self::assertSame(
            'ciphertext:reset-new',
            $passwordResetGrantRepository->all()[1]->getDelivery()->getEncryptedMaterial()?->reveal()
        );
        self::assertFalse($passwordResetGrantRepository->all()[1]->getId()->equals($predecessor->getId()));
        self::assertCount(2, $auditEvidenceRepository->all());
        $audit = $auditEvidenceRepository->all()[1];
        $facts = $audit->getFacts();
        self::assertInstanceOf(AuditGrantOutcome::class, $facts);
        self::assertEquals(AuditGrantState::fromGrant($predecessor), $facts->getBefore());
        self::assertEquals(
            AuditGrantState::fromGrant($passwordResetGrantRepository->all()[0]),
            $facts->getReplacedPredecessor()
        );
        self::assertEquals(AuditGrantState::fromGrant($passwordResetGrantRepository->all()[1]), $facts->getAfter());
        self::assertEquals($command->id(), $audit->getCorrelation()?->getMessageId());
        self::assertCount(2, $eventDispatcher->events());
    }

    public function test_fresh_authority_appends_after_a_fully_terminal_generation(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $userRepository = new InMemoryUserRepository();
        $user = UserFixture::withState('alice@example.test', UserState::ACTIVE);
        $userRepository->add($user);
        $passwordResetGrantRepository = new InMemoryPasswordResetGrants($unitOfWork);
        $issued = $this->grant($user->getId(), 'old');
        self::assertTrue($passwordResetGrantRepository->add($issued));
        $terminal = $issued->consume(
            new DateTimeImmutable('2026-08-20T12:05:00+00:00')
        )->invalidateDelivery();
        self::assertTrue($passwordResetGrantRepository->replace($issued, $terminal));

        $this->handler(
            $userRepository,
            $passwordResetGrantRepository,
            new InMemoryAuditEvidenceRepository($unitOfWork),
            $unitOfWork,
            new InMemoryEventDispatcher(),
            'new'
        )->handle(CommandMessage::create(new RequestPasswordReset(EmailAddress::fromString('alice@example.test'))));

        self::assertSame($terminal, $passwordResetGrantRepository->all()[0]);
        self::assertTrue($passwordResetGrantRepository->all()[1]->isIssued());
    }

    public function test_terminal_authority_with_recoverable_delivery_is_terminalized_before_successor_insertion(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $userRepository = new InMemoryUserRepository();
        $user = UserFixture::withState('alice@example.test', UserState::ACTIVE);
        $userRepository->add($user);
        $passwordResetGrantRepository = new InMemoryPasswordResetGrants($unitOfWork);
        $issued = $this->grant($user->getId(), 'old');
        self::assertTrue($passwordResetGrantRepository->add($issued));
        $consumed = $issued->consume(
            new DateTimeImmutable('2026-08-20T12:05:00+00:00')
        );
        self::assertTrue($passwordResetGrantRepository->replace($issued, $consumed));

        $this->handler(
            $userRepository,
            $passwordResetGrantRepository,
            new InMemoryAuditEvidenceRepository($unitOfWork),
            $unitOfWork,
            new InMemoryEventDispatcher(),
            'new'
        )->handle(CommandMessage::create(new RequestPasswordReset(EmailAddress::fromString('alice@example.test'))));

        self::assertTrue($passwordResetGrantRepository->all()[0]->isConsumed());
        self::assertFalse($passwordResetGrantRepository->all()[0]->getDelivery()->hasRecoverableMaterial());
    }

    #[DataProvider('genericOutcomeStates')]
    public function test_unknown_and_ineligible_requests_have_the_same_no_work_outcome(?UserState $state): void
    {
        $userRepository = new InMemoryUserRepository();
        if ($state instanceof UserState) {
            $userRepository->add(UserFixture::withState('alice@example.test', $state));
        }

        $unitOfWork = new InMemoryUnitOfWork();
        $passwordResetGrantRepository = new InMemoryPasswordResetGrants($unitOfWork);
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork);
        $eventDispatcher = new InMemoryEventDispatcher();

        $this->handler(
            $userRepository,
            $passwordResetGrantRepository,
            $auditEvidenceRepository,
            $unitOfWork,
            $eventDispatcher
        )->handle(
            CommandMessage::create(new RequestPasswordReset(EmailAddress::fromString('alice@example.test')))
        );

        self::assertSame([], $passwordResetGrantRepository->all());
        self::assertSame([], $auditEvidenceRepository->all());
        self::assertSame([], $eventDispatcher->events());
        self::assertSame(1, $unitOfWork->transactions);
    }

    public function test_a_lost_initial_or_reissue_cas_is_mutation_free_and_publishes_failure(): void
    {
        foreach ([false, true] as $reissue) {
            $unitOfWork = new InMemoryUnitOfWork();
            $userRepository = new InMemoryUserRepository();
            $user = UserFixture::withState('alice@example.test', UserState::ACTIVE);
            $userRepository->add($user);
            $passwordResetGrantRepository = new InMemoryPasswordResetGrants(
                $unitOfWork,
                replaceWithSuccessorSucceeds: false,
                addSucceeds: $reissue
            );
            if ($reissue) {
                $passwordResetGrantRepository->add($this->grant($user->getId(), 'old'));
            }

            $before = $passwordResetGrantRepository->all();
            $eventDispatcher = new InMemoryEventDispatcher();

            try {
                $this->handler(
                    $userRepository,
                    $passwordResetGrantRepository,
                    new InMemoryAuditEvidenceRepository($unitOfWork),
                    $unitOfWork,
                    $eventDispatcher
                )->handle(CommandMessage::create(new RequestPasswordReset(
                    EmailAddress::fromString('alice@example.test')
                )));
                self::fail('A lost aggregate CAS succeeded.');
            } catch (StateConflict) {
                self::assertSame($before, $passwordResetGrantRepository->all());
                self::assertInstanceOf(CommandFailedEvent::class, $eventDispatcher->events()[0]);
            }
        }
    }

    public function test_a_lost_terminal_append_is_mutation_free(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $userRepository = new InMemoryUserRepository();
        $user = UserFixture::withState('alice@example.test', UserState::ACTIVE);
        $userRepository->add($user);
        $passwordResetGrantRepository = new InMemoryPasswordResetGrants(
            $unitOfWork,
            appendAfterTerminalSucceeds: false
        );
        $issued = $this->grant($user->getId(), 'old');
        self::assertTrue($passwordResetGrantRepository->add($issued));
        $terminal = $issued->revoke(new DateTimeImmutable())->invalidateDelivery();
        self::assertTrue($passwordResetGrantRepository->replace($issued, $terminal));
        $eventDispatcher = new InMemoryEventDispatcher();

        $this->expectException(StateConflict::class);
        try {
            $this->handler(
                $userRepository,
                $passwordResetGrantRepository,
                new InMemoryAuditEvidenceRepository($unitOfWork),
                $unitOfWork,
                $eventDispatcher
            )->handle(CommandMessage::create(new RequestPasswordReset(EmailAddress::fromString('alice@example.test'))));
        } finally {
            self::assertSame([$terminal], $passwordResetGrantRepository->all());
            self::assertInstanceOf(CommandFailedEvent::class, $eventDispatcher->events()[0]);
        }
    }

    public function test_audit_failure_rolls_back_the_complete_aggregate_write(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $userRepository = new InMemoryUserRepository();
        $userRepository->add(UserFixture::withState('alice@example.test', UserState::ACTIVE));

        $passwordResetGrantRepository = new InMemoryPasswordResetGrants($unitOfWork);
        $eventDispatcher = new InMemoryEventDispatcher();

        $this->expectException(RuntimeException::class);
        try {
            $this->handler(
                $userRepository,
                $passwordResetGrantRepository,
                new InMemoryAuditEvidenceRepository($unitOfWork, failAfterSave: true),
                $unitOfWork,
                $eventDispatcher
            )->handle(CommandMessage::create(new RequestPasswordReset(EmailAddress::fromString('alice@example.test'))));
        } finally {
            self::assertSame([], $passwordResetGrantRepository->all());
            self::assertInstanceOf(CommandFailedEvent::class, $eventDispatcher->events()[0]);
        }
    }

    private function grant(UserId $userId, string $credential): PasswordResetGrant
    {
        return PasswordResetGrant::issue(
            $userId,
            PasswordResetCredential::fromString($credential),
            new DateTimeImmutable('2026-08-20T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-20T13:00:00+00:00'),
            EmailAddress::fromString('alice@example.test'),
            'ciphertext:'.$credential,
            new RecoveryAuthority(1, 0)
        );
    }

    private function handler(
        InMemoryUserRepository $userRepository,
        InMemoryPasswordResetGrants $passwordResetGrantRepository,
        InMemoryAuditEvidenceRepository $auditEvidenceRepository,
        InMemoryUnitOfWork $unitOfWork,
        InMemoryEventDispatcher $eventDispatcher,
        string $credential = 'reset-once',
        string $now = '2026-08-20T12:00:00+00:00'
    ): RequestPasswordResetHandler {
        return new RequestPasswordResetHandler(
            $userRepository,
            $passwordResetGrantRepository,
            $unitOfWork,
            new FixedPasswordResetCredentialGenerator($credential),
            new PrefixPasswordResetDeliveryCipher(),
            new FixedClock($now),
            $eventDispatcher,
            $auditEvidenceRepository
        );
    }
}
