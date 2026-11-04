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
use Fight\AccessControl\Domain\AccessControl\RefreshSession\Event\CurrentSessionLoggedOut;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\Exception\RefreshSessionNotFoundException;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshCredential;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSession;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionId;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionRepository;
use Fight\AccessControl\Domain\AccessControl\User\Event\RedactedCommandFailed;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserRepository;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Application\Messaging\Event\EventDispatcher;
use Fight\Common\Application\Repository\TransactionalUnitOfWork;
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
        private EventDispatcher $eventDispatcher
    ) {
    }

    /**
     * Validates a refresh credential and returns a rotation or bounded-conflict outcome
     */
    public function refresh(#[SensitiveParameter] string $refreshCredential): RefreshResult
    {
        try {
            /** @var RefreshResult|RefreshSessionNotFoundException $refreshResult */
            $refreshResult = $this->unitOfWork->commitTransactional(function () use (
                $refreshCredential
            ): RefreshResult|RefreshSessionNotFoundException {
                $authenticatedAt = $this->clock->now();
                $credential = RefreshCredential::fromString($refreshCredential);
                $refreshSession = $this->refreshSessionRepository->getByCredential($credential);
                if (!$refreshSession instanceof RefreshSession) {
                    $refreshSession = $this->refreshSessionRepository->getByUsedCredential($credential);
                    $user = null;
                    if ($refreshSession instanceof RefreshSession) {
                        $user = $this->userRepository->getById($refreshSession->getUserId());
                    }

                    return $this->resolveUsedCredential($refreshSession, $user, $credential, $authenticatedAt);
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

                    return $this->resolveUsedCredential($currentSession, $user, $credential, $observedAt);
                }

                return RefreshResult::rotated(
                    $this->tokenIssuer->issue($user, $rotatedSession, $rotatedCredential, $authenticatedAt)
                );
            });

            if ($refreshResult instanceof RefreshSessionNotFoundException) {
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
    public function logout(#[SensitiveParameter] string $refreshCredential): void
    {
        try {
            $refreshSessionId = $this->unitOfWork->commitTransactional(function () use (
                $refreshCredential
            ): RefreshSessionId {
                $credential = RefreshCredential::fromString($refreshCredential);
                $refreshSession = $this->refreshSessionRepository->getByCredential($credential);
                if (!$refreshSession instanceof RefreshSession) {
                    throw new RefreshSessionNotFoundException('The refresh session does not exist.');
                }

                $refreshSession = $this->sessionRevocationService->revoke($refreshSession);

                return $refreshSession->getId();
            });

            $this->eventDispatcher->trigger(new CurrentSessionLoggedOut($refreshSessionId));
        } catch (Throwable $throwable) {
            $this->publishFailure('logout', [], 'Session logout failed.');
            throw $throwable;
        }
    }

    /**
     * Rejects any session or owner that is no longer authoritative
     */
    private function assertAuthoritativeSession(
        ?RefreshSession $refreshSession,
        ?User $user,
        DateTimeImmutable $authenticatedAt
    ): void {
        if (
            !$refreshSession instanceof RefreshSession
            || !$user instanceof User
            || !$user->getId()->equals($refreshSession->getUserId())
            || $user->getState() !== UserState::ACTIVE
            || $user->getAuthenticationVersion() !== $refreshSession->getAuthenticationVersion()
            || !$refreshSession->isUsableAt($authenticatedAt)
        ) {
            throw new RefreshSessionNotFoundException('The refresh session is not authoritative.');
        }
    }

    /**
     * Resolves a previously authoritative credential as a benign conflict or terminal replay
     */
    private function resolveUsedCredential(
        ?RefreshSession $refreshSession,
        ?User $user,
        RefreshCredential $credential,
        DateTimeImmutable $observedAt
    ): RefreshResult|RefreshSessionNotFoundException {
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

        return $this->revokeCompromisedSession($refreshSession);
    }

    /**
     * Revokes the authoritative session family atomically after terminal credential replay
     */
    private function revokeCompromisedSession(RefreshSession $refreshSession): RefreshSessionNotFoundException
    {
        $this->sessionRevocationService->revoke($refreshSession);

        return new RefreshSessionNotFoundException('The refresh session is not authoritative.');
    }

    /**
     * Dispatches only allowlisted operation context after a sensitive failure
     *
     * @param string               $operation
     * @param array<string, mixed> $safeData
     * @param string               $failureMessage
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
