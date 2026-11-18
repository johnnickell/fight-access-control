<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\RefreshSession;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\RefreshSession\Service\RefreshCredentialGenerator;
use Fight\AccessControl\Application\AccessControl\RefreshSession\Service\SessionRevocationService;
use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Application\AccessControl\User\Authentication\TokenIssuer;
use Fight\AccessControl\Application\AccessControl\User\Security\AuthenticationTokenPolicy;
use Fight\AccessControl\Application\AccessControl\User\Security\RefreshResult;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Audit\AnonymousId;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditAction;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditAuthenticationOutcome;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidenceRepository;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditSessionRevocation;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditSubject;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditUserOutcome;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditUserRevisions;
use Fight\AccessControl\Domain\AccessControl\Audit\SystemId;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\Event\CurrentSessionLoggedOut;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshCredential;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSession;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionId;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionRepository;
use Fight\AccessControl\Domain\AccessControl\User\Event\RedactedCommandFailed;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\AccessControl\Domain\AccessControl\User\UserRepository;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Application\Error\Exception\AuthenticationRequired;
use Fight\Common\Application\Messaging\Event\EventDispatcher;
use Fight\Common\Application\Repository\TransactionalUnitOfWork;
use Fight\Common\Domain\Exception\DomainException;
use LogicException;
use SensitiveParameter;
use Throwable;

/**
 * Class SessionLifecycle
 *
 * Coordinates refresh rotation, bounded contention, terminal replay and current-session logout.
 */
final readonly class SessionLifecycle
{
    /**
     * Constructs SessionLifecycle
     */
    public function __construct(
        private UserRepository $userRepository,
        private RefreshSessionRepository $refreshSessionRepository,
        private SessionRevocationService $sessionRevocationService,
        private TransactionalUnitOfWork $unitOfWork,
        private Clock $clock,
        private RefreshCredentialGenerator $refreshCredentialGenerator,
        private TokenIssuer $tokenIssuer,
        private AuthenticationTokenPolicy $tokenPolicy,
        private EventDispatcher $eventDispatcher,
        private ?AuditEvidenceRepository $auditEvidenceRepository = null
    ) {
    }

    /**
     * Validates a refresh credential and returns a rotation or bounded-conflict outcome
     */
    public function refresh(
        #[SensitiveParameter] string $refreshCredential,
        UserId|AgentId|SystemId|AnonymousId|null $actorId = null
    ): RefreshResult {
        try {
            /** @var RefreshResult|AuthenticationRequired $refreshResult */
            $refreshResult = $this->unitOfWork->commitTransactional(function () use (
                $refreshCredential,
                $actorId
            ): RefreshResult|AuthenticationRequired {
                $authenticatedAt = $this->clock->now();
                $credential = $this->presentedCredential($refreshCredential);
                $refreshSession = $this->refreshSessionRepository->getByCredential($credential);
                if ($refreshSession !== null && !$refreshSession->matchesCredential($credential)) {
                    throw new LogicException('The current-credential lookup returned unrelated authority.');
                }

                if (!$refreshSession instanceof RefreshSession) {
                    $refreshSession = $this->refreshSessionRepository->getByUsedCredential($credential);
                    if ($refreshSession !== null && !$refreshSession->matchesUsedCredential($credential)) {
                        throw new LogicException('The used-credential lookup returned unrelated authority.');
                    }

                    $user = null;
                    if ($refreshSession instanceof RefreshSession) {
                        $user = $this->userRepository->getById($refreshSession->getUserId());
                    }

                    return $this->resolveUsedCredential(
                        $refreshSession,
                        $user,
                        $credential,
                        $authenticatedAt,
                        $actorId
                    );
                }

                $user = $this->userRepository->getById($refreshSession->getUserId());
                $this->assertAuthoritativeSession($refreshSession, $user, $authenticatedAt);
                $rotatedCredential = $this->refreshCredentialGenerator->generate();
                $proposedIdleExpiry = $this->tokenPolicy->refreshIdleExpiresAt(
                    $authenticatedAt,
                    $refreshSession->isRemembered()
                );
                $absoluteExpiry = $refreshSession->getAbsoluteExpiresAt();
                $idleExpiry = $proposedIdleExpiry < $absoluteExpiry ? $proposedIdleExpiry : $absoluteExpiry;
                $rotatedSession = $refreshSession->rotate($rotatedCredential, $authenticatedAt, $idleExpiry);
                if (!$this->refreshSessionRepository->replace($refreshSession, $rotatedSession)) {
                    $observedAt = $this->clock->now();
                    $currentSession = $this->refreshSessionRepository->getById($refreshSession->getId());
                    if ($currentSession !== null) {
                        SessionRevocationService::assertSameFamily($refreshSession, $currentSession);
                        if (!$currentSession->isRevoked() && !$currentSession->matchesUsedCredential($credential)) {
                            throw new LogicException('The rotation retry lost the expected credential history.');
                        }
                    }

                    return $this->resolveUsedCredential($currentSession, $user, $credential, $observedAt, $actorId);
                }

                $this->auditEvidenceRepository?->add(AuditEvidence::record(
                    $user->getId(),
                    new AuditSubject($user->getId()),
                    AuditAction::SESSION_REFRESHED,
                    $authenticatedAt,
                    new AuditAuthenticationOutcome(
                        new AuditUserOutcome(
                            AuditUserRevisions::fromUser($user),
                            AuditUserRevisions::fromUser($user)
                        ),
                        $rotatedSession->getId(),
                        $refreshSession->getRevision(),
                        $rotatedSession->getRevision()
                    ),
                    null
                ));

                return RefreshResult::rotated(
                    $this->tokenIssuer->issue($user, $rotatedSession, $rotatedCredential, $authenticatedAt)
                );
            });

            // Terminal replay returns a refusal so its safety mutation commits before outward rejection.
            if ($refreshResult instanceof AuthenticationRequired) {
                throw $refreshResult;
            }

            return $refreshResult;
        } catch (Throwable $throwable) {
            $this->publishFailure('refresh', [], 'Session refresh failed.');
            throw $throwable;
        }
    }

    /**
     * Revokes only the session selected by the presented refresh credential
     */
    public function logout(
        #[SensitiveParameter] string $refreshCredential,
        UserId|AgentId|SystemId|AnonymousId|null $actorId = null
    ): void {
        try {
            /** @var RefreshSessionId $refreshSessionId */
            $refreshSessionId = $this->unitOfWork->commitTransactional(function () use (
                $refreshCredential,
                $actorId
            ): RefreshSessionId {
                $credential = $this->presentedCredential($refreshCredential);
                $refreshSession = $this->refreshSessionRepository->getByCredential($credential);
                if ($refreshSession !== null && !$refreshSession->matchesCredential($credential)) {
                    throw new LogicException('The current-credential lookup returned unrelated authority.');
                }

                if (!$refreshSession instanceof RefreshSession) {
                    throw new AuthenticationRequired('The refresh session is not authoritative.');
                }

                if (!$refreshSession->isRevoked() && $this->auditEvidenceRepository !== null && $actorId === null) {
                    throw new AuthenticationRequired('The refresh session is not authoritative.');
                }

                $revokedAt = $this->clock->now();
                [$before, $after] = $this->sessionRevocationService->revoke($refreshSession);
                if ($before !== null && $actorId !== null) {
                    $this->auditEvidenceRepository?->add(AuditEvidence::record(
                        $actorId,
                        new AuditSubject($after->getUserId()),
                        AuditAction::SESSION_LOGGED_OUT,
                        $revokedAt,
                        new AuditSessionRevocation($after->getId(), $before->getRevision(), $after->getRevision()),
                        null
                    ));
                }

                return $after->getId();
            });

            $this->eventDispatcher->trigger(new CurrentSessionLoggedOut($refreshSessionId));
        } catch (Throwable $throwable) {
            $this->publishFailure('logout', [], 'Session logout failed.');
            throw $throwable;
        }
    }

    /**
     * Rejects any session or owner that is no longer authoritative
     *
     * @phpstan-assert RefreshSession $refreshSession
     * @phpstan-assert User $user
     */
    private function assertAuthoritativeSession(
        ?RefreshSession $refreshSession,
        ?User $user,
        DateTimeImmutable $authenticatedAt
    ): void {
        if ($refreshSession !== null && $user !== null && !$user->getId()->equals($refreshSession->getUserId())) {
            throw new LogicException('The session owner lookup returned a different identity.');
        }

        if (
            !$refreshSession instanceof RefreshSession
            || !$user instanceof User
            || $user->getState() !== UserState::ACTIVE
            || $user->getAuthenticationVersion() !== $refreshSession->getAuthenticationVersion()
            || !$refreshSession->isUsableAt($authenticatedAt)
        ) {
            throw new AuthenticationRequired('The refresh session is not authoritative.');
        }
    }

    /**
     * Resolves a previously authoritative credential as a benign conflict or terminal replay
     */
    private function resolveUsedCredential(
        ?RefreshSession $refreshSession,
        ?User $user,
        RefreshCredential $credential,
        DateTimeImmutable $observedAt,
        UserId|AgentId|SystemId|AnonymousId|null $actorId
    ): RefreshResult|AuthenticationRequired {
        $this->assertAuthoritativeSession($refreshSession, $user, $observedAt);
        if (
            $refreshSession->matchesMostRecentlyUsedCredentialWithin(
                $credential,
                $observedAt,
                $this->tokenPolicy->refreshConflictWindow()
            )
        ) {
            return RefreshResult::conflict();
        }

        return $this->revokeCompromisedSession($refreshSession, $observedAt, $actorId);
    }

    /**
     * Revokes the authoritative session family atomically after terminal credential replay
     */
    private function revokeCompromisedSession(
        RefreshSession $refreshSession,
        DateTimeImmutable $observedAt,
        UserId|AgentId|SystemId|AnonymousId|null $actorId
    ): AuthenticationRequired {
        if ($this->auditEvidenceRepository !== null && $actorId === null) {
            throw new AuthenticationRequired('The refresh session is not authoritative.');
        }

        [$before, $after] = $this->sessionRevocationService->revoke($refreshSession);
        if ($before !== null && $actorId !== null) {
            $this->auditEvidenceRepository?->add(AuditEvidence::record(
                $actorId,
                new AuditSubject($after->getUserId()),
                AuditAction::SESSION_REPLAY_REVOKED,
                $observedAt,
                new AuditSessionRevocation($after->getId(), $before->getRevision(), $after->getRevision()),
                null
            ));
        }

        return new AuthenticationRequired('The refresh session is not authoritative.');
    }

    /**
     * Parses a presented credential without classifying unrelated provider faults
     */
    private function presentedCredential(#[SensitiveParameter] string $refreshCredential): RefreshCredential
    {
        try {
            return RefreshCredential::fromString($refreshCredential);
        } catch (DomainException) {
            throw new AuthenticationRequired('The refresh session is not authoritative.');
        }
    }

    /**
     * Dispatches only allowlisted operation context after a sensitive failure
     *
     * @phpstan-param array{} $safeData
     */
    private function publishFailure(string $operation, array $safeData, string $failureMessage): void
    {
        try {
            $this->eventDispatcher->trigger(new RedactedCommandFailed(
                self::class.'::'.$operation,
                $safeData,
                $failureMessage
            ));
        } catch (Throwable) {
            // Notification failure must not replace the original operation failure.
        }
    }
}
