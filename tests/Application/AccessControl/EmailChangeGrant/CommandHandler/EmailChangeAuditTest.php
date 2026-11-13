<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\EmailChangeGrant\CommandHandler;

use Closure;
use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\EmailChangeGrant\CommandHandler\CancelEmailChangeHandler;
use Fight\AccessControl\Application\AccessControl\EmailChangeGrant\CommandHandler\DeliverEmailChangeHandler;
use Fight\AccessControl\Application\AccessControl\EmailChangeGrant\CommandHandler\ExpireEmailChangeHandler;
use Fight\AccessControl\Application\AccessControl\EmailChangeGrant\CommandHandler\RequestEmailChangeHandler;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditGrantState;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditUserOutcome;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditUserRevisions;
use Fight\AccessControl\Domain\AccessControl\Audit\SystemId;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryStatus;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Command\CancelEmailChange;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Command\DeliverEmailChange;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Command\ExpireEmailChange;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Command\RequestEmailChange;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeCredential;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeGrant;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use Fight\Test\AccessControl\Application\AccessControl\Audit\Repository\InMemoryAuditEvidenceRepository;
use Fight\Test\AccessControl\Application\AccessControl\CredentialDelivery\Service as DeliveryService;
use Fight\Test\AccessControl\Application\AccessControl\EmailChangeGrant\Repository\InMemoryEmailChangeGrantRepository;
use Fight\Test\AccessControl\Application\AccessControl\EmailChangeGrant\Service as EmailService;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryUserRepository;
use Fight\Test\AccessControl\Domain\AccessControl\User\UserFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(RequestEmailChangeHandler::class)]
#[CoversClass(CancelEmailChangeHandler::class)]
#[CoversClass(DeliverEmailChangeHandler::class)]
#[CoversClass(ExpireEmailChangeHandler::class)]
#[CoversClass(AuditEvidence::class)]
#[CoversClass(AuditUserRevisions::class)]
final class EmailChangeAuditTest extends TestCase
{
    public static function outcomes(): iterable
    {
        foreach (['request', 'cancel', 'delivery', 'expiry'] as $operation) {
            foreach ([false, true] as $administrative) {
                yield $operation.' '.(int) $administrative => [$operation, $administrative];
            }
        }
    }

    #[DataProvider('outcomes')]
    public function test_enabled_outcomes_retain_exact_provenance_relationships_and_correlation(
        string $operation,
        bool $administrative
    ): void {
        $fixture = $this->fixture($operation, $administrative);
        $beforeUser = $fixture->userRepository->getById($fixture->userId);
        $beforeGrant = $fixture->emailChangeGrantRepository->getLatestByUserId($fixture->userId);
        $fixture->handler->handle($fixture->message);

        self::assertCount(1, $fixture->auditEvidenceRepository->all());
        $evidence = $fixture->auditEvidenceRepository->all()[0];
        self::assertEquals($fixture->message->payload()->getActorId(), $evidence->actorId());
        self::assertEquals($fixture->userId, $evidence->getSubject()->getId());
        $payload = $evidence->toArray();
        self::assertSame($fixture->message->id()->toString(), $payload['correlation']['message_id']);
        self::assertSame('2026-08-22T13:00:00.000000+00:00', $payload['occurred_at']);
        self::assertSame(match ($operation) {
            'request' => 'user.email_change_requested',
            'cancel' => 'user.email_change_cancelled',
            'delivery' => 'user.email_change_delivery.confirmed',
            'expiry' => 'user.email_change_expired'
        }, $payload['action']);
        self::assertEquals($evidence, AuditEvidence::fromArray($payload));
        foreach (['old@example.test', 'new@example.test', 'audit-secret', 'ciphertext', 'credential_hash'] as $secret) {
            self::assertStringNotContainsString($secret, json_encode($payload, JSON_THROW_ON_ERROR));
        }

        $afterUser = $fixture->userRepository->getById($fixture->userId);
        $afterGrant = $fixture->emailChangeGrantRepository->getLatestByUserId($fixture->userId);
        $facts = $evidence->getFacts();
        self::assertEquals(
            AuditGrantState::fromGrant($afterGrant),
            $facts instanceof AuditUserOutcome ? $facts->getGrantAfter() : $facts->getAfter()
        );
        if ($facts instanceof AuditUserOutcome) {
            self::assertEquals(AuditUserRevisions::fromUser($beforeUser), $facts->getBefore());
            self::assertEquals(AuditUserRevisions::fromUser($afterUser), $facts->getAfter());
            self::assertEquals(
                $beforeGrant === null ? null : AuditGrantState::fromGrant($beforeGrant),
                $facts->getGrantBefore()
            );
        }

        self::assertFalse($fixture->unitOfWork->transactionActive);
        self::assertTrue($fixture->unitOfWork->transactionCompleted);
    }

    public static function disabledOutcomes(): iterable
    {
        foreach (self::outcomes() as $name => [$operation, $administrative]) {
            foreach (['null', 'omitted'] as $composition) {
                yield $name.' '.$composition => [$operation, $administrative, $composition];
            }
        }
    }

    #[DataProvider('disabledOutcomes')]
    public function test_null_and_omitted_storage_intentionally_disable_evidence(
        string $operation,
        bool $administrative,
        string $composition
    ): void {
        $fixture = $this->fixture($operation, $administrative, $composition);
        $fixture->handler->handle($fixture->message);

        self::assertSame([], $fixture->auditEvidenceRepository->all());
        self::assertTrue($fixture->unitOfWork->transactionCompleted);
        self::assertCount(1, $fixture->eventDispatcher->events());
        self::assertFalse($fixture->eventDispatcher->events()[0] instanceof CommandFailedEvent);
    }

    #[DataProvider('outcomes')]
    public function test_late_audit_failure_rolls_back_only_the_outcome_not_an_external_send(
        string $operation,
        bool $administrative
    ): void {
        $fixture = $this->fixture($operation, $administrative, failAudit: true);
        $before = $fixture->userRepository->getById($fixture->userId);
        try {
            $fixture->handler->handle($fixture->message);
            self::fail('Required audit failure was ignored.');
        } catch (RuntimeException $runtimeException) {
            self::assertSame($fixture->auditEvidenceRepository->failure(), $runtimeException);
        }

        self::assertEquals($before, $fixture->userRepository->getById($fixture->userId));
        self::assertSame([], $fixture->auditEvidenceRepository->all());
        $grant = $fixture->emailChangeGrantRepository->getLatestByUserId($fixture->userId);
        if ($operation === 'request') {
            self::assertNull($grant);
        } else {
            self::assertTrue($grant->isIssued());
            self::assertSame(
                $operation === 'delivery' ? CredentialDeliveryStatus::CLAIMED : CredentialDeliveryStatus::PENDING,
                $grant->getDelivery()->getStatus()
            );
        }

        self::assertCount((int) ($operation === 'delivery'), $fixture->provider->invocations());
        self::assertInstanceOf(CommandFailedEvent::class, $fixture->eventDispatcher->events()[0]);
    }

    #[DataProvider('outcomes')]
    public function test_publication_failure_and_lost_commit_acknowledgment_retain_committed_evidence(
        string $operation,
        bool $administrative
    ): void {
        foreach (['publication', 'commit acknowledgment'] as $phase) {
            $primary = new RuntimeException('Synthetic primary fault.');
            $onTrigger = null;
            if ($phase === 'publication') {
                $onTrigger = static function ($event) use ($primary): void {
                    if ($event instanceof CommandFailedEvent) {
                        throw new RuntimeException('Secondary notification fault.');
                    }

                    throw $primary;
                };
            }

            $fixture = $this->fixture($operation, $administrative, onTrigger: $onTrigger);
            if ($phase === 'commit acknowledgment') {
                $fixture->unitOfWork->afterCommit = static function () use ($fixture, $operation, $primary): void {
                    if ($operation !== 'delivery' || $fixture->unitOfWork->transactions === 2) {
                        throw $primary;
                    }
                };
            }

            try {
                $fixture->handler->handle($fixture->message);
                self::fail('The ordinary primary fault was suppressed.');
            } catch (RuntimeException $exception) {
                self::assertSame($primary, $exception);
            }

            self::assertCount(1, $fixture->auditEvidenceRepository->all());
            self::assertFalse($fixture->unitOfWork->transactionActive);
        }
    }

    public function test_early_and_repeated_expiry_never_duplicate_outcome_evidence(): void
    {
        $fixture = $this->fixture('expiry');
        $command = $fixture->message->payload();
        $fixture->handler->handle(CommandMessage::create(new ExpireEmailChange(
            $command->getActorId(),
            $command->getUserId(),
            $command->getEmailChangeGrantId(),
            $command->getOccurredAt()->modify('-1 microsecond')
        )));
        self::assertSame([], $fixture->auditEvidenceRepository->all());
        $fixture->handler->handle($fixture->message);
        $fixture->handler->handle($fixture->message);
        self::assertCount(1, $fixture->auditEvidenceRepository->all());
    }

    private function fixture(
        string $operation,
        bool $administrative = false,
        string $composition = 'enabled',
        bool $failAudit = false,
        ?Closure $onTrigger = null
    ): object {
        $unitOfWork = new InMemoryUnitOfWork();
        $userRepository = new InMemoryUserRepository($unitOfWork);
        $user = UserFixture::withState('old@example.test', UserState::ACTIVE);
        $userRepository->add($user);
        $emailChangeGrantRepository = new InMemoryEmailChangeGrantRepository($unitOfWork);
        $at = new DateTimeImmutable('2026-08-22T13:00:00+00:00');
        $grant = null;
        if ($operation !== 'request') {
            $reserved = clone $user;
            $reserved->requestEmailChange(EmailAddress::fromString('new@example.test'), $at);
            self::assertTrue($userRepository->replaceEmailChangeReservation($user, $reserved));
            $grant = EmailChangeGrant::issue(
                $user->getId(),
                EmailChangeCredential::fromString('audit-secret'),
                $at->modify('-1 hour'),
                $operation === 'expiry' ? $at : $at->modify('+1 hour'),
                EmailAddress::fromString('new@example.test'),
                'ciphertext:audit-secret',
                $reserved->getEmailChangeReservationRevision()
            );
            self::assertTrue($emailChangeGrantRepository->add($grant));
        }

        $actorId = $administrative ? UserId::generate() : $user->getId();
        $command = match ($operation) {
            'request' => new RequestEmailChange($actorId, $user->getId(), EmailAddress::fromString('new@example.test')),
            'cancel' => new CancelEmailChange($actorId, $user->getId()),
            'delivery' => new DeliverEmailChange($actorId, $user->getId(), $grant->getDelivery()->getId()),
            'expiry' => new ExpireEmailChange(
                $administrative ? $actorId : SystemId::generate(),
                $user->getId(),
                $grant->getId(),
                $at
            )
        };
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork, failAfterSave: $failAudit);
        $eventDispatcher = new InMemoryEventDispatcher($onTrigger);
        $provider = new DeliveryService\RecordingCredentialDeliveryProvider(
            onDeliver: static function () use ($unitOfWork): void {
                self::assertFalse($unitOfWork->transactionActive);
            }
        );
        $clock = new FixedClock($at->format(DATE_ATOM));
        $arguments = match ($operation) {
            'request' => [$userRepository, $emailChangeGrantRepository,
                new EmailService\FixedEmailChangeAdministrationAuthorization(true), $unitOfWork,
                new EmailService\FixedEmailChangeCredentialGenerator('audit-secret'),
                new EmailService\PrefixEmailChangeDeliveryCipher(),
                $clock, $eventDispatcher],
            'cancel' => [$userRepository, $emailChangeGrantRepository,
                new EmailService\FixedEmailChangeAdministrationAuthorization(true),
                $unitOfWork, $clock, $eventDispatcher],
            'delivery' => [$emailChangeGrantRepository, $unitOfWork, new EmailService\PrefixEmailChangeDeliveryCipher(),
                $provider, $clock, $eventDispatcher],
            'expiry' => [$userRepository, $emailChangeGrantRepository, $unitOfWork, $eventDispatcher]
        };
        if ($composition !== 'omitted') {
            $arguments[] = $composition === 'null' ? null : $auditEvidenceRepository;
        }

        $handler = match ($operation) {
            'request' => new RequestEmailChangeHandler(...$arguments),
            'cancel' => new CancelEmailChangeHandler(...$arguments),
            'delivery' => new DeliverEmailChangeHandler(...$arguments),
            'expiry' => new ExpireEmailChangeHandler(...$arguments)
        };

        return (object) [
            'handler'                    => $handler,
            'message'                    => CommandMessage::create($command),
            'userId'                     => $user->getId(),
            'unitOfWork'                 => $unitOfWork,
            'userRepository'             => $userRepository,
            'emailChangeGrantRepository' => $emailChangeGrantRepository,
            'auditEvidenceRepository'    => $auditEvidenceRepository,
            'eventDispatcher'            => $eventDispatcher,
            'provider'                   => $provider
        ];
    }
}
