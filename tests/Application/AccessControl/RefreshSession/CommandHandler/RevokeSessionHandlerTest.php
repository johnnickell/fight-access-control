<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\RefreshSession\CommandHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\RefreshSession\CommandHandler\RevokeSessionHandler;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\Command\RevokeSession;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\Event\RefreshSessionRevoked;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\Exception\CurrentRefreshSessionRevocationException;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\Exception\RefreshSessionConflictException;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\Exception\RefreshSessionNotFoundException;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\Exception\SessionAdministrationAuthorizationException;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\Exception\SessionRevocationReasonException;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\Exception\SessionRevocationReasonRequiredException;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshCredential;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSession;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionId;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionRepository;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\SessionRevocationReason;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Fight\Test\AccessControl\Application\AccessControl\Audit\Repository\InMemoryAuditEvidenceRepository;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\RefreshSession\Repository\ControllableRefreshSessionRepository;
use Fight\Test\AccessControl\Application\AccessControl\RefreshSession\Repository\InMemoryRefreshSessionRepository;
use Fight\Test\AccessControl\Application\AccessControl\RefreshSession\Service\FixedSessionAdministrationAuthorization;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FailingClock;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

#[CoversClass(RevokeSessionHandler::class)]
#[CoversClass(RevokeSession::class)]
#[CoversClass(RefreshSessionRevoked::class)]
#[CoversClass(SessionRevocationReason::class)]
#[CoversClass(AuditEvidence::class)]
final class RevokeSessionHandlerTest extends TestCase
{
    public function test_that_an_administrative_revocation_reason_is_trimmed_and_bounded(): void
    {
        self::assertSame('Compromised device', SessionRevocationReason::fromString(
            "\u{00A0}\u{2003}Compromised device\u{3000}"
        )->toString());

        self::assertSame(
            str_repeat("\u{00E9}", 500),
            SessionRevocationReason::fromString(str_repeat("\u{00E9}", 500))->toString()
        );

        $rejectedReasons = 0;
        foreach (['', '   ', "\u{00A0}\u{2003}\u{3000}", str_repeat("\u{00E9}", 501)] as $invalidReason) {
            try {
                SessionRevocationReason::fromString($invalidReason);
                self::fail('An unusable administrative reason must be rejected.');
            } catch (SessionRevocationReasonException) {
                ++$rejectedReasons;
            }
        }

        self::assertSame(4, $rejectedReasons);

        $userId = UserId::fromString('018f0000-0000-7000-8000-000000000004');
        self::assertSame([], AuditEvidence::record('Admin-42', 'user.invited', $userId)->context());
    }

    public function test_it_revokes_an_owned_non_current_session_before_committing_once_and_emitting_success(): void
    {
        $actorId = UserId::fromString('018f0000-0000-7000-8000-000000000001');
        $currentSessionId = RefreshSessionId::fromString('018f0000-0000-7000-8000-000000000002');
        $targetSessionId = RefreshSessionId::fromString('018f0000-0000-7000-8000-000000000003');
        $unitOfWork = new InMemoryUnitOfWork();
        $repository = new InMemoryRefreshSessionRepository($unitOfWork);
        $repository->add($this->session($targetSessionId, $actorId));

        $events = new InMemoryEventDispatcher(function () use ($repository, $targetSessionId, $unitOfWork): void {
            self::assertTrue($repository->getById($targetSessionId)?->isRevoked());
            self::assertTrue($unitOfWork->transactionCompleted);
        });
        $authorization = new FixedSessionAdministrationAuthorization(true);
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork);
        $handler = new RevokeSessionHandler(
            $repository,
            new FixedClock(new DateTimeImmutable('2026-08-20T12:00:00+00:00')),
            $authorization,
            $auditEvidenceRepository,
            $unitOfWork,
            $events
        );

        $handler->handle(CommandMessage::create(new RevokeSession(
            $actorId,
            $currentSessionId,
            $targetSessionId
        )));

        self::assertSame(RevokeSession::class, RevokeSessionHandler::commandRegistration());
        self::assertSame(1, $unitOfWork->transactions);
        self::assertTrue($repository->getById($targetSessionId)?->isRevoked());
        self::assertCount(1, $events->events());
        self::assertInstanceOf(RefreshSessionRevoked::class, $events->events()[0]);
        $event = $events->events()[0];
        self::assertSame($actorId, $event->getActorId());
        self::assertSame($actorId, $event->getUserId());
        self::assertSame($targetSessionId, $event->getRefreshSessionId());
        self::assertSame('2026-08-20T12:00:00+00:00', $event->getRevokedAt()->format(DATE_ATOM));
        self::assertSame(0, $authorization->calls());
        self::assertSame([], $auditEvidenceRepository->all());
    }

    public function test_it_rejects_revoking_the_current_session_and_preserves_it(): void
    {
        $actorId = UserId::fromString('018f0000-0000-7000-8000-000000000001');
        $currentSessionId = RefreshSessionId::fromString('018f0000-0000-7000-8000-000000000002');
        $unitOfWork = new InMemoryUnitOfWork();
        $repository = new InMemoryRefreshSessionRepository($unitOfWork);
        $repository->add($this->session($currentSessionId, $actorId));

        $command = new RevokeSession($actorId, $currentSessionId, $currentSessionId);
        $events = new InMemoryEventDispatcher();
        $handler = $this->handler($repository, $unitOfWork, $events);

        $failure = $this->captureFailure($handler, $command);

        self::assertInstanceOf(CurrentRefreshSessionRevocationException::class, $failure);
        self::assertFalse($repository->getById($currentSessionId)?->isRevoked());
        $this->assertCommandFailure($events, $command, $failure);
    }

    public function test_it_rejects_revoking_another_users_session_and_preserves_it(): void
    {
        $actorId = UserId::fromString('018f0000-0000-7000-8000-000000000001');
        $currentSessionId = RefreshSessionId::fromString('018f0000-0000-7000-8000-000000000002');
        $targetSessionId = RefreshSessionId::fromString('018f0000-0000-7000-8000-000000000003');
        $unitOfWork = new InMemoryUnitOfWork();
        $repository = new InMemoryRefreshSessionRepository($unitOfWork);
        $repository->add($this->session(
            $targetSessionId,
            UserId::fromString('018f0000-0000-7000-8000-000000000004')
        ));
        $command = new RevokeSession($actorId, $currentSessionId, $targetSessionId);
        $events = new InMemoryEventDispatcher();
        $authorization = new FixedSessionAdministrationAuthorization(false);
        $handler = $this->handler($repository, $unitOfWork, $events, $authorization);

        $failure = $this->captureFailure($handler, $command);

        self::assertInstanceOf(SessionAdministrationAuthorizationException::class, $failure);
        self::assertSame('Session administration is not authorized.', $failure->getMessage());
        self::assertSame(1, $authorization->calls());
        self::assertFalse($repository->getById($targetSessionId)?->isRevoked());
        $this->assertCommandFailure($events, $command, $failure);
    }

    public function test_it_rejects_a_missing_or_unusable_target_as_non_authoritative(): void
    {
        $actorId = UserId::fromString('018f0000-0000-7000-8000-000000000001');
        $currentSessionId = RefreshSessionId::fromString('018f0000-0000-7000-8000-000000000002');
        $targetSessionId = RefreshSessionId::fromString('018f0000-0000-7000-8000-000000000003');

        foreach ([null, $this->session($targetSessionId, $actorId)->revoke()] as $targetSession) {
            $repository = new ControllableRefreshSessionRepository($targetSession);
            $unitOfWork = new InMemoryUnitOfWork();
            $command = new RevokeSession($actorId, $currentSessionId, $targetSessionId);
            $events = new InMemoryEventDispatcher();
            $handler = $this->handler($repository, $unitOfWork, $events);

            $failure = $this->captureFailure($handler, $command);

            self::assertInstanceOf(RefreshSessionNotFoundException::class, $failure);
            self::assertSame(1, $unitOfWork->transactions);
            $this->assertCommandFailure($events, $command, $failure);
        }
    }

    public function test_it_rejects_a_concurrent_replacement_and_emits_no_success_event(): void
    {
        $actorId = UserId::fromString('018f0000-0000-7000-8000-000000000001');
        $currentSessionId = RefreshSessionId::fromString('018f0000-0000-7000-8000-000000000002');
        $targetSessionId = RefreshSessionId::fromString('018f0000-0000-7000-8000-000000000003');
        $repository = new ControllableRefreshSessionRepository(
            $this->session($targetSessionId, $actorId),
            replaceSucceeds: false
        );
        $unitOfWork = new InMemoryUnitOfWork();
        $command = new RevokeSession($actorId, $currentSessionId, $targetSessionId);
        $events = new InMemoryEventDispatcher();
        $handler = $this->handler($repository, $unitOfWork, $events);

        $failure = $this->captureFailure($handler, $command);

        self::assertInstanceOf(RefreshSessionConflictException::class, $failure);
        self::assertFalse($repository->getById($targetSessionId)?->isRevoked());
        $this->assertCommandFailure($events, $command, $failure);
    }

    public function test_it_rethrows_the_same_repository_failure_and_dispatches_command_failure(): void
    {
        $actorId = UserId::fromString('018f0000-0000-7000-8000-000000000001');
        $currentSessionId = RefreshSessionId::fromString('018f0000-0000-7000-8000-000000000002');
        $targetSessionId = RefreshSessionId::fromString('018f0000-0000-7000-8000-000000000003');
        $expectedFailure = new RuntimeException('repository read failed');
        $repository = new ControllableRefreshSessionRepository(null, getFailure: $expectedFailure);
        $command = new RevokeSession($actorId, $currentSessionId, $targetSessionId);
        $events = new InMemoryEventDispatcher();
        $handler = $this->handler($repository, new InMemoryUnitOfWork(), $events);

        $actualFailure = $this->captureFailure($handler, $command);

        self::assertSame($expectedFailure, $actualFailure);
        $this->assertCommandFailure($events, $command, $expectedFailure);
    }

    public function test_it_rethrows_the_same_clock_failure_and_dispatches_command_failure(): void
    {
        $actorId = UserId::fromString('018f0000-0000-7000-8000-000000000001');
        $currentSessionId = RefreshSessionId::fromString('018f0000-0000-7000-8000-000000000002');
        $targetSessionId = RefreshSessionId::fromString('018f0000-0000-7000-8000-000000000003');
        $expectedFailure = new RuntimeException('clock failed');
        $command = new RevokeSession($actorId, $currentSessionId, $targetSessionId);
        $events = new InMemoryEventDispatcher();
        $handler = new RevokeSessionHandler(
            new ControllableRefreshSessionRepository($this->session($targetSessionId, $actorId)),
            new FailingClock($expectedFailure),
            new FixedSessionAdministrationAuthorization(true),
            new InMemoryAuditEvidenceRepository(),
            new InMemoryUnitOfWork(),
            $events
        );

        $actualFailure = $this->captureFailure($handler, $command);

        self::assertSame($expectedFailure, $actualFailure);
        $this->assertCommandFailure($events, $command, $expectedFailure);
    }

    public function test_it_requires_a_reason_for_an_authorized_administrative_revocation(): void
    {
        $actorId = UserId::fromString('018f0000-0000-7000-8000-000000000001');
        $userId = UserId::fromString('018f0000-0000-7000-8000-000000000004');
        $currentSessionId = RefreshSessionId::fromString('018f0000-0000-7000-8000-000000000002');
        $targetSessionId = RefreshSessionId::fromString('018f0000-0000-7000-8000-000000000003');
        $unitOfWork = new InMemoryUnitOfWork();
        $repository = new InMemoryRefreshSessionRepository($unitOfWork);
        $repository->add($this->session($targetSessionId, $userId));

        $command = new RevokeSession($actorId, $currentSessionId, $targetSessionId);
        $events = new InMemoryEventDispatcher();
        $handler = $this->handler($repository, $unitOfWork, $events);

        $failure = $this->captureFailure($handler, $command);

        self::assertInstanceOf(SessionRevocationReasonRequiredException::class, $failure);
        self::assertFalse($repository->getById($targetSessionId)?->isRevoked());
        $this->assertCommandFailure($events, $command, $failure);
    }

    public function test_it_atomically_revokes_another_users_session_with_reasoned_audit_evidence(): void
    {
        $actorId = UserId::fromString('018f0000-0000-7000-8000-000000000001');
        $userId = UserId::fromString('018f0000-0000-7000-8000-000000000004');
        $currentSessionId = RefreshSessionId::fromString('018f0000-0000-7000-8000-000000000002');
        $targetSessionId = RefreshSessionId::fromString('018f0000-0000-7000-8000-000000000003');
        $unitOfWork = new InMemoryUnitOfWork();
        $repository = new InMemoryRefreshSessionRepository($unitOfWork);
        $repository->add($this->session($targetSessionId, $userId));

        $authorization = new FixedSessionAdministrationAuthorization(true);
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork);
        $reason = SessionRevocationReason::fromString('Compromised device');
        $command = new RevokeSession($actorId, $currentSessionId, $targetSessionId, $reason);
        $events = new InMemoryEventDispatcher(
            function () use ($auditEvidenceRepository, $unitOfWork): void {
                self::assertCount(1, $auditEvidenceRepository->all());
                self::assertTrue($unitOfWork->transactionCompleted);
            }
        );
        $handler = $this->handler(
            $repository,
            $unitOfWork,
            $events,
            $authorization,
            $auditEvidenceRepository
        );

        $handler->handle(CommandMessage::create($command));

        self::assertTrue($repository->getById($targetSessionId)?->isRevoked());
        self::assertSame(1, $authorization->calls());
        self::assertSame($actorId, $authorization->lastActorId());
        self::assertSame($userId, $authorization->lastUserId());
        self::assertCount(1, $auditEvidenceRepository->all());
        $evidence = $auditEvidenceRepository->all()[0];
        self::assertSame('refresh_session.administratively_revoked', $evidence->action());
        self::assertSame($actorId->toString(), $evidence->actorId());
        self::assertSame($userId, $evidence->subjectId());
        self::assertSame([
            'refresh_session_id' => $targetSessionId->toString(),
            'reason' => 'Compromised device',
        ], $evidence->context());
        self::assertCount(1, $events->events());
        self::assertInstanceOf(RefreshSessionRevoked::class, $events->events()[0]);
        self::assertArrayNotHasKey('reason', $events->events()[0]->toArray());
    }

    public function test_it_rolls_back_an_administrative_revocation_when_audit_persistence_fails(): void
    {
        $actorId = UserId::fromString('018f0000-0000-7000-8000-000000000001');
        $userId = UserId::fromString('018f0000-0000-7000-8000-000000000004');
        $currentSessionId = RefreshSessionId::fromString('018f0000-0000-7000-8000-000000000002');
        $targetSessionId = RefreshSessionId::fromString('018f0000-0000-7000-8000-000000000003');
        $unitOfWork = new InMemoryUnitOfWork();
        $repository = new InMemoryRefreshSessionRepository($unitOfWork);
        $repository->add($this->session($targetSessionId, $userId));

        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork, failAfterSave: true);
        $events = new InMemoryEventDispatcher();
        $command = new RevokeSession(
            $actorId,
            $currentSessionId,
            $targetSessionId,
            SessionRevocationReason::fromString('Suspected compromise')
        );
        $handler = $this->handler(
            $repository,
            $unitOfWork,
            $events,
            new FixedSessionAdministrationAuthorization(true),
            $auditEvidenceRepository
        );

        $failure = $this->captureFailure($handler, $command);

        self::assertSame('The audit persistence write failed.', $failure->getMessage());
        self::assertFalse($repository->getById($targetSessionId)?->isRevoked());
        self::assertSame([], $auditEvidenceRepository->all());
        $this->assertCommandFailure($events, $command, $failure);
    }

    public function test_the_command_and_event_round_trip_and_reject_missing_required_data(): void
    {
        $actorId = UserId::fromString('018f0000-0000-7000-8000-000000000001');
        $currentSessionId = RefreshSessionId::fromString('018f0000-0000-7000-8000-000000000002');
        $targetSessionId = RefreshSessionId::fromString('018f0000-0000-7000-8000-000000000003');
        $command = new RevokeSession(
            $actorId,
            $currentSessionId,
            $targetSessionId,
            SessionRevocationReason::fromString('Compromised device')
        );
        $event = new RefreshSessionRevoked(
            $actorId,
            $actorId,
            $targetSessionId,
            new DateTimeImmutable('2026-08-20T12:00:00+00:00')
        );

        self::assertEquals($command, RevokeSession::fromArray($command->toArray()));
        self::assertEquals($event, RefreshSessionRevoked::fromArray($event->toArray()));

        $rejectedPayloads = 0;
        foreach (
            [
                [RevokeSession::class, []],
                [RevokeSession::class, ['actor_id' => $actorId->toString()]],
                [
                    RevokeSession::class,
                    [
                        'actor_id' => $actorId->toString(),
                        'current_session_id' => $currentSessionId->toString(),
                    ],
                ],
                [
                    RevokeSession::class,
                    [
                        'actor_id' => $actorId->toString(),
                        'current_session_id' => $currentSessionId->toString(),
                        'target_session_id' => $targetSessionId->toString(),
                    ],
                ],
                [RefreshSessionRevoked::class, []],
                [RefreshSessionRevoked::class, ['actor_id' => $actorId->toString()]],
                [
                    RefreshSessionRevoked::class,
                    ['actor_id' => $actorId->toString(), 'user_id' => $actorId->toString()],
                ],
                [
                    RefreshSessionRevoked::class,
                    [
                        'actor_id' => $actorId->toString(),
                        'user_id' => $actorId->toString(),
                        'refresh_session_id' => $targetSessionId->toString(),
                    ],
                ],
            ] as [$messageClass, $incompleteData]
        ) {
            try {
                $messageClass::fromArray($incompleteData);
                self::fail('Incomplete message data must be rejected.');
            } catch (DomainException) {
                ++$rejectedPayloads;
            }
        }

        self::assertSame(8, $rejectedPayloads);
    }

    private function assertCommandFailure(
        InMemoryEventDispatcher $events,
        RevokeSession $command,
        Throwable $failure
    ): void {
        self::assertCount(1, $events->events());
        self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
        self::assertSame($command, $events->events()[0]->getCommand());
        self::assertSame($failure->getMessage(), $events->events()[0]->getErrorMessage());
    }

    private function captureFailure(RevokeSessionHandler $handler, RevokeSession $command): Throwable
    {
        try {
            $handler->handle(CommandMessage::create($command));
        } catch (Throwable $throwable) {
            return $throwable;
        }

        self::fail('Session revocation must have failed.');
    }

    private function handler(
        RefreshSessionRepository $refreshSessionRepository,
        InMemoryUnitOfWork $unitOfWork,
        InMemoryEventDispatcher $events,
        ?FixedSessionAdministrationAuthorization $sessionAdministrationAuthorization = null,
        ?InMemoryAuditEvidenceRepository $auditEvidenceRepository = null
    ): RevokeSessionHandler {
        return new RevokeSessionHandler(
            $refreshSessionRepository,
            new FixedClock(new DateTimeImmutable('2026-08-20T12:00:00+00:00')),
            $sessionAdministrationAuthorization ?? new FixedSessionAdministrationAuthorization(true),
            $auditEvidenceRepository ?? new InMemoryAuditEvidenceRepository($unitOfWork),
            $unitOfWork,
            $events
        );
    }

    private function session(RefreshSessionId $refreshSessionId, UserId $userId): RefreshSession
    {
        return RefreshSession::start(
            $refreshSessionId,
            $userId,
            RefreshCredential::fromString(str_repeat('a', 64)),
            new DateTimeImmutable('2026-08-19T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-21T12:00:00+00:00'),
            new DateTimeImmutable('2026-09-19T12:00:00+00:00'),
            1,
            false
        );
    }
}
