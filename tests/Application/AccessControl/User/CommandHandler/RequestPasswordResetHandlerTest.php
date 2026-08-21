<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User\CommandHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\ConfirmPasswordResetDeliveryHandler;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\ExpirePasswordResetDeliveryHandler;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\RequestPasswordResetHandler;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\User\Command\ConfirmPasswordResetDelivery;
use Fight\AccessControl\Domain\AccessControl\User\Command\ExpirePasswordResetDelivery;
use Fight\AccessControl\Domain\AccessControl\User\Command\RequestPasswordReset;
use Fight\AccessControl\Domain\AccessControl\User\Event\PasswordResetRequested;
use Fight\AccessControl\Domain\AccessControl\User\PasswordResetCredential;
use Fight\AccessControl\Domain\AccessControl\User\PasswordResetDelivery;
use Fight\AccessControl\Domain\AccessControl\User\PasswordResetDeliveryId;
use Fight\AccessControl\Domain\AccessControl\User\PasswordResetGrant;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use Fight\Test\AccessControl\Application\AccessControl\Audit\Repository\InMemoryAuditEvidenceRepository;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryPasswordResetDeliveryRepository;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryPasswordResetGrantRepository;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryUserRepository;
use Fight\Test\AccessControl\Application\AccessControl\User\Service\FixedPasswordResetClock;
use Fight\Test\AccessControl\Application\AccessControl\User\Service\FixedPasswordResetCredentialGenerator;
use Fight\Test\AccessControl\Application\AccessControl\User\Service\PrefixPasswordResetDeliveryCipher;
use Fight\Test\AccessControl\Domain\AccessControl\User\UserFixture;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(RequestPasswordResetHandler::class)]
#[CoversClass(PasswordResetDelivery::class)]
#[CoversClass(PasswordResetGrant::class)]
#[CoversClass(AuditEvidence::class)]
#[CoversClass(PasswordResetRequested::class)]
final class RequestPasswordResetHandlerTest extends TestCase
{
    /**
     * @return iterable<string, array{?UserState}>
     */
    public static function genericOutcomeStates(): iterable
    {
        yield 'unknown identity' => [null];
        yield 'pending activation identity' => [UserState::PENDING_ACTIVATION];
        yield 'disabled identity' => [UserState::DISABLED];
        yield 'deleted identity' => [UserState::DELETED];
    }

    /**
     * @return iterable<string, array{bool, bool, string}>
     */
    public static function concurrentReplacementCases(): iterable
    {
        yield 'grant authority changed' => [
            false,
            true,
            'Password-reset authority changed concurrently.',
        ];
        yield 'delivery generation changed' => [
            true,
            false,
            'Password-reset delivery changed concurrently.',
        ];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function terminalDeliveryCases(): iterable
    {
        yield 'confirmed delivery' => ['confirmed'];
        yield 'delivery expired at exact boundary' => ['expired'];
    }

    /**
     * @return iterable<string, array{bool, bool, string}>
     */
    public static function initialCreationConflictCases(): iterable
    {
        yield 'grant add lost' => [
            false,
            true,
            'Password-reset authority changed concurrently.',
        ];
        yield 'delivery add lost after grant add' => [
            true,
            false,
            'Password-reset delivery changed concurrently.',
        ];
    }

    public function test_it_atomically_stages_recovery_for_an_active_user_before_publishing_success(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $userRepository = new InMemoryUserRepository();
        $user = UserFixture::withState('alice@example.test', UserState::ACTIVE);
        $userRepository->add($user);
        $passwordResetGrantRepository = new InMemoryPasswordResetGrantRepository($unitOfWork);
        $passwordResetDeliveryRepository = new InMemoryPasswordResetDeliveryRepository($unitOfWork);
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork);
        $events = new InMemoryEventDispatcher(function () use (
            $auditEvidenceRepository,
            $passwordResetDeliveryRepository,
            $passwordResetGrantRepository,
            $unitOfWork
        ): void {
            self::assertTrue($unitOfWork->transactionCompleted);
            self::assertCount(1, $passwordResetGrantRepository->all());
            self::assertCount(1, $passwordResetDeliveryRepository->all());
            self::assertCount(1, $auditEvidenceRepository->all());
        });
        $handler = new RequestPasswordResetHandler(
            $userRepository,
            $passwordResetGrantRepository,
            $passwordResetDeliveryRepository,
            $auditEvidenceRepository,
            $unitOfWork,
            new FixedPasswordResetCredentialGenerator('reset-once'),
            new PrefixPasswordResetDeliveryCipher(),
            new FixedPasswordResetClock('2026-08-20T12:00:00+00:00'),
            $events
        );

        $handler->handle(CommandMessage::create(new RequestPasswordReset(
            EmailAddress::fromString('ALICE@example.test')
        )));

        $grant = $passwordResetGrantRepository->all()[0];
        $delivery = $passwordResetDeliveryRepository->all()[0];
        $evidence = $auditEvidenceRepository->all()[0];
        $requestedEvent = $events->events()[0];

        self::assertSame(RequestPasswordReset::class, RequestPasswordResetHandler::commandRegistration());
        self::assertSame(1, $unitOfWork->transactions);
        self::assertSame($user->getId(), $grant->getUserId());
        self::assertSame('password_reset', $grant->purpose());
        self::assertSame(
            '05c9d62bb6aa0ab0704c4d5203707b27eacd59b88cc02a64e3c2fee2fb72d890',
            $grant->getCredentialHash()
        );
        self::assertSame('2026-08-20T13:00:00+00:00', $grant->getExpiresAt()->format(DATE_ATOM));
        self::assertSame($user->getId(), $delivery->getUserId());
        self::assertInstanceOf(PasswordResetRequested::class, $requestedEvent);
        self::assertSame($delivery->getId(), $requestedEvent->getPasswordResetDeliveryId());
        self::assertSame('alice@example.test', $delivery->getEmail());
        self::assertSame('ciphertext:reset-once', $delivery->getCiphertext());
        self::assertSame($grant->getExpiresAt(), $delivery->getExpiresAt());
        self::assertSame('anonymous', $evidence->actorId());
        self::assertSame('user.password_reset_requested', $evidence->action());
        self::assertSame($user->getId(), $evidence->userId());
        self::assertSame([], $evidence->context());
        self::assertCount(1, $events->events());
        self::assertInstanceOf(PasswordResetRequested::class, $requestedEvent);
    }

    public function test_it_reads_authoritative_user_eligibility_inside_the_atomic_transaction(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $userRepository = new InMemoryUserRepository(
            beforeGetByEmail: static function () use ($unitOfWork): void {
                self::assertTrue($unitOfWork->transactionActive);
            }
        );
        $userRepository->add(UserFixture::withState('alice@example.test', UserState::ACTIVE));

        $handler = new RequestPasswordResetHandler(
            $userRepository,
            new InMemoryPasswordResetGrantRepository($unitOfWork),
            new InMemoryPasswordResetDeliveryRepository($unitOfWork),
            new InMemoryAuditEvidenceRepository($unitOfWork),
            $unitOfWork,
            new FixedPasswordResetCredentialGenerator('reset-once'),
            new PrefixPasswordResetDeliveryCipher(),
            new FixedPasswordResetClock('2026-08-20T12:00:00+00:00'),
            new InMemoryEventDispatcher()
        );

        $handler->handle(CommandMessage::create(new RequestPasswordReset(
            EmailAddress::fromString('alice@example.test')
        )));

        self::assertSame(1, $unitOfWork->transactions);
    }

    public function test_it_revokes_and_destroys_superseded_reset_material_before_staging_a_fresh_reissue(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $userRepository = new InMemoryUserRepository();
        $user = UserFixture::withState('alice@example.test', UserState::ACTIVE);
        $userRepository->add($user);
        $passwordResetGrantRepository = new InMemoryPasswordResetGrantRepository($unitOfWork);
        $passwordResetDeliveryRepository = new InMemoryPasswordResetDeliveryRepository($unitOfWork);
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork);
        $events = new InMemoryEventDispatcher();
        $commandMessage = CommandMessage::create(new RequestPasswordReset(
            EmailAddress::fromString('alice@example.test')
        ));
        $firstHandler = new RequestPasswordResetHandler(
            $userRepository,
            $passwordResetGrantRepository,
            $passwordResetDeliveryRepository,
            $auditEvidenceRepository,
            $unitOfWork,
            new FixedPasswordResetCredentialGenerator('reset-old'),
            new PrefixPasswordResetDeliveryCipher(),
            new FixedPasswordResetClock('2026-08-20T12:00:00+00:00'),
            $events
        );
        $secondHandler = new RequestPasswordResetHandler(
            $userRepository,
            $passwordResetGrantRepository,
            $passwordResetDeliveryRepository,
            $auditEvidenceRepository,
            $unitOfWork,
            new FixedPasswordResetCredentialGenerator('reset-fresh'),
            new PrefixPasswordResetDeliveryCipher(),
            new FixedPasswordResetClock('2026-08-20T12:15:00+00:00'),
            $events
        );

        $firstHandler->handle($commandMessage);
        $secondHandler->handle($commandMessage);

        $grants = $passwordResetGrantRepository->all();
        $deliveries = $passwordResetDeliveryRepository->all();
        self::assertSame(2, $unitOfWork->transactions);
        self::assertCount(2, $grants);
        self::assertTrue($grants[0]->isRevoked());
        self::assertSame('2026-08-20T12:15:00+00:00', $grants[0]->getRevokedAt()?->format(DATE_ATOM));
        self::assertSame(
            '64ae40655c6dfac4bc2989594e8058d5028ee377b6af7d0f6a9e5dbddaf6b33b',
            $grants[0]->getCredentialHash()
        );
        self::assertSame(
            '0f1cc0c0b5b50acab476279b9b048402d2d4420d26ede82a5661e040a5b97415',
            $grants[1]->getCredentialHash()
        );
        self::assertSame('password_reset', $grants[1]->purpose());
        self::assertSame('2026-08-20T13:15:00+00:00', $grants[1]->getExpiresAt()->format(DATE_ATOM));
        self::assertCount(2, $deliveries);
        self::assertFalse($deliveries[0]->getId()->equals($deliveries[1]->getId()));
        self::assertNull($deliveries[0]->getCiphertext());
        self::assertFalse($deliveries[0]->isRecoverable());
        self::assertSame('ciphertext:reset-fresh', $deliveries[1]->getCiphertext());
        self::assertTrue($deliveries[1]->isRecoverable());
        self::assertSame($grants[1]->getExpiresAt(), $deliveries[1]->getExpiresAt());
        self::assertCount(2, $auditEvidenceRepository->all());
        self::assertCount(2, $events->events());
        $firstRequestedEvent = $events->events()[0];
        $secondRequestedEvent = $events->events()[1];
        self::assertInstanceOf(PasswordResetRequested::class, $firstRequestedEvent);
        self::assertInstanceOf(PasswordResetRequested::class, $secondRequestedEvent);
        self::assertSame($deliveries[0]->getId(), $firstRequestedEvent->getPasswordResetDeliveryId());
        self::assertSame($deliveries[1]->getId(), $secondRequestedEvent->getPasswordResetDeliveryId());

        new ConfirmPasswordResetDeliveryHandler(
            $passwordResetDeliveryRepository,
            $unitOfWork,
            $events
        )->handle(CommandMessage::create(new ConfirmPasswordResetDelivery(
            'password-reset-transport',
            $user->getId(),
            $deliveries[0]->getId(),
            new DateTimeImmutable('2026-08-20T12:20:00+00:00')
        )));
        new ExpirePasswordResetDeliveryHandler(
            $passwordResetDeliveryRepository,
            $unitOfWork,
            $events
        )->handle(CommandMessage::create(new ExpirePasswordResetDelivery(
            'password-reset-expiry',
            $user->getId(),
            $deliveries[0]->getId(),
            new DateTimeImmutable('2026-08-20T14:00:00+00:00')
        )));

        $freshDelivery = $passwordResetDeliveryRepository->getById($deliveries[1]->getId());
        self::assertInstanceOf(PasswordResetDelivery::class, $freshDelivery);
        self::assertSame('ciphertext:reset-fresh', $freshDelivery->getCiphertext());
        self::assertTrue($freshDelivery->isRecoverable());
        self::assertCount(2, $events->events());
    }

    #[DataProvider('concurrentReplacementCases')]
    public function test_it_fails_reissue_when_current_reset_authority_changes_concurrently(
        bool $grantReplaceSucceeds,
        bool $deliveryReplaceSucceeds,
        string $failureMessage
    ): void {
        $unitOfWork = new InMemoryUnitOfWork();
        $userRepository = new InMemoryUserRepository();
        $user = UserFixture::withState('alice@example.test', UserState::ACTIVE);
        $userRepository->add($user);
        $passwordResetGrantRepository = new InMemoryPasswordResetGrantRepository(
            $unitOfWork,
            replaceSucceeds: $grantReplaceSucceeds
        );
        $predecessorGrant = PasswordResetGrant::issue(
            $user->getId(),
            PasswordResetCredential::fromString('reset-old'),
            new DateTimeImmutable('2026-08-20T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-20T13:00:00+00:00')
        );
        $passwordResetGrantRepository->add($predecessorGrant);
        $passwordResetDeliveryRepository = new InMemoryPasswordResetDeliveryRepository(
            $unitOfWork,
            replaceSucceeds: $deliveryReplaceSucceeds
        );
        $predecessorDelivery = PasswordResetDelivery::create(
            PasswordResetDeliveryId::generate(),
            $user->getId(),
            'alice@example.test',
            'ciphertext:reset-old',
            new DateTimeImmutable('2026-08-20T13:00:00+00:00')
        );
        $passwordResetDeliveryRepository->add($predecessorDelivery);
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork);
        $events = new InMemoryEventDispatcher();
        $handler = new RequestPasswordResetHandler(
            $userRepository,
            $passwordResetGrantRepository,
            $passwordResetDeliveryRepository,
            $auditEvidenceRepository,
            $unitOfWork,
            new FixedPasswordResetCredentialGenerator('reset-fresh'),
            new PrefixPasswordResetDeliveryCipher(),
            new FixedPasswordResetClock('2026-08-20T12:15:00+00:00'),
            $events
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage($failureMessage);
        try {
            $handler->handle(CommandMessage::create(new RequestPasswordReset(
                EmailAddress::fromString('alice@example.test')
            )));
        } finally {
            self::assertSame([$predecessorGrant], $passwordResetGrantRepository->all());
            self::assertSame([$predecessorDelivery], $passwordResetDeliveryRepository->all());
            self::assertSame([], $auditEvidenceRepository->all());
            self::assertCount(1, $events->events());
            self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
        }
    }

    public function test_it_rejects_reissue_when_the_generator_reuses_the_predecessor_credential(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $userRepository = new InMemoryUserRepository();
        $user = UserFixture::withState('alice@example.test', UserState::ACTIVE);
        $userRepository->add($user);
        $passwordResetGrantRepository = new InMemoryPasswordResetGrantRepository($unitOfWork);
        $predecessorGrant = PasswordResetGrant::issue(
            $user->getId(),
            PasswordResetCredential::fromString('reset-reused'),
            new DateTimeImmutable('2026-08-20T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-20T13:00:00+00:00')
        );
        $passwordResetGrantRepository->add($predecessorGrant);
        $passwordResetDeliveryRepository = new InMemoryPasswordResetDeliveryRepository($unitOfWork);
        $predecessorDelivery = PasswordResetDelivery::create(
            PasswordResetDeliveryId::generate(),
            $user->getId(),
            'alice@example.test',
            'ciphertext:reset-reused',
            new DateTimeImmutable('2026-08-20T13:00:00+00:00')
        );
        $passwordResetDeliveryRepository->add($predecessorDelivery);
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork);
        $events = new InMemoryEventDispatcher();
        $handler = new RequestPasswordResetHandler(
            $userRepository,
            $passwordResetGrantRepository,
            $passwordResetDeliveryRepository,
            $auditEvidenceRepository,
            $unitOfWork,
            new FixedPasswordResetCredentialGenerator('reset-reused'),
            new PrefixPasswordResetDeliveryCipher(),
            new FixedPasswordResetClock('2026-08-20T12:15:00+00:00'),
            $events
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Password-reset authority changed concurrently.');
        try {
            $handler->handle(CommandMessage::create(new RequestPasswordReset(
                EmailAddress::fromString('alice@example.test')
            )));
        } finally {
            self::assertSame([$predecessorGrant], $passwordResetGrantRepository->all());
            self::assertSame([$predecessorDelivery], $passwordResetDeliveryRepository->all());
            self::assertSame([], $auditEvidenceRepository->all());
            self::assertCount(1, $events->events());
            self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
        }
    }

    public function test_it_rejects_reissue_when_the_generator_reuses_any_historical_credential(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $userRepository = new InMemoryUserRepository();
        $user = UserFixture::withState('alice@example.test', UserState::ACTIVE);
        $userRepository->add($user);
        $passwordResetGrantRepository = new InMemoryPasswordResetGrantRepository($unitOfWork);
        $grantA = PasswordResetGrant::issue(
            $user->getId(),
            PasswordResetCredential::fromString('reset-a'),
            new DateTimeImmutable('2026-08-20T11:00:00+00:00'),
            new DateTimeImmutable('2026-08-20T12:00:00+00:00')
        );
        $grantB = PasswordResetGrant::issue(
            $user->getId(),
            PasswordResetCredential::fromString('reset-b'),
            new DateTimeImmutable('2026-08-20T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-20T13:00:00+00:00')
        );
        $passwordResetGrantRepository->add($grantA);
        self::assertTrue($passwordResetGrantRepository->replace(
            $grantA,
            $grantA->revoke(new DateTimeImmutable('2026-08-20T12:00:00+00:00')),
            $grantB
        ));
        $passwordResetDeliveryRepository = new InMemoryPasswordResetDeliveryRepository($unitOfWork);
        $deliveryA = PasswordResetDelivery::create(
            PasswordResetDeliveryId::generate(),
            $user->getId(),
            'alice@example.test',
            'ciphertext:reset-a',
            new DateTimeImmutable('2026-08-20T12:00:00+00:00')
        );
        $deliveryB = PasswordResetDelivery::create(
            PasswordResetDeliveryId::generate(),
            $user->getId(),
            'alice@example.test',
            'ciphertext:reset-b',
            new DateTimeImmutable('2026-08-20T13:00:00+00:00')
        );
        $passwordResetDeliveryRepository->add($deliveryA);
        self::assertTrue($passwordResetDeliveryRepository->replace(
            $deliveryA,
            $deliveryA->invalidate(),
            $deliveryB
        ));
        $grantHistory = $passwordResetGrantRepository->all();
        $deliveryHistory = $passwordResetDeliveryRepository->all();
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork);
        $events = new InMemoryEventDispatcher();
        $handler = new RequestPasswordResetHandler(
            $userRepository,
            $passwordResetGrantRepository,
            $passwordResetDeliveryRepository,
            $auditEvidenceRepository,
            $unitOfWork,
            new FixedPasswordResetCredentialGenerator('reset-a'),
            new PrefixPasswordResetDeliveryCipher(),
            new FixedPasswordResetClock('2026-08-20T12:15:00+00:00'),
            $events
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Password-reset authority changed concurrently.');
        try {
            $handler->handle(CommandMessage::create(new RequestPasswordReset(
                EmailAddress::fromString('alice@example.test')
            )));
        } finally {
            self::assertSame($grantHistory, $passwordResetGrantRepository->all());
            self::assertSame($deliveryHistory, $passwordResetDeliveryRepository->all());
            self::assertSame([], $auditEvidenceRepository->all());
            self::assertCount(1, $events->events());
            self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
        }
    }

    #[DataProvider('initialCreationConflictCases')]
    public function test_it_rolls_back_empty_history_creation_when_first_authority_loses(
        bool $grantAddSucceeds,
        bool $deliveryAddSucceeds,
        string $failureMessage
    ): void {
        $unitOfWork = new InMemoryUnitOfWork();
        $userRepository = new InMemoryUserRepository();
        $userRepository->add(UserFixture::withState('alice@example.test', UserState::ACTIVE));

        $passwordResetGrantRepository = new InMemoryPasswordResetGrantRepository(
            $unitOfWork,
            addSucceeds: $grantAddSucceeds
        );
        $passwordResetDeliveryRepository = new InMemoryPasswordResetDeliveryRepository(
            $unitOfWork,
            addSucceeds: $deliveryAddSucceeds
        );
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork);
        $events = new InMemoryEventDispatcher();
        $handler = new RequestPasswordResetHandler(
            $userRepository,
            $passwordResetGrantRepository,
            $passwordResetDeliveryRepository,
            $auditEvidenceRepository,
            $unitOfWork,
            new FixedPasswordResetCredentialGenerator('reset-first'),
            new PrefixPasswordResetDeliveryCipher(),
            new FixedPasswordResetClock('2026-08-20T12:00:00+00:00'),
            $events
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage($failureMessage);
        try {
            $handler->handle(CommandMessage::create(new RequestPasswordReset(
                EmailAddress::fromString('alice@example.test')
            )));
        } finally {
            self::assertSame([], $passwordResetGrantRepository->all());
            self::assertSame([], $passwordResetDeliveryRepository->all());
            self::assertSame([], $auditEvidenceRepository->all());
            self::assertCount(1, $events->events());
            self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
        }
    }

    #[DataProvider('terminalDeliveryCases')]
    public function test_it_stages_fresh_work_after_the_latest_delivery_became_terminal(string $terminalState): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $userRepository = new InMemoryUserRepository();
        $user = UserFixture::withState('alice@example.test', UserState::ACTIVE);
        $userRepository->add($user);
        $passwordResetGrantRepository = new InMemoryPasswordResetGrantRepository($unitOfWork);
        $passwordResetDeliveryRepository = new InMemoryPasswordResetDeliveryRepository($unitOfWork);
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork);
        $commandMessage = CommandMessage::create(new RequestPasswordReset(
            EmailAddress::fromString('alice@example.test')
        ));
        new RequestPasswordResetHandler(
            $userRepository,
            $passwordResetGrantRepository,
            $passwordResetDeliveryRepository,
            $auditEvidenceRepository,
            $unitOfWork,
            new FixedPasswordResetCredentialGenerator('reset-old'),
            new PrefixPasswordResetDeliveryCipher(),
            new FixedPasswordResetClock('2026-08-20T12:00:00+00:00'),
            new InMemoryEventDispatcher()
        )->handle($commandMessage);
        $predecessorDelivery = $passwordResetDeliveryRepository->all()[0];
        if ($terminalState === 'confirmed') {
            new ConfirmPasswordResetDeliveryHandler(
                $passwordResetDeliveryRepository,
                $unitOfWork,
                new InMemoryEventDispatcher()
            )->handle(CommandMessage::create(new ConfirmPasswordResetDelivery(
                'password-reset-transport',
                $user->getId(),
                $predecessorDelivery->getId(),
                new DateTimeImmutable('2026-08-20T12:10:00+00:00')
            )));
        } else {
            new ExpirePasswordResetDeliveryHandler(
                $passwordResetDeliveryRepository,
                $unitOfWork,
                new InMemoryEventDispatcher()
            )->handle(CommandMessage::create(new ExpirePasswordResetDelivery(
                'password-reset-expiry',
                $user->getId(),
                $predecessorDelivery->getId(),
                new DateTimeImmutable('2026-08-20T13:00:00+00:00')
            )));
        }

        $events = new InMemoryEventDispatcher(static function () use (
            $auditEvidenceRepository,
            $passwordResetDeliveryRepository,
            $passwordResetGrantRepository,
            $unitOfWork
        ): void {
            self::assertTrue($unitOfWork->transactionCompleted);
            self::assertCount(2, $passwordResetGrantRepository->all());
            self::assertCount(2, $passwordResetDeliveryRepository->all());
            self::assertCount(2, $auditEvidenceRepository->all());
        });
        new RequestPasswordResetHandler(
            $userRepository,
            $passwordResetGrantRepository,
            $passwordResetDeliveryRepository,
            $auditEvidenceRepository,
            $unitOfWork,
            new FixedPasswordResetCredentialGenerator('reset-fresh'),
            new PrefixPasswordResetDeliveryCipher(),
            new FixedPasswordResetClock('2026-08-20T13:15:00+00:00'),
            $events
        )->handle($commandMessage);

        $grants = $passwordResetGrantRepository->all();
        $deliveries = $passwordResetDeliveryRepository->all();
        self::assertSame(3, $unitOfWork->transactions);
        self::assertCount(2, $grants);
        self::assertTrue($grants[0]->isRevoked());
        self::assertTrue($grants[1]->isIssued());
        self::assertSame('2026-08-20T14:15:00+00:00', $grants[1]->getExpiresAt()->format(DATE_ATOM));
        self::assertCount(2, $deliveries);
        self::assertFalse($deliveries[0]->isRecoverable());
        self::assertSame('ciphertext:reset-fresh', $deliveries[1]->getCiphertext());
        self::assertTrue($deliveries[1]->isRecoverable());
        self::assertCount(2, $auditEvidenceRepository->all());
        self::assertCount(1, $events->events());
        self::assertInstanceOf(PasswordResetRequested::class, $events->events()[0]);
        self::assertSame($deliveries[1]->getId(), $events->events()[0]->getPasswordResetDeliveryId());
    }

    public function test_it_rolls_back_fresh_grant_when_terminal_delivery_authority_changes_concurrently(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $userRepository = new InMemoryUserRepository();
        $user = UserFixture::withState('alice@example.test', UserState::ACTIVE);
        $userRepository->add($user);
        $passwordResetGrantRepository = new InMemoryPasswordResetGrantRepository($unitOfWork);
        $predecessorGrant = PasswordResetGrant::issue(
            $user->getId(),
            PasswordResetCredential::fromString('reset-old'),
            new DateTimeImmutable('2026-08-20T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-20T13:00:00+00:00')
        );
        $passwordResetGrantRepository->add($predecessorGrant);
        $passwordResetDeliveryRepository = new InMemoryPasswordResetDeliveryRepository(
            $unitOfWork,
            appendAfterTerminalSucceeds: false
        );
        $terminalDelivery = PasswordResetDelivery::create(
            PasswordResetDeliveryId::generate(),
            $user->getId(),
            'alice@example.test',
            'ciphertext:reset-old',
            new DateTimeImmutable('2026-08-20T13:00:00+00:00')
        )->confirm();
        $passwordResetDeliveryRepository->add($terminalDelivery);
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork);
        $events = new InMemoryEventDispatcher();
        $handler = new RequestPasswordResetHandler(
            $userRepository,
            $passwordResetGrantRepository,
            $passwordResetDeliveryRepository,
            $auditEvidenceRepository,
            $unitOfWork,
            new FixedPasswordResetCredentialGenerator('reset-fresh'),
            new PrefixPasswordResetDeliveryCipher(),
            new FixedPasswordResetClock('2026-08-20T12:15:00+00:00'),
            $events
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Password-reset delivery changed concurrently.');
        try {
            $handler->handle(CommandMessage::create(new RequestPasswordReset(
                EmailAddress::fromString('alice@example.test')
            )));
        } finally {
            self::assertSame([$predecessorGrant], $passwordResetGrantRepository->all());
            self::assertTrue($predecessorGrant->isIssued());
            self::assertSame([$terminalDelivery], $passwordResetDeliveryRepository->all());
            self::assertFalse($terminalDelivery->isRecoverable());
            self::assertSame([], $auditEvidenceRepository->all());
            self::assertCount(1, $events->events());
            self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
        }
    }

    public function test_it_publishes_only_failure_when_terminal_grant_authority_changes_concurrently(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $userRepository = new InMemoryUserRepository();
        $user = UserFixture::withState('alice@example.test', UserState::ACTIVE);
        $userRepository->add($user);
        $issuedGrant = PasswordResetGrant::issue(
            $user->getId(),
            PasswordResetCredential::fromString('reset-old'),
            new DateTimeImmutable('2026-08-20T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-20T13:00:00+00:00')
        );
        $terminalGrant = $issuedGrant->consume(new DateTimeImmutable('2026-08-20T12:05:00+00:00'));
        $passwordResetGrantRepository = new InMemoryPasswordResetGrantRepository(
            $unitOfWork,
            appendAfterTerminalSucceeds: false
        );
        $passwordResetGrantRepository->add($terminalGrant);

        $passwordResetDeliveryRepository = new InMemoryPasswordResetDeliveryRepository($unitOfWork);
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork);
        $events = new InMemoryEventDispatcher();
        $handler = new RequestPasswordResetHandler(
            $userRepository,
            $passwordResetGrantRepository,
            $passwordResetDeliveryRepository,
            $auditEvidenceRepository,
            $unitOfWork,
            new FixedPasswordResetCredentialGenerator('reset-fresh'),
            new PrefixPasswordResetDeliveryCipher(),
            new FixedPasswordResetClock('2026-08-20T12:15:00+00:00'),
            $events
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Password-reset authority changed concurrently.');
        try {
            $handler->handle(CommandMessage::create(new RequestPasswordReset(
                EmailAddress::fromString('alice@example.test')
            )));
        } finally {
            self::assertSame([$terminalGrant], $passwordResetGrantRepository->all());
            self::assertTrue($terminalGrant->isConsumed());
            self::assertSame([], $passwordResetDeliveryRepository->all());
            self::assertSame([], $auditEvidenceRepository->all());
            self::assertCount(1, $events->events());
            self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
        }
    }

    public function test_it_issues_fresh_authority_after_the_latest_grant_was_consumed(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $userRepository = new InMemoryUserRepository();
        $user = UserFixture::withState('alice@example.test', UserState::ACTIVE);
        $userRepository->add($user);
        $passwordResetGrantRepository = new InMemoryPasswordResetGrantRepository($unitOfWork);
        $passwordResetDeliveryRepository = new InMemoryPasswordResetDeliveryRepository($unitOfWork);
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork);
        $events = new InMemoryEventDispatcher();
        $commandMessage = CommandMessage::create(new RequestPasswordReset(
            EmailAddress::fromString('alice@example.test')
        ));
        $firstHandler = new RequestPasswordResetHandler(
            $userRepository,
            $passwordResetGrantRepository,
            $passwordResetDeliveryRepository,
            $auditEvidenceRepository,
            $unitOfWork,
            new FixedPasswordResetCredentialGenerator('reset-old'),
            new PrefixPasswordResetDeliveryCipher(),
            new FixedPasswordResetClock('2026-08-20T12:00:00+00:00'),
            $events
        );
        $secondHandler = new RequestPasswordResetHandler(
            $userRepository,
            $passwordResetGrantRepository,
            $passwordResetDeliveryRepository,
            $auditEvidenceRepository,
            $unitOfWork,
            new FixedPasswordResetCredentialGenerator('reset-fresh'),
            new PrefixPasswordResetDeliveryCipher(),
            new FixedPasswordResetClock('2026-08-20T12:15:00+00:00'),
            $events
        );
        $firstHandler->handle($commandMessage);
        $issuedGrant = $passwordResetGrantRepository->all()[0];
        $consumedGrant = $issuedGrant->consume(new DateTimeImmutable('2026-08-20T12:05:00+00:00'));
        $passwordResetGrantRepository->replaceConsumed($issuedGrant, $consumedGrant);

        $secondHandler->handle($commandMessage);

        $grants = $passwordResetGrantRepository->all();
        $deliveries = $passwordResetDeliveryRepository->all();
        self::assertSame(2, $unitOfWork->transactions);
        self::assertCount(2, $grants);
        self::assertSame($consumedGrant, $grants[0]);
        self::assertTrue($grants[0]->isConsumed());
        self::assertFalse($grants[0]->isRevoked());
        self::assertSame('2026-08-20T12:05:00+00:00', $grants[0]->getConsumedAt()?->format(DATE_ATOM));
        self::assertTrue($grants[1]->isIssued());
        self::assertSame('2026-08-20T13:15:00+00:00', $grants[1]->getExpiresAt()->format(DATE_ATOM));
        self::assertCount(2, $deliveries);
        self::assertFalse($deliveries[0]->isRecoverable());
        self::assertSame('ciphertext:reset-fresh', $deliveries[1]->getCiphertext());
        self::assertCount(2, $auditEvidenceRepository->all());
        self::assertCount(2, $events->events());
        self::assertInstanceOf(PasswordResetRequested::class, $events->events()[1]);
    }

    public function test_it_issues_fresh_authority_after_the_latest_grant_was_revoked(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $userRepository = new InMemoryUserRepository();
        $user = UserFixture::withState('alice@example.test', UserState::ACTIVE);
        $userRepository->add($user);
        $passwordResetGrantRepository = new InMemoryPasswordResetGrantRepository($unitOfWork);
        $issuedGrant = PasswordResetGrant::issue(
            $user->getId(),
            PasswordResetCredential::fromString('reset-old'),
            new DateTimeImmutable('2026-08-20T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-20T13:00:00+00:00')
        );
        $revokedGrant = $issuedGrant->revoke(new DateTimeImmutable('2026-08-20T12:05:00+00:00'));
        $passwordResetGrantRepository->add($revokedGrant);
        $passwordResetDeliveryRepository = new InMemoryPasswordResetDeliveryRepository($unitOfWork);
        $staleDelivery = PasswordResetDelivery::create(
            PasswordResetDeliveryId::generate(),
            $user->getId(),
            'alice@example.test',
            'ciphertext:reset-old',
            new DateTimeImmutable('2026-08-20T13:00:00+00:00')
        );
        $passwordResetDeliveryRepository->add($staleDelivery);
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork);
        $events = new InMemoryEventDispatcher(static function () use (
            $auditEvidenceRepository,
            $passwordResetDeliveryRepository,
            $passwordResetGrantRepository,
            $unitOfWork
        ): void {
            self::assertTrue($unitOfWork->transactionCompleted);
            self::assertCount(2, $passwordResetGrantRepository->all());
            self::assertCount(2, $passwordResetDeliveryRepository->all());
            self::assertCount(1, $auditEvidenceRepository->all());
        });
        $handler = new RequestPasswordResetHandler(
            $userRepository,
            $passwordResetGrantRepository,
            $passwordResetDeliveryRepository,
            $auditEvidenceRepository,
            $unitOfWork,
            new FixedPasswordResetCredentialGenerator('reset-fresh'),
            new PrefixPasswordResetDeliveryCipher(),
            new FixedPasswordResetClock('2026-08-20T12:15:00+00:00'),
            $events
        );

        $handler->handle(CommandMessage::create(new RequestPasswordReset(
            EmailAddress::fromString('alice@example.test')
        )));

        $grants = $passwordResetGrantRepository->all();
        $deliveries = $passwordResetDeliveryRepository->all();
        $evidence = $auditEvidenceRepository->all()[0];
        $event = $events->events()[0];
        self::assertSame(1, $unitOfWork->transactions);
        self::assertSame($revokedGrant, $grants[0]);
        self::assertTrue($grants[0]->isRevoked());
        self::assertFalse($grants[0]->isConsumed());
        self::assertSame('2026-08-20T12:05:00+00:00', $grants[0]->getRevokedAt()?->format(DATE_ATOM));
        self::assertTrue($grants[1]->isIssued());
        self::assertSame('2026-08-20T13:15:00+00:00', $grants[1]->getExpiresAt()->format(DATE_ATOM));
        self::assertSame($staleDelivery->getId(), $deliveries[0]->getId());
        self::assertFalse($deliveries[0]->isRecoverable());
        self::assertFalse($deliveries[0]->getId()->equals($deliveries[1]->getId()));
        self::assertSame('ciphertext:reset-fresh', $deliveries[1]->getCiphertext());
        self::assertTrue($deliveries[1]->isRecoverable());
        self::assertSame('anonymous', $evidence->actorId());
        self::assertSame('user.password_reset_requested', $evidence->action());
        self::assertSame($user->getId(), $evidence->userId());
        self::assertInstanceOf(PasswordResetRequested::class, $event);
        self::assertSame($deliveries[1]->getId(), $event->getPasswordResetDeliveryId());
        self::assertSame('2026-08-20T12:15:00+00:00', $event->getIssuedAt()->format(DATE_ATOM));
    }

    public function test_it_adds_fresh_delivery_when_terminal_grant_history_has_no_delivery_work(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $userRepository = new InMemoryUserRepository();
        $user = UserFixture::withState('alice@example.test', UserState::ACTIVE);
        $userRepository->add($user);
        $passwordResetGrantRepository = new InMemoryPasswordResetGrantRepository($unitOfWork);
        $issuedGrant = PasswordResetGrant::issue(
            $user->getId(),
            PasswordResetCredential::fromString('reset-old'),
            new DateTimeImmutable('2026-08-20T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-20T13:00:00+00:00')
        );
        $consumedGrant = $issuedGrant->consume(new DateTimeImmutable('2026-08-20T12:05:00+00:00'));
        $passwordResetGrantRepository->add($consumedGrant);
        $passwordResetDeliveryRepository = new InMemoryPasswordResetDeliveryRepository($unitOfWork);
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork);
        $events = new InMemoryEventDispatcher();
        $handler = new RequestPasswordResetHandler(
            $userRepository,
            $passwordResetGrantRepository,
            $passwordResetDeliveryRepository,
            $auditEvidenceRepository,
            $unitOfWork,
            new FixedPasswordResetCredentialGenerator('reset-fresh'),
            new PrefixPasswordResetDeliveryCipher(),
            new FixedPasswordResetClock('2026-08-20T12:15:00+00:00'),
            $events
        );

        $handler->handle(CommandMessage::create(new RequestPasswordReset(
            EmailAddress::fromString('alice@example.test')
        )));

        $grants = $passwordResetGrantRepository->all();
        self::assertCount(2, $grants);
        self::assertSame($consumedGrant, $grants[0]);
        self::assertTrue($grants[0]->isConsumed());
        self::assertTrue($grants[1]->isIssued());
        self::assertSame('2026-08-20T13:15:00+00:00', $grants[1]->getExpiresAt()->format(DATE_ATOM));
        self::assertCount(1, $passwordResetDeliveryRepository->all());
        self::assertSame('ciphertext:reset-fresh', $passwordResetDeliveryRepository->all()[0]->getCiphertext());
        self::assertCount(1, $auditEvidenceRepository->all());
        self::assertCount(1, $events->events());
        self::assertInstanceOf(PasswordResetRequested::class, $events->events()[0]);
    }

    public function test_it_restores_terminal_history_when_fresh_authority_cannot_commit(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $userRepository = new InMemoryUserRepository();
        $user = UserFixture::withState('alice@example.test', UserState::ACTIVE);
        $userRepository->add($user);
        $passwordResetGrantRepository = new InMemoryPasswordResetGrantRepository($unitOfWork);
        $issuedGrant = PasswordResetGrant::issue(
            $user->getId(),
            PasswordResetCredential::fromString('reset-old'),
            new DateTimeImmutable('2026-08-20T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-20T13:00:00+00:00')
        );
        $consumedGrant = $issuedGrant->consume(new DateTimeImmutable('2026-08-20T12:05:00+00:00'));
        $passwordResetGrantRepository->add($consumedGrant);
        $passwordResetDeliveryRepository = new InMemoryPasswordResetDeliveryRepository($unitOfWork);
        $passwordResetDelivery = PasswordResetDelivery::create(
            PasswordResetDeliveryId::generate(),
            $user->getId(),
            'alice@example.test',
            'ciphertext:reset-old',
            new DateTimeImmutable('2026-08-20T13:00:00+00:00')
        );
        $passwordResetDeliveryRepository->add($passwordResetDelivery);
        $events = new InMemoryEventDispatcher();
        $handler = new RequestPasswordResetHandler(
            $userRepository,
            $passwordResetGrantRepository,
            $passwordResetDeliveryRepository,
            new InMemoryAuditEvidenceRepository($unitOfWork, failAfterSave: true),
            $unitOfWork,
            new FixedPasswordResetCredentialGenerator('reset-fresh'),
            new PrefixPasswordResetDeliveryCipher(),
            new FixedPasswordResetClock('2026-08-20T12:15:00+00:00'),
            $events
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The audit persistence write failed.');
        try {
            $handler->handle(CommandMessage::create(new RequestPasswordReset(
                EmailAddress::fromString('alice@example.test')
            )));
        } finally {
            self::assertSame([$consumedGrant], $passwordResetGrantRepository->all());
            self::assertTrue($consumedGrant->isConsumed());
            self::assertSame([$passwordResetDelivery], $passwordResetDeliveryRepository->all());
            self::assertSame('ciphertext:reset-old', $passwordResetDelivery->getCiphertext());
            self::assertCount(1, $events->events());
            self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
        }
    }

    public function test_it_restores_superseded_reset_material_when_reissue_cannot_commit(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $userRepository = new InMemoryUserRepository();
        $user = UserFixture::withState('alice@example.test', UserState::ACTIVE);
        $userRepository->add($user);
        $passwordResetGrantRepository = new InMemoryPasswordResetGrantRepository($unitOfWork);
        $passwordResetDeliveryRepository = new InMemoryPasswordResetDeliveryRepository($unitOfWork);
        $commandMessage = CommandMessage::create(new RequestPasswordReset(
            EmailAddress::fromString('alice@example.test')
        ));
        $firstHandler = new RequestPasswordResetHandler(
            $userRepository,
            $passwordResetGrantRepository,
            $passwordResetDeliveryRepository,
            new InMemoryAuditEvidenceRepository($unitOfWork),
            $unitOfWork,
            new FixedPasswordResetCredentialGenerator('reset-old'),
            new PrefixPasswordResetDeliveryCipher(),
            new FixedPasswordResetClock('2026-08-20T12:00:00+00:00'),
            new InMemoryEventDispatcher()
        );
        $firstHandler->handle($commandMessage);

        $predecessorGrant = $passwordResetGrantRepository->all()[0];
        $predecessorDelivery = $passwordResetDeliveryRepository->all()[0];
        $events = new InMemoryEventDispatcher();
        $secondHandler = new RequestPasswordResetHandler(
            $userRepository,
            $passwordResetGrantRepository,
            $passwordResetDeliveryRepository,
            new InMemoryAuditEvidenceRepository($unitOfWork, failAfterSave: true),
            $unitOfWork,
            new FixedPasswordResetCredentialGenerator('reset-fresh'),
            new PrefixPasswordResetDeliveryCipher(),
            new FixedPasswordResetClock('2026-08-20T12:15:00+00:00'),
            $events
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The audit persistence write failed.');
        try {
            $secondHandler->handle($commandMessage);
        } finally {
            self::assertSame([$predecessorGrant], $passwordResetGrantRepository->all());
            self::assertTrue($predecessorGrant->isIssued());
            self::assertSame([$predecessorDelivery], $passwordResetDeliveryRepository->all());
            self::assertSame('ciphertext:reset-old', $predecessorDelivery->getCiphertext());
            self::assertTrue($predecessorDelivery->isRecoverable());
            self::assertCount(1, $events->events());
            self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
        }
    }

    #[DataProvider('genericOutcomeStates')]
    public function test_it_returns_the_same_void_outcome_without_work_for_unknown_or_ineligible_email(
        ?UserState $state
    ): void {
        $userRepository = new InMemoryUserRepository();
        if ($state instanceof UserState) {
            $userRepository->add(UserFixture::withState('alice@example.test', $state));
        }

        $passwordResetGrantRepository = new InMemoryPasswordResetGrantRepository();
        $passwordResetDeliveryRepository = new InMemoryPasswordResetDeliveryRepository();
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository();
        $unitOfWork = new InMemoryUnitOfWork();
        $events = new InMemoryEventDispatcher();
        $handler = new RequestPasswordResetHandler(
            $userRepository,
            $passwordResetGrantRepository,
            $passwordResetDeliveryRepository,
            $auditEvidenceRepository,
            $unitOfWork,
            new FixedPasswordResetCredentialGenerator('reset-once'),
            new PrefixPasswordResetDeliveryCipher(),
            new FixedPasswordResetClock('2026-08-20T12:00:00+00:00'),
            $events
        );

        $handler->handle(CommandMessage::create(new RequestPasswordReset(
            EmailAddress::fromString('ALICE@example.test')
        )));

        self::assertSame(1, $unitOfWork->transactions);
        self::assertTrue($unitOfWork->transactionCompleted);
        self::assertSame([], $passwordResetGrantRepository->all());
        self::assertSame([], $passwordResetDeliveryRepository->all());
        self::assertSame([], $auditEvidenceRepository->all());
        self::assertSame([], $events->events());
    }

    public function test_it_rolls_back_all_recovery_work_and_rethrows_with_command_failure_evidence(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $userRepository = new InMemoryUserRepository();
        $userRepository->add(UserFixture::withState('alice@example.test', UserState::ACTIVE));

        $passwordResetGrantRepository = new InMemoryPasswordResetGrantRepository($unitOfWork);
        $passwordResetDeliveryRepository = new InMemoryPasswordResetDeliveryRepository($unitOfWork);
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork, failAfterSave: true);
        $events = new InMemoryEventDispatcher();
        $handler = new RequestPasswordResetHandler(
            $userRepository,
            $passwordResetGrantRepository,
            $passwordResetDeliveryRepository,
            $auditEvidenceRepository,
            $unitOfWork,
            new FixedPasswordResetCredentialGenerator('reset-once'),
            new PrefixPasswordResetDeliveryCipher(),
            new FixedPasswordResetClock('2026-08-20T12:00:00+00:00'),
            $events
        );

        $this->expectException(RuntimeException::class);
        try {
            $handler->handle(CommandMessage::create(new RequestPasswordReset(
                EmailAddress::fromString('alice@example.test')
            )));
        } finally {
            self::assertSame([], $passwordResetGrantRepository->all());
            self::assertSame([], $passwordResetDeliveryRepository->all());
            self::assertSame([], $auditEvidenceRepository->all());
            self::assertCount(1, $events->events());
            self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
        }
    }
}
