<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\PasswordResetGrant\CommandHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\PasswordResetGrant\CommandHandler\RequestPasswordResetHandler;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\Command\RequestPasswordReset;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\Event\PasswordResetRequested;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetCredential;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrant;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use Fight\Test\AccessControl\Application\AccessControl\Audit\Repository\InMemoryAuditEvidenceRepository;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\PasswordResetGrant\Repository\InMemoryPasswordResetGrants;
use Fight\Test\AccessControl\Application\AccessControl\PasswordResetGrant\Service\FixedPasswordResetClock;
use Fight\Test\AccessControl\Application\AccessControl\PasswordResetGrant\Service\FixedPasswordResetCredentialGenerator;
use Fight\Test\AccessControl\Application\AccessControl\PasswordResetGrant\Service\PrefixPasswordResetDeliveryCipher;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryUserRepository;
use Fight\Test\AccessControl\Domain\AccessControl\User\UserFixture;
use LogicException;
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
        $users = new InMemoryUserRepository();
        $user = UserFixture::withState('alice@example.test', UserState::ACTIVE);
        $users->add($user);
        $repository = new InMemoryPasswordResetGrants($unitOfWork);
        $audit = new InMemoryAuditEvidenceRepository($unitOfWork);
        $events = new InMemoryEventDispatcher(static function () use ($audit, $repository, $unitOfWork): void {
            self::assertTrue($unitOfWork->transactionCompleted);
            self::assertCount(1, $repository->all());
            self::assertCount(1, $audit->all());
        });

        $this->handler($users, $repository, $audit, $unitOfWork, $events)->handle(
            CommandMessage::create(new RequestPasswordReset(EmailAddress::fromString('ALICE@example.test')))
        );

        $grant = $repository->all()[0];
        self::assertSame(RequestPasswordReset::class, RequestPasswordResetHandler::commandRegistration());
        self::assertSame(1, $unitOfWork->transactions);
        self::assertSame($grant, $repository->getById($grant->getId()));
        self::assertSame($grant, $repository->getByDeliveryId($grant->getDelivery()->getId()));
        self::assertSame($user->getId(), $grant->getUserId());
        self::assertSame('ciphertext:reset-once', $grant->getDelivery()->getCiphertext());
        self::assertSame('alice@example.test', $grant->getDelivery()->getEmail()->canonical());
        self::assertSame($grant->getExpiresAt(), $grant->getDelivery()->getExpiresAt());
        self::assertSame('user.password_reset_requested', $audit->all()[0]->action());
        self::assertInstanceOf(PasswordResetRequested::class, $events->events()[0]);
        self::assertSame($grant->getDelivery()->getId(), $events->events()[0]->getPasswordResetDeliveryId());
    }

    public function test_reissue_atomically_terminalizes_the_predecessor_and_inserts_the_successor(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $users = new InMemoryUserRepository();
        $user = UserFixture::withState('alice@example.test', UserState::ACTIVE);
        $users->add($user);
        $repository = new InMemoryPasswordResetGrants($unitOfWork);
        $audit = new InMemoryAuditEvidenceRepository($unitOfWork);
        $events = new InMemoryEventDispatcher();
        $command = CommandMessage::create(new RequestPasswordReset(EmailAddress::fromString('alice@example.test')));
        $this->handler($users, $repository, $audit, $unitOfWork, $events, 'reset-old')->handle($command);
        $predecessor = $repository->all()[0];

        $this->handler(
            $users,
            $repository,
            $audit,
            $unitOfWork,
            $events,
            'reset-new',
            '2026-08-20T12:15:00+00:00'
        )->handle($command);

        self::assertCount(2, $repository->all());
        self::assertTrue($repository->all()[0]->isRevoked());
        self::assertFalse($repository->all()[0]->getDelivery()->isRecoverable());
        self::assertTrue($repository->all()[1]->isIssued());
        self::assertSame('ciphertext:reset-new', $repository->all()[1]->getDelivery()->getCiphertext());
        self::assertFalse($repository->all()[1]->getId()->equals($predecessor->getId()));
        self::assertCount(2, $audit->all());
        self::assertCount(2, $events->events());
    }

    public function test_fresh_authority_appends_after_a_fully_terminal_generation(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $users = new InMemoryUserRepository();
        $user = UserFixture::withState('alice@example.test', UserState::ACTIVE);
        $users->add($user);
        $repository = new InMemoryPasswordResetGrants($unitOfWork);
        $issued = $this->grant($user->getId(), 'old');
        self::assertTrue($repository->add($issued));
        $terminal = $issued->consume(
            new DateTimeImmutable('2026-08-20T12:05:00+00:00')
        )->invalidateDelivery();
        self::assertTrue($repository->replace($issued, $terminal));

        $this->handler(
            $users,
            $repository,
            new InMemoryAuditEvidenceRepository($unitOfWork),
            $unitOfWork,
            new InMemoryEventDispatcher(),
            'new'
        )->handle(CommandMessage::create(new RequestPasswordReset(EmailAddress::fromString('alice@example.test'))));

        self::assertSame($terminal, $repository->all()[0]);
        self::assertTrue($repository->all()[1]->isIssued());
    }

    public function test_terminal_authority_with_recoverable_delivery_is_terminalized_before_successor_insertion(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $users = new InMemoryUserRepository();
        $user = UserFixture::withState('alice@example.test', UserState::ACTIVE);
        $users->add($user);
        $repository = new InMemoryPasswordResetGrants($unitOfWork);
        $issued = $this->grant($user->getId(), 'old');
        self::assertTrue($repository->add($issued));
        $consumed = $issued->consume(
            new DateTimeImmutable('2026-08-20T12:05:00+00:00')
        );
        self::assertTrue($repository->replace($issued, $consumed));

        $this->handler(
            $users,
            $repository,
            new InMemoryAuditEvidenceRepository($unitOfWork),
            $unitOfWork,
            new InMemoryEventDispatcher(),
            'new'
        )->handle(CommandMessage::create(new RequestPasswordReset(EmailAddress::fromString('alice@example.test'))));

        self::assertTrue($repository->all()[0]->isConsumed());
        self::assertFalse($repository->all()[0]->getDelivery()->isRecoverable());
    }

    #[DataProvider('genericOutcomeStates')]
    public function test_unknown_and_ineligible_requests_have_the_same_no_work_outcome(?UserState $state): void
    {
        $users = new InMemoryUserRepository();
        if ($state instanceof UserState) {
            $users->add(UserFixture::withState('alice@example.test', $state));
        }

        $unitOfWork = new InMemoryUnitOfWork();
        $repository = new InMemoryPasswordResetGrants($unitOfWork);
        $audit = new InMemoryAuditEvidenceRepository($unitOfWork);
        $events = new InMemoryEventDispatcher();

        $this->handler($users, $repository, $audit, $unitOfWork, $events)->handle(
            CommandMessage::create(new RequestPasswordReset(EmailAddress::fromString('alice@example.test')))
        );

        self::assertSame([], $repository->all());
        self::assertSame([], $audit->all());
        self::assertSame([], $events->events());
        self::assertSame(1, $unitOfWork->transactions);
    }

    public function test_a_lost_initial_or_reissue_cas_is_mutation_free_and_publishes_failure(): void
    {
        foreach ([false, true] as $reissue) {
            $unitOfWork = new InMemoryUnitOfWork();
            $users = new InMemoryUserRepository();
            $user = UserFixture::withState('alice@example.test', UserState::ACTIVE);
            $users->add($user);
            $repository = new InMemoryPasswordResetGrants(
                $unitOfWork,
                replaceWithSuccessorSucceeds: false,
                addSucceeds: $reissue
            );
            if ($reissue) {
                $repository->add($this->grant($user->getId(), 'old'));
            }

            $before = $repository->all();
            $events = new InMemoryEventDispatcher();

            try {
                $this->handler(
                    $users,
                    $repository,
                    new InMemoryAuditEvidenceRepository($unitOfWork),
                    $unitOfWork,
                    $events
                )->handle(CommandMessage::create(new RequestPasswordReset(
                    EmailAddress::fromString('alice@example.test')
                )));
                self::fail('A lost aggregate CAS succeeded.');
            } catch (LogicException) {
                self::assertSame($before, $repository->all());
                self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
            }
        }
    }

    public function test_a_lost_terminal_append_is_mutation_free(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $users = new InMemoryUserRepository();
        $user = UserFixture::withState('alice@example.test', UserState::ACTIVE);
        $users->add($user);
        $repository = new InMemoryPasswordResetGrants($unitOfWork, appendAfterTerminalSucceeds: false);
        $issued = $this->grant($user->getId(), 'old');
        self::assertTrue($repository->add($issued));
        $terminal = $issued->revoke(new DateTimeImmutable())->invalidateDelivery();
        self::assertTrue($repository->replace($issued, $terminal));
        $events = new InMemoryEventDispatcher();

        $this->expectException(LogicException::class);
        try {
            $this->handler(
                $users,
                $repository,
                new InMemoryAuditEvidenceRepository($unitOfWork),
                $unitOfWork,
                $events
            )->handle(CommandMessage::create(new RequestPasswordReset(EmailAddress::fromString('alice@example.test'))));
        } finally {
            self::assertSame([$terminal], $repository->all());
            self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
        }
    }

    public function test_audit_failure_rolls_back_the_complete_aggregate_write(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $users = new InMemoryUserRepository();
        $users->add(UserFixture::withState('alice@example.test', UserState::ACTIVE));

        $repository = new InMemoryPasswordResetGrants($unitOfWork);
        $events = new InMemoryEventDispatcher();

        $this->expectException(RuntimeException::class);
        try {
            $this->handler(
                $users,
                $repository,
                new InMemoryAuditEvidenceRepository($unitOfWork, failAfterSave: true),
                $unitOfWork,
                $events
            )->handle(CommandMessage::create(new RequestPasswordReset(EmailAddress::fromString('alice@example.test'))));
        } finally {
            self::assertSame([], $repository->all());
            self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
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
            'ciphertext:'.$credential
        );
    }

    private function handler(
        InMemoryUserRepository $users,
        InMemoryPasswordResetGrants $repository,
        InMemoryAuditEvidenceRepository $audit,
        InMemoryUnitOfWork $unitOfWork,
        InMemoryEventDispatcher $events,
        string $credential = 'reset-once',
        string $now = '2026-08-20T12:00:00+00:00'
    ): RequestPasswordResetHandler {
        return new RequestPasswordResetHandler(
            $users,
            $repository,
            $audit,
            $unitOfWork,
            new FixedPasswordResetCredentialGenerator($credential),
            new PrefixPasswordResetDeliveryCipher(),
            new FixedPasswordResetClock($now),
            $events
        );
    }
}
