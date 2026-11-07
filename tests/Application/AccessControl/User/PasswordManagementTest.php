<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\EmailChangeGrant\EmailChangeConfirmation;
use Fight\AccessControl\Application\AccessControl\RefreshSession\SessionLifecycle;
use Fight\AccessControl\Application\AccessControl\User\Authentication\TokenIssuer;
use Fight\AccessControl\Application\AccessControl\User\Authentication\UserAuthentication;
use Fight\AccessControl\Application\AccessControl\User\PasswordManagement;
use Fight\AccessControl\Application\AccessControl\User\Security\AccessToken;
use Fight\AccessControl\Application\AccessControl\User\Security\AuthenticationTokenPolicy;
use Fight\AccessControl\Application\AccessControl\User\Security\RefreshOutcome;
use Fight\AccessControl\Application\AccessControl\User\Security\RefreshResult;
use Fight\AccessControl\Application\AccessControl\User\Security\TokenSet;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrant;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeGrant;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetCredential;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrant;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshCredential;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSession;
use Fight\AccessControl\Domain\AccessControl\User\Event\EmailChangeConfirmed;
use Fight\AccessControl\Domain\AccessControl\User\Event\PasswordChanged;
use Fight\AccessControl\Domain\AccessControl\User\Event\PasswordResetCompleted;
use Fight\AccessControl\Domain\AccessControl\User\Event\RedactedCommandFailed;
use Fight\AccessControl\Domain\AccessControl\User\Event\UserActivated;
use Fight\AccessControl\Domain\AccessControl\User\Event\UserLoggedIn;
use Fight\AccessControl\Domain\AccessControl\User\PasswordHash;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Application\Error\Exception\AuthenticationRequired as PasswordResetRejectedException;
use Fight\Common\Application\Error\Exception\AuthenticationRequired as PasswordChangeRejectedException;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Test\AccessControl\Application\AccessControl\Audit\Repository\InMemoryAuditEvidenceRepository;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\PasswordResetGrant\Repository\InMemoryPasswordResetGrants;
use Fight\Test\AccessControl\Application\AccessControl\RefreshSession\Repository\InMemoryRefreshSessionRepository;
use Fight\Test\AccessControl\Application\AccessControl\User\Authentication\SynchronousAuthenticationFixture;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryUserRepository;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryUserRepositoryState;
use Fight\Test\AccessControl\Domain\AccessControl\User\UserFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(AccessToken::class)]
#[CoversClass(UserAuthentication::class)]
#[CoversClass(PasswordManagement::class)]
#[CoversClass(SessionLifecycle::class)]
#[CoversClass(EmailChangeConfirmation::class)]
#[CoversClass(TokenIssuer::class)]
#[CoversClass(AuthenticationTokenPolicy::class)]
#[CoversClass(RefreshOutcome::class)]
#[CoversClass(RefreshResult::class)]
#[CoversClass(ActivationGrant::class)]
#[CoversClass(AuditEvidence::class)]
#[CoversClass(EmailChangeConfirmed::class)]
#[CoversClass(EmailChangeGrant::class)]
#[CoversClass(PasswordHash::class)]
#[CoversClass(PasswordChanged::class)]
#[CoversClass(PasswordResetCompleted::class)]
#[CoversClass(PasswordResetGrant::class)]
#[CoversClass(RefreshCredential::class)]
#[CoversClass(RefreshSession::class)]
#[CoversClass(TokenSet::class)]
#[CoversClass(UserActivated::class)]
#[CoversClass(UserLoggedIn::class)]
#[CoversClass(User::class)]
final class PasswordManagementTest extends TestCase
{
    use SynchronousAuthenticationFixture;

    private const string SIBLING_CREDENTIAL = 'abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789';

    public function test_that_unproven_password_change_is_generic_redacted_and_mutation_free(): void
    {
        $missingUserId = UserId::generate();
        foreach (
            [
                'missing authority'          => [
                    $missingUserId,
                    null,
                    $this->activeUserFor('missing-password-change@example.test', $missingUserId),
                    'correct-secret'
                ],
                'inactive authority'         => [
                    UserId::generate(),
                    UserFixture::withState('inactive-password-change@example.test', UserState::DISABLED),
                    null,
                    'correct-secret'
                ],
                'incorrect current password' => [
                    UserId::generate(),
                    $this->activeUserFor('incorrect-password-change@example.test'),
                    null,
                    'incorrect-current-password'
                ]
            ] as [$authenticatedUserId, $storedUser, $sessionOwner, $currentPassword]
        ) {
            $unitOfWork = new InMemoryUnitOfWork();
            $userRepository = new InMemoryUserRepository($unitOfWork);
            $refreshSessionRepository = new InMemoryRefreshSessionRepository($unitOfWork);
            $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork);
            if ($storedUser instanceof User) {
                $authenticatedUserId = $storedUser->getId();
                $userRepository->add($storedUser);
                $sessionOwner = $storedUser;
            }

            self::assertInstanceOf(User::class, $sessionOwner);
            $refreshSession = $this->session($sessionOwner, $this->refreshCredential(), false);
            $refreshSessionRepository->add($refreshSession);
            $originalPasswordHash = $storedUser?->getPasswordHash()?->toString();
            $eventDispatcher = new InMemoryEventDispatcher();
            $service = $this->passwordManagement(
                userRepository: $userRepository,
                refreshSessionRepository: $refreshSessionRepository,
                unitOfWork: $unitOfWork,
                eventDispatcher: $eventDispatcher,
                auditEvidenceRepository: $auditEvidenceRepository
            );

            try {
                $service->changePassword(
                    $authenticatedUserId,
                    $currentPassword,
                    'a sufficiently long rejected changed password'
                );
                self::fail('Expected password change rejection.');
            } catch (PasswordChangeRejectedException $domainException) {
                self::assertInstanceOf(PasswordChangeRejectedException::class, $domainException);
                self::assertSame('Password change rejected.', $domainException->getMessage());
            }

            $authoritativeUser = $userRepository->getById($authenticatedUserId);
            if ($storedUser instanceof User) {
                self::assertInstanceOf(User::class, $authoritativeUser);
                self::assertSame($originalPasswordHash, $authoritativeUser->getPasswordHash()?->toString());
                self::assertSame(1, $authoritativeUser->getAuthenticationVersion());
                self::assertSame(
                    $storedUser->getAuthenticationAuthorityRevision(),
                    $authoritativeUser->getAuthenticationAuthorityRevision()
                );
            } else {
                self::assertNull($authoritativeUser);
            }

            self::assertSame(1, $unitOfWork->transactions);
            self::assertSame([$refreshSession], $refreshSessionRepository->all());
            self::assertFalse($refreshSession->isRevoked());
            self::assertSame([], $auditEvidenceRepository->all());
            self::assertCount(1, $eventDispatcher->events());
            self::assertInstanceOf(RedactedCommandFailed::class, $eventDispatcher->events()[0]);
            self::assertSame(
                PasswordManagement::class.'::changePassword',
                $eventDispatcher->events()[0]->getCommandClass()
            );
            self::assertSame(
                ['user_id' => $authenticatedUserId->toString()],
                $eventDispatcher->events()[0]->getRedactedCommandData()
            );
            self::assertSame('Password change failed.', $eventDispatcher->events()[0]->getErrorMessage());
            self::assertStringNotContainsString(
                $currentPassword,
                serialize($eventDispatcher->events()[0]->toArray())
            );
            self::assertStringNotContainsString(
                'a sufficiently long rejected changed password',
                serialize($eventDispatcher->events()[0]->toArray())
            );
        }
    }

    public function test_that_proven_password_change_atomically_replaces_authority_and_revokes_all_sessions(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $userRepository = new InMemoryUserRepository($unitOfWork);
        $refreshSessionRepository = new InMemoryRefreshSessionRepository($unitOfWork);
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork);
        $user = $this->activeUserFor('change-password@example.test');
        $userRepository->add($user);
        $refreshSessionRepository->add($this->session($user, $this->refreshCredential(), false));
        $refreshSessionRepository->add($this->session(
            $user,
            RefreshCredential::fromString(self::SIBLING_CREDENTIAL),
            true
        ));
        $eventDispatcher = new InMemoryEventDispatcher(static function () use (
            $auditEvidenceRepository,
            $refreshSessionRepository,
            $unitOfWork,
            $user,
            $userRepository
        ): void {
            self::assertTrue($unitOfWork->transactionCompleted);
            self::assertSame(2, $userRepository->getById($user->getId())?->getAuthenticationVersion());
            self::assertTrue(array_all(
                $refreshSessionRepository->all(),
                static fn(RefreshSession $refreshSession): bool => $refreshSession->isRevoked()
            ));
            self::assertCount(1, $auditEvidenceRepository->all());
        });
        $service = $this->passwordManagement(
            userRepository: $userRepository,
            refreshSessionRepository: $refreshSessionRepository,
            unitOfWork: $unitOfWork,
            eventDispatcher: $eventDispatcher,
            auditEvidenceRepository: $auditEvidenceRepository
        );

        $service->changePassword(
            $user->getId(),
            'correct-secret',
            'a sufficiently long changed password'
        );

        $authoritativeUser = $userRepository->getById($user->getId());
        self::assertInstanceOf(User::class, $authoritativeUser);
        self::assertSame(1, $unitOfWork->transactions);
        self::assertTrue(password_verify(
            'a sufficiently long changed password',
            $authoritativeUser->getPasswordHash()?->toString() ?? ''
        ));
        self::assertSame(2, $authoritativeUser->getAuthenticationVersion());
        self::assertSame(2, $authoritativeUser->getAuthenticationAuthorityRevision());
        self::assertTrue(array_all(
            $refreshSessionRepository->all(),
            static fn(RefreshSession $refreshSession): bool => $refreshSession->isRevoked()
        ));
        self::assertSame(1, $refreshSessionRepository->getAllActiveByUserIdCalls());
        self::assertSame(0, $refreshSessionRepository->getByUserIdCalls());
        self::assertSame($user->getId()->toString(), $auditEvidenceRepository->all()[0]->actorId());
        self::assertSame('user.password_changed', $auditEvidenceRepository->all()[0]->action());
        self::assertSame($user->getId(), $auditEvidenceRepository->all()[0]->subjectId());
        self::assertSame([], $auditEvidenceRepository->all()[0]->context());
        self::assertCount(1, $eventDispatcher->events());
        self::assertInstanceOf(PasswordChanged::class, $eventDispatcher->events()[0]);
        self::assertSame($user->getId(), $eventDispatcher->events()[0]->getUserId());
        self::assertSame('2026-08-19T12:00:00+00:00', $eventDispatcher->events()[0]->getChangedAt()->format(DATE_ATOM));
        self::assertSame(
            $eventDispatcher->events()[0]->toArray(),
            PasswordChanged::fromArray($eventDispatcher->events()[0]->toArray())->toArray()
        );
        self::assertStringNotContainsString('correct-secret', serialize($eventDispatcher->events()[0]->toArray()));
        self::assertStringNotContainsString(
            'a sufficiently long changed password',
            serialize($eventDispatcher->events()[0]->toArray())
        );

        $this->expectException(DomainException::class);
        PasswordChanged::fromArray([]);
    }

    public function test_that_password_change_authority_cas_loss_is_generic_redacted_and_mutation_free(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $userRepository = new InMemoryUserRepository(
            $unitOfWork,
            replaceAuthenticationAuthoritySucceeds: false
        );
        $refreshSessionRepository = new InMemoryRefreshSessionRepository($unitOfWork);
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork);
        $user = $this->activeUserFor('change-password-cas-loss@example.test');
        $userRepository->add($user);
        $refreshSession = $this->session($user, $this->refreshCredential(), false);
        $refreshSessionRepository->add($refreshSession);
        $originalPasswordHash = $user->getPasswordHash()?->toString();
        $eventDispatcher = new InMemoryEventDispatcher();
        $service = $this->passwordManagement(
            userRepository: $userRepository,
            refreshSessionRepository: $refreshSessionRepository,
            unitOfWork: $unitOfWork,
            eventDispatcher: $eventDispatcher,
            auditEvidenceRepository: $auditEvidenceRepository
        );

        try {
            $service->changePassword(
                $user->getId(),
                'correct-secret',
                'a sufficiently long rejected changed password'
            );
            self::fail('Expected password change rejection.');
        } catch (PasswordChangeRejectedException $passwordChangeRejectedException) {
            self::assertInstanceOf(PasswordChangeRejectedException::class, $passwordChangeRejectedException);
            self::assertSame('Password change rejected.', $passwordChangeRejectedException->getMessage());
        }

        $authoritativeUser = $userRepository->getById($user->getId());
        self::assertInstanceOf(User::class, $authoritativeUser);
        self::assertSame($originalPasswordHash, $authoritativeUser->getPasswordHash()?->toString());
        self::assertSame(1, $authoritativeUser->getAuthenticationVersion());
        self::assertSame(1, $authoritativeUser->getAuthenticationAuthorityRevision());
        self::assertSame([$refreshSession], $refreshSessionRepository->all());
        self::assertFalse($refreshSession->isRevoked());
        self::assertSame([], $auditEvidenceRepository->all());
        $this->assertPasswordChangeFailureIsRedacted(
            $eventDispatcher,
            $user->getId(),
            'Password change rejected.',
            'correct-secret',
            'a sufficiently long rejected changed password'
        );
    }

    public function test_that_session_revocation_failure_rolls_back_password_change_and_prior_revocations(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $userRepository = new InMemoryUserRepository($unitOfWork);
        $replacementCalls = 0;
        $failure = new RuntimeException('The refresh-session revocation write failed.');
        $refreshSessionRepository = new InMemoryRefreshSessionRepository(
            $unitOfWork,
            beforeReplace: static function () use (&$replacementCalls, $failure): void {
                ++$replacementCalls;
                if ($replacementCalls === 2) {
                    throw $failure;
                }
            }
        );
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork);
        $user = $this->activeUserFor('change-password-session-rollback@example.test');
        $userRepository->add($user);
        $originalPasswordHash = $user->getPasswordHash()?->toString();
        $firstSession = $this->session($user, $this->refreshCredential(), false);
        $secondSession = $this->session(
            $user,
            RefreshCredential::fromString(self::SIBLING_CREDENTIAL),
            true
        );
        $refreshSessionRepository->add($firstSession);
        $refreshSessionRepository->add($secondSession);

        $eventDispatcher = new InMemoryEventDispatcher();
        $service = $this->passwordManagement(
            userRepository: $userRepository,
            refreshSessionRepository: $refreshSessionRepository,
            unitOfWork: $unitOfWork,
            eventDispatcher: $eventDispatcher,
            auditEvidenceRepository: $auditEvidenceRepository
        );

        try {
            $service->changePassword(
                $user->getId(),
                'correct-secret',
                'a sufficiently long rolled back changed password'
            );
            self::fail('Expected refresh-session revocation failure.');
        } catch (RuntimeException $runtimeException) {
            self::assertSame($failure, $runtimeException);
        }

        $authoritativeUser = $userRepository->getById($user->getId());
        self::assertInstanceOf(User::class, $authoritativeUser);
        self::assertSame($originalPasswordHash, $authoritativeUser->getPasswordHash()?->toString());
        self::assertSame(1, $authoritativeUser->getAuthenticationVersion());
        self::assertSame(1, $authoritativeUser->getAuthenticationAuthorityRevision());
        self::assertSame([$firstSession, $secondSession], $refreshSessionRepository->all());
        self::assertFalse($firstSession->isRevoked());
        self::assertFalse($secondSession->isRevoked());
        self::assertSame([], $auditEvidenceRepository->all());
        $this->assertPasswordChangeFailureIsRedacted(
            $eventDispatcher,
            $user->getId(),
            'The refresh-session revocation write failed.',
            'correct-secret',
            'a sufficiently long rolled back changed password'
        );
    }

    public function test_that_late_password_change_audit_failure_rolls_back_every_staged_effect(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $userRepository = new InMemoryUserRepository($unitOfWork);
        $refreshSessionRepository = new InMemoryRefreshSessionRepository($unitOfWork);
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork, failAfterSave: true);
        $user = $this->activeUserFor('change-password-audit-rollback@example.test');
        $userRepository->add($user);
        $originalPasswordHash = $user->getPasswordHash()?->toString();
        $firstSession = $this->session($user, $this->refreshCredential(), false);
        $secondSession = $this->session(
            $user,
            RefreshCredential::fromString(self::SIBLING_CREDENTIAL),
            true
        );
        $refreshSessionRepository->add($firstSession);
        $refreshSessionRepository->add($secondSession);

        $eventDispatcher = new InMemoryEventDispatcher();
        $service = $this->passwordManagement(
            userRepository: $userRepository,
            refreshSessionRepository: $refreshSessionRepository,
            unitOfWork: $unitOfWork,
            eventDispatcher: $eventDispatcher,
            auditEvidenceRepository: $auditEvidenceRepository
        );

        try {
            $service->changePassword(
                $user->getId(),
                'correct-secret',
                'a sufficiently long audit rolled back password'
            );
            self::fail('Expected audit persistence failure.');
        } catch (RuntimeException $runtimeException) {
            self::assertSame($auditEvidenceRepository->failure(), $runtimeException);
        }

        $authoritativeUser = $userRepository->getById($user->getId());
        self::assertInstanceOf(User::class, $authoritativeUser);
        self::assertSame($originalPasswordHash, $authoritativeUser->getPasswordHash()?->toString());
        self::assertSame(1, $authoritativeUser->getAuthenticationVersion());
        self::assertSame(1, $authoritativeUser->getAuthenticationAuthorityRevision());
        self::assertSame([$firstSession, $secondSession], $refreshSessionRepository->all());
        self::assertFalse($firstSession->isRevoked());
        self::assertFalse($secondSession->isRevoked());
        self::assertSame([], $auditEvidenceRepository->all());
        $this->assertPasswordChangeFailureIsRedacted(
            $eventDispatcher,
            $user->getId(),
            'The audit persistence write failed.',
            'correct-secret',
            'a sufficiently long audit rolled back password'
        );
    }

    public function test_that_password_reset_atomically_changes_authority_and_revokes_every_active_session(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $userRepository = new InMemoryUserRepository($unitOfWork);
        $passwordResetGrantRepository = new InMemoryPasswordResetGrants($unitOfWork);
        $refreshSessionRepository = new InMemoryRefreshSessionRepository($unitOfWork);
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork);
        $user = $this->activeUserFor('reset@example.test');
        $userRepository->add($user);
        $issuedAt = new DateTimeImmutable('2026-08-19T11:00:00+00:00');
        $expiresAt = new DateTimeImmutable('2026-08-19T13:00:00+00:00');
        $passwordResetGrantRepository->add(PasswordResetGrant::issue(
            $user->getId(),
            PasswordResetCredential::fromString(self::RESET_CREDENTIAL),
            $issuedAt,
            $expiresAt,
            $user->getEmail(),
            'ciphertext:reset-once',
            $user->getRecoveryAuthority()
        ));
        $refreshSessionRepository->add($this->session($user, $this->refreshCredential(), false));
        $refreshSessionRepository->add($this->session(
            $user,
            RefreshCredential::fromString(self::SIBLING_CREDENTIAL),
            true
        ));
        $eventDispatcher = new InMemoryEventDispatcher(static function () use (
            $auditEvidenceRepository,
            $passwordResetGrantRepository,
            $refreshSessionRepository,
            $unitOfWork,
            $user,
            $userRepository
        ): void {
            self::assertTrue($unitOfWork->transactionCompleted);
            self::assertTrue($passwordResetGrantRepository->all()[0]->isConsumed());
            self::assertFalse($passwordResetGrantRepository->all()[0]->getDelivery()->hasRecoverableMaterial());
            self::assertTrue(array_all(
                $refreshSessionRepository->all(),
                static fn(RefreshSession $refreshSession): bool => $refreshSession->isRevoked()
            ));
            self::assertSame(2, $userRepository->getById($user->getId())?->getAuthenticationVersion());
            self::assertCount(1, $auditEvidenceRepository->all());
        });
        $service = $this->passwordManagement(
            userRepository: $userRepository,
            refreshSessionRepository: $refreshSessionRepository,
            unitOfWork: $unitOfWork,
            eventDispatcher: $eventDispatcher,
            passwordResetGrantRepository: $passwordResetGrantRepository,
            auditEvidenceRepository: $auditEvidenceRepository
        );

        $service->resetPassword(
            $user->getId(),
            self::RESET_CREDENTIAL,
            'a sufficiently long replacement password'
        );

        $authoritativeUser = $userRepository->getById($user->getId());
        self::assertInstanceOf(User::class, $authoritativeUser);
        self::assertSame(1, $unitOfWork->transactions);
        self::assertTrue(password_verify(
            'a sufficiently long replacement password',
            $authoritativeUser->getPasswordHash()?->toString() ?? ''
        ));
        self::assertSame(2, $authoritativeUser->getAuthenticationVersion());
        self::assertSame(2, $authoritativeUser->getAuthenticationAuthorityRevision());
        self::assertTrue($passwordResetGrantRepository->all()[0]->isConsumed());
        self::assertSame(
            '2026-08-19T12:00:00+00:00',
            $passwordResetGrantRepository->all()[0]->getConsumedAt()?->format(DATE_ATOM)
        );
        self::assertFalse($passwordResetGrantRepository->all()[0]->getDelivery()->hasRecoverableMaterial());
        self::assertTrue(array_all(
            $refreshSessionRepository->all(),
            static fn(RefreshSession $refreshSession): bool => $refreshSession->isRevoked()
        ));
        self::assertSame(1, $refreshSessionRepository->getAllActiveByUserIdCalls());
        self::assertSame(0, $refreshSessionRepository->getByUserIdCalls());
        self::assertSame($user->getId()->toString(), $auditEvidenceRepository->all()[0]->actorId());
        self::assertSame('user.password_reset_completed', $auditEvidenceRepository->all()[0]->action());
        self::assertSame($user->getId(), $auditEvidenceRepository->all()[0]->subjectId());
        self::assertSame([], $auditEvidenceRepository->all()[0]->context());
        self::assertCount(1, $eventDispatcher->events());
        self::assertInstanceOf(PasswordResetCompleted::class, $eventDispatcher->events()[0]);
        self::assertSame($user->getId(), $eventDispatcher->events()[0]->getUserId());
        self::assertSame(
            '2026-08-19T12:00:00+00:00',
            $eventDispatcher->events()[0]->getCompletedAt()->format(DATE_ATOM)
        );
        self::assertSame(
            $eventDispatcher->events()[0]->toArray(),
            PasswordResetCompleted::fromArray($eventDispatcher->events()[0]->toArray())->toArray()
        );
        self::assertStringNotContainsString(
            self::RESET_CREDENTIAL,
            serialize($eventDispatcher->events()[0]->toArray())
        );
        self::assertStringNotContainsString(
            'a sufficiently long replacement password',
            serialize($eventDispatcher->events()[0]->toArray())
        );
    }

    public function test_that_password_reset_completion_event_rejects_missing_data(): void
    {
        $this->expectException(DomainException::class);

        PasswordResetCompleted::fromArray([]);
    }

    public function test_that_concurrent_grant_consumption_rejects_reset_without_mutating_authority(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $userRepository = new InMemoryUserRepository($unitOfWork);
        $passwordResetGrantRepository = new InMemoryPasswordResetGrants(
            $unitOfWork,
            replaceConsumedSucceeds: false
        );
        $refreshSessionRepository = new InMemoryRefreshSessionRepository($unitOfWork);
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork);
        $eventDispatcher = new InMemoryEventDispatcher();
        $user = $this->activeUserFor('reset-conflict@example.test');
        $userRepository->add($user);
        $originalPasswordHash = $user->getPasswordHash()?->toString();
        $passwordResetGrant = PasswordResetGrant::issue(
            $user->getId(),
            PasswordResetCredential::fromString(self::RESET_CREDENTIAL),
            new DateTimeImmutable('2026-08-19T11:00:00+00:00'),
            new DateTimeImmutable('2026-08-19T13:00:00+00:00'),
            $user->getEmail(),
            'ciphertext:reset-once',
            $user->getRecoveryAuthority()
        );
        $passwordResetGrantRepository->add($passwordResetGrant);
        $refreshSession = $this->session($user, $this->refreshCredential(), false);
        $refreshSessionRepository->add($refreshSession);
        $service = $this->passwordManagement(
            userRepository: $userRepository,
            refreshSessionRepository: $refreshSessionRepository,
            unitOfWork: $unitOfWork,
            eventDispatcher: $eventDispatcher,
            passwordResetGrantRepository: $passwordResetGrantRepository,
            auditEvidenceRepository: $auditEvidenceRepository
        );

        try {
            $service->resetPassword(
                $user->getId(),
                self::RESET_CREDENTIAL,
                'a sufficiently long rejected replacement password'
            );
            self::fail('Expected concurrent password-reset consumption to be rejected.');
        } catch (PasswordResetRejectedException $passwordResetRejectedException) {
            self::assertSame('Password reset rejected.', $passwordResetRejectedException->getMessage());
        }

        self::assertSame($originalPasswordHash, $user->getPasswordHash()?->toString());
        self::assertSame(1, $user->getAuthenticationVersion());
        self::assertSame(1, $user->getAuthenticationAuthorityRevision());
        self::assertSame([$passwordResetGrant], $passwordResetGrantRepository->all());
        self::assertTrue($passwordResetGrant->isIssued());
        self::assertSame([$refreshSession], $refreshSessionRepository->all());
        self::assertFalse($refreshSession->isRevoked());
        self::assertSame([], $auditEvidenceRepository->all());
        self::assertCount(1, $eventDispatcher->events());
        self::assertInstanceOf(RedactedCommandFailed::class, $eventDispatcher->events()[0]);
        self::assertSame('Password reset failed.', $eventDispatcher->events()[0]->getErrorMessage());
    }

    public function test_that_password_reset_rejects_concurrent_authentication_authority_change(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $userRepository = new InMemoryUserRepository(
            $unitOfWork,
            replaceAuthenticationAuthoritySucceeds: false
        );
        $passwordResetGrantRepository = new InMemoryPasswordResetGrants($unitOfWork);
        $refreshSessionRepository = new InMemoryRefreshSessionRepository($unitOfWork);
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork);
        $eventDispatcher = new InMemoryEventDispatcher();
        $user = $this->activeUserFor('reset-authority-conflict@example.test');
        $userRepository->add($user);
        $originalPasswordHash = $user->getPasswordHash()?->toString();
        $passwordResetGrant = PasswordResetGrant::issue(
            $user->getId(),
            PasswordResetCredential::fromString(self::RESET_CREDENTIAL),
            new DateTimeImmutable('2026-08-19T11:00:00+00:00'),
            new DateTimeImmutable('2026-08-19T13:00:00+00:00'),
            $user->getEmail(),
            'ciphertext:reset-once',
            $user->getRecoveryAuthority()
        );
        $passwordResetGrantRepository->add($passwordResetGrant);
        $refreshSession = $this->session($user, $this->refreshCredential(), false);
        $refreshSessionRepository->add($refreshSession);
        $service = $this->passwordManagement(
            userRepository: $userRepository,
            refreshSessionRepository: $refreshSessionRepository,
            unitOfWork: $unitOfWork,
            eventDispatcher: $eventDispatcher,
            passwordResetGrantRepository: $passwordResetGrantRepository,
            auditEvidenceRepository: $auditEvidenceRepository
        );

        try {
            $service->resetPassword(
                $user->getId(),
                self::RESET_CREDENTIAL,
                'a sufficiently long rejected replacement password'
            );
            self::fail('Expected concurrent authentication authority to reject password reset.');
        } catch (PasswordResetRejectedException $passwordResetRejectedException) {
            self::assertSame('Password reset rejected.', $passwordResetRejectedException->getMessage());
        }

        self::assertSame(
            $originalPasswordHash,
            $userRepository->getById($user->getId())?->getPasswordHash()?->toString()
        );
        self::assertSame(1, $userRepository->getById($user->getId())?->getAuthenticationVersion());
        self::assertSame([$passwordResetGrant], $passwordResetGrantRepository->all());
        self::assertTrue($passwordResetGrant->isIssued());
        self::assertSame([$refreshSession], $refreshSessionRepository->all());
        self::assertFalse($refreshSession->isRevoked());
        self::assertSame([], $auditEvidenceRepository->all());
        self::assertCount(1, $eventDispatcher->events());
        self::assertInstanceOf(RedactedCommandFailed::class, $eventDispatcher->events()[0]);
        self::assertSame(
            ['user_id' => $user->getId()->toString()],
            $eventDispatcher->events()[0]->getRedactedCommandData()
        );
    }

    #[DataProvider('nonAuthoritativePasswordResetDeliveryCases')]
    public function test_that_password_reset_succeeds_without_delivery_authority(bool $hasInvalidatedDelivery): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $userRepository = new InMemoryUserRepository($unitOfWork);
        $passwordResetGrantRepository = new InMemoryPasswordResetGrants($unitOfWork);
        $refreshSessionRepository = new InMemoryRefreshSessionRepository($unitOfWork);
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork);
        $eventDispatcher = new InMemoryEventDispatcher();
        $user = $this->activeUserFor('reset-without-delivery@example.test');
        $userRepository->add($user);
        $issuedPasswordResetGrant = PasswordResetGrant::issue(
            $user->getId(),
            PasswordResetCredential::fromString(self::RESET_CREDENTIAL),
            new DateTimeImmutable('2026-08-19T11:00:00+00:00'),
            new DateTimeImmutable('2026-08-19T13:00:00+00:00'),
            $user->getEmail(),
            'ciphertext:reset-once',
            $user->getRecoveryAuthority()
        );
        $passwordResetGrant = $issuedPasswordResetGrant;
        if ($hasInvalidatedDelivery) {
            $passwordResetGrant = $passwordResetGrant->invalidateDelivery();
        }

        self::assertTrue($passwordResetGrantRepository->add($issuedPasswordResetGrant));
        if ($passwordResetGrant !== $issuedPasswordResetGrant) {
            self::assertTrue($passwordResetGrantRepository->replace($issuedPasswordResetGrant, $passwordResetGrant));
        }

        $refreshSession = $this->session($user, $this->refreshCredential(), false);
        $refreshSessionRepository->add($refreshSession);
        $service = $this->passwordManagement(
            userRepository: $userRepository,
            refreshSessionRepository: $refreshSessionRepository,
            unitOfWork: $unitOfWork,
            eventDispatcher: $eventDispatcher,
            passwordResetGrantRepository: $passwordResetGrantRepository,
            auditEvidenceRepository: $auditEvidenceRepository
        );

        $service->resetPassword(
            $user->getId(),
            self::RESET_CREDENTIAL,
            'a sufficiently long replacement password'
        );

        $authoritativeUser = $userRepository->getById($user->getId());
        self::assertInstanceOf(User::class, $authoritativeUser);
        self::assertSame(1, $unitOfWork->transactions);
        self::assertTrue(password_verify(
            'a sufficiently long replacement password',
            $authoritativeUser->getPasswordHash()?->toString() ?? ''
        ));
        self::assertSame(2, $authoritativeUser->getAuthenticationVersion());
        self::assertSame(2, $authoritativeUser->getAuthenticationAuthorityRevision());
        self::assertTrue($passwordResetGrantRepository->all()[0]->isConsumed());
        self::assertTrue($refreshSessionRepository->all()[0]->isRevoked());
        self::assertFalse($passwordResetGrantRepository->all()[0]->getDelivery()->hasRecoverableMaterial());

        self::assertSame('user.password_reset_completed', $auditEvidenceRepository->all()[0]->action());
        self::assertCount(1, $eventDispatcher->events());
        self::assertInstanceOf(PasswordResetCompleted::class, $eventDispatcher->events()[0]);
    }

    #[DataProvider('rejectedPasswordResetAuthority')]
    public function test_that_password_reset_failures_are_generic_redacted_and_leave_authority_unchanged(
        string $scenario,
        string $presentedCredential
    ): void {
        $unitOfWork = new InMemoryUnitOfWork();
        $userRepository = new InMemoryUserRepository($unitOfWork);
        $passwordResetGrantRepository = new InMemoryPasswordResetGrants($unitOfWork);
        $refreshSessionRepository = new InMemoryRefreshSessionRepository($unitOfWork);
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork);
        $user = $this->activeUserFor('reset-rejected@example.test');
        if ($scenario !== 'missing user authority') {
            $userRepository->add($user);
        }

        $originalPasswordHash = $user->getPasswordHash()?->toString();
        $expiresAt = new DateTimeImmutable('2026-08-19T13:00:00+00:00');
        if ($scenario === 'expiry boundary') {
            $expiresAt = new DateTimeImmutable('2026-08-19T12:00:00+00:00');
        }

        $issuedPasswordResetGrant = PasswordResetGrant::issue(
            $user->getId(),
            PasswordResetCredential::fromString(self::RESET_CREDENTIAL),
            new DateTimeImmutable('2026-08-19T11:00:00+00:00'),
            $expiresAt,
            $user->getEmail(),
            'ciphertext:reset-once',
            $user->getRecoveryAuthority()
        );
        $passwordResetGrant = $issuedPasswordResetGrant;
        if ($scenario === 'consumed grant replay') {
            $passwordResetGrant = $passwordResetGrant->consume(
                new DateTimeImmutable('2026-08-19T11:30:00+00:00')
            );
        }

        if ($scenario === 'revoked grant replay') {
            $passwordResetGrant = $passwordResetGrant->revoke(
                new DateTimeImmutable('2026-08-19T11:30:00+00:00')
            );
        }

        if ($scenario !== 'missing grant authority') {
            self::assertTrue($passwordResetGrantRepository->add($issuedPasswordResetGrant));
            if ($passwordResetGrant !== $issuedPasswordResetGrant) {
                self::assertTrue(
                    $passwordResetGrantRepository->replace($issuedPasswordResetGrant, $passwordResetGrant)
                );
            }
        }

        $refreshSession = $this->session($user, $this->refreshCredential(), false);
        $refreshSessionRepository->add($refreshSession);
        $eventDispatcher = new InMemoryEventDispatcher();
        $service = $this->passwordManagement(
            userRepository: $userRepository,
            refreshSessionRepository: $refreshSessionRepository,
            unitOfWork: $unitOfWork,
            eventDispatcher: $eventDispatcher,
            passwordResetGrantRepository: $passwordResetGrantRepository,
            auditEvidenceRepository: $auditEvidenceRepository
        );

        try {
            $service->resetPassword(
                $user->getId(),
                $presentedCredential,
                'a sufficiently long rejected replacement password'
            );
            self::fail('Expected password reset rejection.');
        } catch (PasswordResetRejectedException $passwordResetRejectedException) {
            self::assertInstanceOf(PasswordResetRejectedException::class, $passwordResetRejectedException);
            self::assertSame('Password reset rejected.', $passwordResetRejectedException->getMessage());
        }

        self::assertSame(1, $unitOfWork->transactions);
        self::assertSame($originalPasswordHash, $user->getPasswordHash()?->toString());
        self::assertSame(1, $user->getAuthenticationVersion());
        self::assertSame(
            $scenario === 'missing grant authority' ? [] : [$passwordResetGrant],
            $passwordResetGrantRepository->all()
        );
        self::assertSame([$refreshSession], $refreshSessionRepository->all());
        self::assertFalse($refreshSession->isRevoked());
        self::assertSame([], $auditEvidenceRepository->all());
        self::assertCount(1, $eventDispatcher->events());
        self::assertInstanceOf(RedactedCommandFailed::class, $eventDispatcher->events()[0]);
        self::assertSame(
            PasswordManagement::class.'::resetPassword',
            $eventDispatcher->events()[0]->getCommandClass()
        );
        self::assertSame(
            ['user_id' => $user->getId()->toString()],
            $eventDispatcher->events()[0]->getRedactedCommandData()
        );
        self::assertSame('Password reset failed.', $eventDispatcher->events()[0]->getErrorMessage());
        if ($presentedCredential !== '') {
            self::assertStringNotContainsString(
                $presentedCredential,
                serialize($eventDispatcher->events()[0]->toArray())
            );
        }

        self::assertStringNotContainsString(
            'a sufficiently long rejected replacement password',
            serialize($eventDispatcher->events()[0]->toArray())
        );
    }

    public function test_that_a_late_password_reset_audit_failure_rolls_back_every_staged_authority_change(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $userState = new InMemoryUserRepositoryState();
        $userRepository = new InMemoryUserRepository($unitOfWork, state: $userState);
        $passwordResetGrantRepository = new InMemoryPasswordResetGrants($unitOfWork);
        $refreshSessionRepository = new InMemoryRefreshSessionRepository($unitOfWork);
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork, failAfterSave: true);
        $user = $this->activeUserFor('reset-rollback@example.test');
        $userRepository->add($user);
        $originalPasswordHash = $user->getPasswordHash()?->toString();
        $expiresAt = new DateTimeImmutable('2026-08-19T13:00:00+00:00');
        $passwordResetGrant = PasswordResetGrant::issue(
            $user->getId(),
            PasswordResetCredential::fromString(self::RESET_CREDENTIAL),
            new DateTimeImmutable('2026-08-19T11:00:00+00:00'),
            $expiresAt,
            $user->getEmail(),
            'ciphertext:reset-once',
            $user->getRecoveryAuthority()
        );
        $firstSession = $this->session($user, $this->refreshCredential(), false);
        $secondSession = $this->session(
            $user,
            RefreshCredential::fromString(self::SIBLING_CREDENTIAL),
            true
        );
        $passwordResetGrantRepository->add($passwordResetGrant);
        $refreshSessionRepository->add($firstSession);
        $refreshSessionRepository->add($secondSession);

        $eventDispatcher = new InMemoryEventDispatcher();
        $service = $this->passwordManagement(
            userRepository: $userRepository,
            refreshSessionRepository: $refreshSessionRepository,
            unitOfWork: $unitOfWork,
            eventDispatcher: $eventDispatcher,
            passwordResetGrantRepository: $passwordResetGrantRepository,
            auditEvidenceRepository: $auditEvidenceRepository
        );

        try {
            $service->resetPassword(
                $user->getId(),
                self::RESET_CREDENTIAL,
                'a sufficiently long rolled back password'
            );
            self::fail('Expected audit persistence failure.');
        } catch (RuntimeException $runtimeException) {
            self::assertSame($auditEvidenceRepository->failure(), $runtimeException);
            self::assertSame('The audit persistence write failed.', $runtimeException->getMessage());
        }

        self::assertSame(1, $unitOfWork->transactions);
        self::assertSame($originalPasswordHash, $user->getPasswordHash()?->toString());
        self::assertSame(1, $user->getAuthenticationVersion());
        self::assertSame(1, $user->getAuthenticationAuthorityRevision());
        self::assertFalse($userState->isAuthenticationAuthorityFenceHeld($user->getId()));
        self::assertSame([$passwordResetGrant], $passwordResetGrantRepository->all());
        self::assertFalse($passwordResetGrantRepository->all()[0]->isConsumed());
        self::assertTrue($passwordResetGrantRepository->all()[0]->getDelivery()->hasRecoverableMaterial());
        self::assertSame([$firstSession, $secondSession], $refreshSessionRepository->all());
        self::assertFalse($firstSession->isRevoked());
        self::assertFalse($secondSession->isRevoked());
        self::assertSame([], $auditEvidenceRepository->all());
        self::assertCount(1, $eventDispatcher->events());
        self::assertInstanceOf(RedactedCommandFailed::class, $eventDispatcher->events()[0]);
        self::assertSame(
            PasswordManagement::class.'::resetPassword',
            $eventDispatcher->events()[0]->getCommandClass()
        );
        self::assertSame(
            ['user_id' => $user->getId()->toString()],
            $eventDispatcher->events()[0]->getRedactedCommandData()
        );
        self::assertSame('Password reset failed.', $eventDispatcher->events()[0]->getErrorMessage());
        self::assertStringNotContainsString(
            self::RESET_CREDENTIAL,
            serialize($eventDispatcher->events()[0]->toArray())
        );
        self::assertStringNotContainsString(
            'a sufficiently long rolled back password',
            serialize($eventDispatcher->events()[0]->toArray())
        );
    }

    public function test_notification_failure_keeps_password_change_rollback_and_committed_authority_distinct(): void
    {
        foreach (['audit', 'commit', 'publication'] as $phase) {
            $unitOfWork = new InMemoryUnitOfWork();
            $userRepository = new InMemoryUserRepository($unitOfWork);
            $refreshSessionRepository = new InMemoryRefreshSessionRepository($unitOfWork);
            $auditEvidenceRepository = new InMemoryAuditEvidenceRepository(
                $unitOfWork,
                failAfterSave: $phase === 'audit'
            );
            $user = $this->activeUserFor('person@example.test');
            $userRepository->add($user);
            $session = $this->session($user, $this->refreshCredential(), false);
            $refreshSessionRepository->add($session);
            $primary = new RuntimeException('Synthetic operation detail. correct-secret ciphertext:synthetic');
            if ($phase === 'commit') {
                $unitOfWork->beforeCommit = static fn(): never => throw $primary;
            }

            $attempts = [];
            $eventDispatcher = new InMemoryEventDispatcher(static function ($event) use (&$attempts, $primary): void {
                $attempts[] = $event;
                if ($event instanceof PasswordChanged) {
                    throw $primary;
                }

                throw new RuntimeException('Synthetic notification failure.');
            });
            $service = $this->passwordManagement(
                userRepository: $userRepository,
                refreshSessionRepository: $refreshSessionRepository,
                unitOfWork: $unitOfWork,
                eventDispatcher: $eventDispatcher,
                auditEvidenceRepository: $auditEvidenceRepository
            );
            $caught = null;
            try {
                $service->changePassword($user->getId(), 'correct-secret', 'a sufficiently long new password');
            } catch (RuntimeException $exception) {
                $caught = $exception;
            }

            $expected = $primary;
            if ($phase === 'audit') {
                $expected = $auditEvidenceRepository->failure();
            }

            self::assertSame($expected, $caught);
            $committed = $phase === 'publication';
            self::assertSame($committed, $unitOfWork->transactionCompleted);
            self::assertFalse($unitOfWork->transactionActive);
            $stored = $userRepository->getById($user->getId());
            self::assertNotNull($stored);
            self::assertSame(1 + (int) $committed, $stored->getAuthenticationVersion());
            self::assertSame(1 + (int) $committed, $stored->getAuthenticationAuthorityRevision());
            self::assertSame($committed, $refreshSessionRepository->all()[0]->isRevoked());
            self::assertCount((int) $committed, $auditEvidenceRepository->all());
            if (!$committed) {
                self::assertSame($user->getPasswordHash(), $stored->getPasswordHash());
            } else {
                self::assertTrue(password_verify(
                    'a sufficiently long new password',
                    $stored->getPasswordHash()->toString()
                ));
                self::assertSame('user.password_changed', $auditEvidenceRepository->all()[0]->action());
            }

            self::assertCount(1 + (int) $committed, $attempts);
            $failure = $attempts[array_key_last($attempts)];
            self::assertInstanceOf(RedactedCommandFailed::class, $failure);
            self::assertSame('Password change failed.', $failure->getErrorMessage());
            self::assertSame(['user_id' => $user->getId()->toString()], $failure->getRedactedCommandData());
            foreach (['correct-secret', 'ciphertext:synthetic', 'a sufficiently long new password'] as $secret) {
                self::assertStringNotContainsString($secret, serialize($failure->toArray()));
            }
        }
    }
}
