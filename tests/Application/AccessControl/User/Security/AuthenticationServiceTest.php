<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User\Security;

use Closure;
use DateInterval;
use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\RefreshSession\Service\RefreshCredentialGenerator;
use Fight\AccessControl\Application\AccessControl\User\Security\AccessToken;
use Fight\AccessControl\Application\AccessControl\User\Security\AuthenticationService;
use Fight\AccessControl\Application\AccessControl\User\Security\AuthenticationTokenPolicy;
use Fight\AccessControl\Application\AccessControl\User\Security\RefreshOutcome;
use Fight\AccessControl\Application\AccessControl\User\Security\RefreshResult;
use Fight\AccessControl\Application\AccessControl\User\Security\TokenSet;
use Fight\AccessControl\Application\AccessControl\User\Service\AuthenticationClock;
use Fight\AccessControl\Application\AccessControl\User\Service\LoginThrottle;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationCredential;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrant;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrantRepository;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidenceRepository;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\Exception\PasswordResetRejectedException;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetCredential;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrant;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrantRepository;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\Event\CurrentSessionLoggedOut;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\Exception\RefreshSessionNotFoundException;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshCredential;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSession;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionId;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionRepository;
use Fight\AccessControl\Domain\AccessControl\User\Event\PasswordChanged;
use Fight\AccessControl\Domain\AccessControl\User\Event\PasswordResetCompleted;
use Fight\AccessControl\Domain\AccessControl\User\Event\RedactedCommandFailed;
use Fight\AccessControl\Domain\AccessControl\User\Event\UserActivated;
use Fight\AccessControl\Domain\AccessControl\User\Event\UserLoggedIn;
use Fight\AccessControl\Domain\AccessControl\User\Exception\LoginRejectedException;
use Fight\AccessControl\Domain\AccessControl\User\Exception\PasswordChangeRejectedException;
use Fight\AccessControl\Domain\AccessControl\User\PasswordHash;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\AccessControl\Domain\AccessControl\User\UserRepository;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Application\Auth\Security\PasswordHasher;
use Fight\Common\Application\Auth\Security\PasswordValidator;
use Fight\Common\Application\Auth\Security\TokenEncoder;
use Fight\Common\Domain\Collection\ArrayList;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Repository\Pagination;
use Fight\Common\Domain\Repository\ResultSet;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\Repository\InMemoryActivationGrantRepository;
use Fight\Test\AccessControl\Application\AccessControl\Audit\Repository\InMemoryAuditEvidenceRepository;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\PasswordResetGrant\Repository\InMemoryPasswordResetGrants;
use Fight\Test\AccessControl\Application\AccessControl\RefreshSession\Repository\InMemoryRefreshSessionRepository;
use Fight\Test\AccessControl\Application\AccessControl\RefreshSession\Repository\InMemoryRefreshSessionRepositoryState;
use Fight\Test\AccessControl\Application\AccessControl\RefreshSession\Service\FixedRefreshCredentialGenerator;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryUserRepository;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryUserRepositoryState;
use Fight\Test\AccessControl\Application\AccessControl\User\Service\FixedAuthenticationClock;
use Fight\Test\AccessControl\Application\AccessControl\User\Service\FixedLoginThrottle;
use Fight\Test\AccessControl\Domain\AccessControl\User\UserFixture;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(AccessToken::class)]
#[CoversClass(AuthenticationService::class)]
#[CoversClass(AuthenticationTokenPolicy::class)]
#[CoversClass(RefreshOutcome::class)]
#[CoversClass(RefreshResult::class)]
#[CoversClass(ActivationGrant::class)]
#[CoversClass(AuditEvidence::class)]
#[CoversClass(PasswordHash::class)]
#[CoversClass(PasswordChanged::class)]
#[CoversClass(PasswordChangeRejectedException::class)]
#[CoversClass(PasswordResetCompleted::class)]
#[CoversClass(PasswordResetGrant::class)]
#[CoversClass(PasswordResetRejectedException::class)]
#[CoversClass(RefreshCredential::class)]
#[CoversClass(RefreshSession::class)]
#[CoversClass(TokenSet::class)]
#[CoversClass(UserActivated::class)]
#[CoversClass(UserLoggedIn::class)]
#[CoversClass(User::class)]
final class AuthenticationServiceTest extends TestCase
{
    private const string ACTIVATION_CREDENTIAL = 'activate-once';

    private const string REFRESH_CREDENTIAL = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    private const string RESET_CREDENTIAL = 'reset-once';

    private const string ROTATED_CREDENTIAL = '1111111111111111111111111111111111111111111111111111111111111111';

    private const string SIBLING_CREDENTIAL = 'abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789';

    private const string WINNER_CREDENTIAL = '2222222222222222222222222222222222222222222222222222222222222222';

    private const string SECOND_ROTATED_CREDENTIAL = '3333333333333333333333333333333333333333333333333333333333333333';

    /**
     * @return array<string, array{?User, bool}>
     */
    public static function rejectedLoginStates(): array
    {
        $activeUser = static function (string $email): User {
            $user = User::invite(UserId::generate(), EmailAddress::fromString($email));
            $user->activate(PasswordHash::fromString(password_hash('correct-secret', PASSWORD_DEFAULT)));

            return $user;
        };

        return [
            'unknown identity' => [null, true],
            'pending identity' => [
                User::invite(UserId::generate(), EmailAddress::fromString('pending@example.test')),
                true,
            ],
            'disabled identity' => [UserFixture::withState('disabled@example.test', UserState::DISABLED), true],
            'deleted identity' => [UserFixture::withState('deleted@example.test', UserState::DELETED), true],
            'throttled active identity' => [$activeUser('throttled@example.test'), false],
        ];
    }

    /**
     * @return array<string, array{bool, string, string}>
     */
    public static function refreshLifetimeCases(): array
    {
        return [
            'ordinary session' => [false, '2026-08-20T12:00:00+00:00', '2026-08-21T11:00:00+00:00'],
            'remembered session' => [true, '2026-09-03T12:00:00+00:00', '2026-09-18T11:00:00+00:00'],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function rejectedPasswordResetAuthority(): array
    {
        return [
            'wrong credential' => ['wrong credential', 'wrong-reset-credential'],
            'empty credential' => ['empty credential', ''],
            'expiry boundary' => ['expiry boundary', self::RESET_CREDENTIAL],
            'consumed grant replay' => ['consumed grant replay', self::RESET_CREDENTIAL],
            'revoked grant replay' => ['revoked grant replay', self::RESET_CREDENTIAL],
            'missing user authority' => ['missing user authority', self::RESET_CREDENTIAL],
            'missing grant authority' => ['missing grant authority', self::RESET_CREDENTIAL],
        ];
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function nonAuthoritativePasswordResetDeliveryCases(): array
    {
        return [
            'absent delivery' => [false],
            'already invalidated delivery' => [true],
        ];
    }

    public function test_that_unproven_password_change_is_generic_redacted_and_mutation_free(): void
    {
        $missingUserId = UserId::generate();
        foreach (
            [
                'missing authority' => [
                    $missingUserId,
                    null,
                    $this->activeUserFor('missing-password-change@example.test', $missingUserId),
                    'correct-secret',
                ],
                'inactive authority' => [
                    UserId::generate(),
                    UserFixture::withState('inactive-password-change@example.test', UserState::DISABLED),
                    null,
                    'correct-secret',
                ],
                'incorrect current password' => [
                    UserId::generate(),
                    $this->activeUserFor('incorrect-password-change@example.test'),
                    null,
                    'incorrect-current-password',
                ],
            ] as [$authenticatedUserId, $storedUser, $sessionOwner, $currentPassword]
        ) {
            $unitOfWork = new InMemoryUnitOfWork();
            $users = new InMemoryUserRepository($unitOfWork);
            $sessions = new InMemoryRefreshSessionRepository($unitOfWork);
            $auditEvidence = new InMemoryAuditEvidenceRepository($unitOfWork);
            if ($storedUser instanceof User) {
                $authenticatedUserId = $storedUser->getId();
                $users->add($storedUser);
                $sessionOwner = $storedUser;
            }

            self::assertInstanceOf(User::class, $sessionOwner);
            $refreshSession = $this->session($sessionOwner, $this->refreshCredential(), false);
            $sessions->add($refreshSession);
            $originalPasswordHash = $storedUser?->getPasswordHash()?->toString();
            $events = new InMemoryEventDispatcher();
            $service = $this->service(
                $users,
                new InMemoryActivationGrantRepository($unitOfWork),
                $sessions,
                $unitOfWork,
                $events,
                auditEvidenceRepository: $auditEvidence
            );

            try {
                $service->changePassword(
                    $authenticatedUserId,
                    $currentPassword,
                    'a sufficiently long rejected changed password'
                );
                self::fail('Expected password change rejection.');
            } catch (DomainException $domainException) {
                self::assertInstanceOf(PasswordChangeRejectedException::class, $domainException);
                self::assertSame('Password change rejected.', $domainException->getMessage());
            }

            $authoritativeUser = $users->getById($authenticatedUserId);
            if ($storedUser instanceof User) {
                self::assertInstanceOf(User::class, $authoritativeUser);
                self::assertSame($originalPasswordHash, $authoritativeUser->getPasswordHash()?->toString());
                self::assertSame(1, $authoritativeUser->getAuthenticationVersion());
                self::assertSame(0, $authoritativeUser->getAuthenticationAuthorityRevision());
            } else {
                self::assertNull($authoritativeUser);
            }

            self::assertSame(1, $unitOfWork->transactions);
            self::assertSame([$refreshSession], $sessions->all());
            self::assertFalse($refreshSession->isRevoked());
            self::assertSame([], $auditEvidence->all());
            self::assertCount(1, $events->events());
            self::assertInstanceOf(RedactedCommandFailed::class, $events->events()[0]);
            self::assertSame(
                AuthenticationService::class.'::changePassword',
                $events->events()[0]->getCommandClass()
            );
            self::assertSame(
                ['user_id' => $authenticatedUserId->toString()],
                $events->events()[0]->getRedactedCommandData()
            );
            self::assertSame('Password change rejected.', $events->events()[0]->getErrorMessage());
            self::assertStringNotContainsString(
                $currentPassword,
                serialize($events->events()[0]->toArray())
            );
            self::assertStringNotContainsString(
                'a sufficiently long rejected changed password',
                serialize($events->events()[0]->toArray())
            );
        }
    }

    public function test_that_proven_password_change_atomically_replaces_authority_and_revokes_all_sessions(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $users = new InMemoryUserRepository($unitOfWork);
        $sessions = new InMemoryRefreshSessionRepository($unitOfWork);
        $auditEvidence = new InMemoryAuditEvidenceRepository($unitOfWork);
        $user = $this->activeUserFor('change-password@example.test');
        $users->add($user);
        $sessions->add($this->session($user, $this->refreshCredential(), false));
        $sessions->add($this->session(
            $user,
            RefreshCredential::fromString(self::SIBLING_CREDENTIAL),
            true
        ));
        $events = new InMemoryEventDispatcher(static function () use (
            $auditEvidence,
            $sessions,
            $unitOfWork,
            $user,
            $users
        ): void {
            self::assertTrue($unitOfWork->transactionCompleted);
            self::assertSame(2, $users->getById($user->getId())?->getAuthenticationVersion());
            self::assertTrue(array_all(
                $sessions->all(),
                static fn(RefreshSession $refreshSession): bool => $refreshSession->isRevoked()
            ));
            self::assertCount(1, $auditEvidence->all());
        });
        $service = $this->service(
            $users,
            new InMemoryActivationGrantRepository($unitOfWork),
            $sessions,
            $unitOfWork,
            $events,
            auditEvidenceRepository: $auditEvidence
        );

        $service->changePassword(
            $user->getId(),
            'correct-secret',
            'a sufficiently long changed password'
        );

        $authoritativeUser = $users->getById($user->getId());
        self::assertInstanceOf(User::class, $authoritativeUser);
        self::assertSame(1, $unitOfWork->transactions);
        self::assertTrue(password_verify(
            'a sufficiently long changed password',
            $authoritativeUser->getPasswordHash()?->toString() ?? ''
        ));
        self::assertSame(2, $authoritativeUser->getAuthenticationVersion());
        self::assertSame(1, $authoritativeUser->getAuthenticationAuthorityRevision());
        self::assertTrue(array_all(
            $sessions->all(),
            static fn(RefreshSession $refreshSession): bool => $refreshSession->isRevoked()
        ));
        self::assertSame(1, $sessions->getAllActiveByUserIdCalls());
        self::assertSame(0, $sessions->getByUserIdCalls());
        self::assertSame($user->getId()->toString(), $auditEvidence->all()[0]->actorId());
        self::assertSame('user.password_changed', $auditEvidence->all()[0]->action());
        self::assertSame($user->getId(), $auditEvidence->all()[0]->userId());
        self::assertSame([], $auditEvidence->all()[0]->context());
        self::assertCount(1, $events->events());
        self::assertInstanceOf(PasswordChanged::class, $events->events()[0]);
        self::assertSame($user->getId(), $events->events()[0]->getUserId());
        self::assertSame('2026-08-19T12:00:00+00:00', $events->events()[0]->getChangedAt()->format(DATE_ATOM));
        self::assertSame(
            $events->events()[0]->toArray(),
            PasswordChanged::fromArray($events->events()[0]->toArray())->toArray()
        );
        self::assertStringNotContainsString('correct-secret', serialize($events->events()[0]->toArray()));
        self::assertStringNotContainsString(
            'a sufficiently long changed password',
            serialize($events->events()[0]->toArray())
        );

        $this->expectException(DomainException::class);
        PasswordChanged::fromArray([]);
    }

    public function test_that_password_change_authority_cas_loss_is_generic_redacted_and_mutation_free(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $users = new InMemoryUserRepository(
            $unitOfWork,
            replaceAuthenticationAuthoritySucceeds: false
        );
        $sessions = new InMemoryRefreshSessionRepository($unitOfWork);
        $auditEvidence = new InMemoryAuditEvidenceRepository($unitOfWork);
        $user = $this->activeUserFor('change-password-cas-loss@example.test');
        $users->add($user);
        $refreshSession = $this->session($user, $this->refreshCredential(), false);
        $sessions->add($refreshSession);
        $originalPasswordHash = $user->getPasswordHash()?->toString();
        $events = new InMemoryEventDispatcher();
        $service = $this->service(
            $users,
            new InMemoryActivationGrantRepository($unitOfWork),
            $sessions,
            $unitOfWork,
            $events,
            auditEvidenceRepository: $auditEvidence
        );

        try {
            $service->changePassword(
                $user->getId(),
                'correct-secret',
                'a sufficiently long rejected changed password'
            );
            self::fail('Expected password change rejection.');
        } catch (DomainException $domainException) {
            self::assertInstanceOf(PasswordChangeRejectedException::class, $domainException);
            self::assertSame('Password change rejected.', $domainException->getMessage());
        }

        $authoritativeUser = $users->getById($user->getId());
        self::assertInstanceOf(User::class, $authoritativeUser);
        self::assertSame($originalPasswordHash, $authoritativeUser->getPasswordHash()?->toString());
        self::assertSame(1, $authoritativeUser->getAuthenticationVersion());
        self::assertSame(0, $authoritativeUser->getAuthenticationAuthorityRevision());
        self::assertSame([$refreshSession], $sessions->all());
        self::assertFalse($refreshSession->isRevoked());
        self::assertSame([], $auditEvidence->all());
        $this->assertPasswordChangeFailureIsRedacted(
            $events,
            $user->getId(),
            'Password change rejected.',
            'correct-secret',
            'a sufficiently long rejected changed password'
        );
    }

    public function test_that_session_revocation_failure_rolls_back_password_change_and_prior_revocations(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $users = new InMemoryUserRepository($unitOfWork);
        $replacementCalls = 0;
        $failure = new RuntimeException('The refresh-session revocation write failed.');
        $sessions = new InMemoryRefreshSessionRepository(
            $unitOfWork,
            beforeReplace: static function () use (&$replacementCalls, $failure): void {
                ++$replacementCalls;
                if ($replacementCalls === 2) {
                    throw $failure;
                }
            }
        );
        $auditEvidence = new InMemoryAuditEvidenceRepository($unitOfWork);
        $user = $this->activeUserFor('change-password-session-rollback@example.test');
        $users->add($user);
        $originalPasswordHash = $user->getPasswordHash()?->toString();
        $firstSession = $this->session($user, $this->refreshCredential(), false);
        $secondSession = $this->session(
            $user,
            RefreshCredential::fromString(self::SIBLING_CREDENTIAL),
            true
        );
        $sessions->add($firstSession);
        $sessions->add($secondSession);

        $events = new InMemoryEventDispatcher();
        $service = $this->service(
            $users,
            new InMemoryActivationGrantRepository($unitOfWork),
            $sessions,
            $unitOfWork,
            $events,
            auditEvidenceRepository: $auditEvidence
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

        $authoritativeUser = $users->getById($user->getId());
        self::assertInstanceOf(User::class, $authoritativeUser);
        self::assertSame($originalPasswordHash, $authoritativeUser->getPasswordHash()?->toString());
        self::assertSame(1, $authoritativeUser->getAuthenticationVersion());
        self::assertSame(0, $authoritativeUser->getAuthenticationAuthorityRevision());
        self::assertSame([$firstSession, $secondSession], $sessions->all());
        self::assertFalse($firstSession->isRevoked());
        self::assertFalse($secondSession->isRevoked());
        self::assertSame([], $auditEvidence->all());
        $this->assertPasswordChangeFailureIsRedacted(
            $events,
            $user->getId(),
            'The refresh-session revocation write failed.',
            'correct-secret',
            'a sufficiently long rolled back changed password'
        );
    }

    public function test_that_late_password_change_audit_failure_rolls_back_every_staged_effect(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $users = new InMemoryUserRepository($unitOfWork);
        $sessions = new InMemoryRefreshSessionRepository($unitOfWork);
        $auditEvidence = new InMemoryAuditEvidenceRepository($unitOfWork, failAfterSave: true);
        $user = $this->activeUserFor('change-password-audit-rollback@example.test');
        $users->add($user);
        $originalPasswordHash = $user->getPasswordHash()?->toString();
        $firstSession = $this->session($user, $this->refreshCredential(), false);
        $secondSession = $this->session(
            $user,
            RefreshCredential::fromString(self::SIBLING_CREDENTIAL),
            true
        );
        $sessions->add($firstSession);
        $sessions->add($secondSession);

        $events = new InMemoryEventDispatcher();
        $service = $this->service(
            $users,
            new InMemoryActivationGrantRepository($unitOfWork),
            $sessions,
            $unitOfWork,
            $events,
            auditEvidenceRepository: $auditEvidence
        );

        try {
            $service->changePassword(
                $user->getId(),
                'correct-secret',
                'a sufficiently long audit rolled back password'
            );
            self::fail('Expected audit persistence failure.');
        } catch (RuntimeException $runtimeException) {
            self::assertSame($auditEvidence->failure(), $runtimeException);
        }

        $authoritativeUser = $users->getById($user->getId());
        self::assertInstanceOf(User::class, $authoritativeUser);
        self::assertSame($originalPasswordHash, $authoritativeUser->getPasswordHash()?->toString());
        self::assertSame(1, $authoritativeUser->getAuthenticationVersion());
        self::assertSame(0, $authoritativeUser->getAuthenticationAuthorityRevision());
        self::assertSame([$firstSession, $secondSession], $sessions->all());
        self::assertFalse($firstSession->isRevoked());
        self::assertFalse($secondSession->isRevoked());
        self::assertSame([], $auditEvidence->all());
        $this->assertPasswordChangeFailureIsRedacted(
            $events,
            $user->getId(),
            'The audit persistence write failed.',
            'correct-secret',
            'a sufficiently long audit rolled back password'
        );
    }

    public function test_that_activation_hashes_secrets_and_commits_a_token_set_before_safe_publication(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $users = new InMemoryUserRepository($unitOfWork);
        $grants = new InMemoryActivationGrantRepository($unitOfWork);
        $sessions = new InMemoryRefreshSessionRepository($unitOfWork);
        $user = User::invite(UserId::generate(), EmailAddress::fromString('activate@example.test'));
        $users->add($user);
        $grants->add($this->grant($user->getId()));
        $events = new InMemoryEventDispatcher(static function () use ($unitOfWork): void {
            self::assertTrue($unitOfWork->transactionCompleted);
        });
        $tokenEncoder = new RecordingTokenEncoder();
        $service = $this->service($users, $grants, $sessions, $unitOfWork, $events, tokenEncoder: $tokenEncoder);

        $tokenSet = $service->activate(
            $user->getId(),
            self::ACTIVATION_CREDENTIAL,
            'a sufficiently long initial password',
            true
        );

        $authoritativeUser = $users->getById($user->getId());
        self::assertInstanceOf(User::class, $authoritativeUser);
        self::assertSame(1, $unitOfWork->transactions);
        self::assertSame(UserState::ACTIVE, $authoritativeUser->getState());
        self::assertTrue(password_verify(
            'a sufficiently long initial password',
            $authoritativeUser->getPasswordHash()?->toString() ?? ''
        ));
        self::assertSame(1, $authoritativeUser->getAuthenticationAuthorityRevision());
        self::assertTrue($grants->all()[0]->isConsumed());
        self::assertCount(1, $sessions->all());
        $this->assertTokenSet($tokenSet, $authoritativeUser, $sessions->all()[0], true);
        self::assertSame('access', $tokenEncoder->claims['type']);
        self::assertSame($user->getId()->toString(), $tokenEncoder->claims['sub']);
        self::assertSame($sessions->all()[0]->getId()->toString(), $tokenEncoder->claims['sid']);
        self::assertSame(1, $tokenEncoder->claims['auth_version']);
        self::assertSame(1787140800, $tokenEncoder->claims['iat']);
        self::assertEquals(new DateTimeImmutable('2026-08-19T12:15:00+00:00'), $tokenEncoder->expiration);
        self::assertInstanceOf(UserActivated::class, $events->events()[0]);
        self::assertStringNotContainsString(self::ACTIVATION_CREDENTIAL, serialize($events->events()[0]->toArray()));
        self::assertStringNotContainsString(
            'a sufficiently long initial password',
            serialize($events->events()[0]->toArray())
        );
    }

    public function test_that_activation_rejects_a_wrong_credential_with_only_redacted_context(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $users = new InMemoryUserRepository($unitOfWork);
        $grants = new InMemoryActivationGrantRepository($unitOfWork);
        $sessions = new InMemoryRefreshSessionRepository($unitOfWork);
        $events = new InMemoryEventDispatcher();
        $user = User::invite(UserId::generate(), EmailAddress::fromString('activate@example.test'));
        $users->add($user);
        $grants->add($this->grant($user->getId()));
        $service = $this->service($users, $grants, $sessions, $unitOfWork, $events);

        $this->expectException(LogicException::class);
        try {
            $service->activate($user->getId(), 'wrong-credential', 'a sufficiently long initial password');
        } finally {
            self::assertSame(UserState::PENDING_ACTIVATION, $user->getState());
            self::assertFalse($grants->all()[0]->isConsumed());
            self::assertCount(0, $sessions->all());
            self::assertInstanceOf(RedactedCommandFailed::class, $events->events()[0]);
            self::assertSame(AuthenticationService::class.'::activate', $events->events()[0]->getCommandClass());
            self::assertSame(['user_id' => $user->getId()->toString()], $events->events()[0]->getRedactedCommandData());
            self::assertStringNotContainsString('wrong-credential', serialize($events->events()[0]->toArray()));
        }
    }

    public function test_that_activation_rejects_concurrent_authentication_authority_change(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $users = new InMemoryUserRepository(
            $unitOfWork,
            replaceAuthenticationAuthoritySucceeds: false
        );
        $grants = new InMemoryActivationGrantRepository($unitOfWork);
        $sessions = new InMemoryRefreshSessionRepository($unitOfWork);
        $events = new InMemoryEventDispatcher();
        $user = User::invite(UserId::generate(), EmailAddress::fromString('activate-conflict@example.test'));
        $users->add($user);
        $grant = $this->grant($user->getId());
        $grants->add($grant);
        $service = $this->service($users, $grants, $sessions, $unitOfWork, $events);

        try {
            $service->activate(
                $user->getId(),
                self::ACTIVATION_CREDENTIAL,
                'a sufficiently long initial password'
            );
            self::fail('Expected concurrent activation authority to be rejected.');
        } catch (LogicException $logicException) {
            self::assertSame('The invited account changed concurrently.', $logicException->getMessage());
        }

        self::assertSame($user, $users->getById($user->getId()));
        self::assertSame(UserState::PENDING_ACTIVATION, $user->getState());
        self::assertNull($user->getPasswordHash());
        self::assertSame([$grant], $grants->all());
        self::assertSame([], $sessions->all());
        self::assertCount(1, $events->events());
        self::assertInstanceOf(RedactedCommandFailed::class, $events->events()[0]);
        self::assertSame(
            ['user_id' => $user->getId()->toString()],
            $events->events()[0]->getRedactedCommandData()
        );
    }

    public function test_that_activation_rejects_concurrent_grant_consumption(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $users = new InMemoryUserRepository($unitOfWork);
        $grants = new InMemoryActivationGrantRepository($unitOfWork, replaceSucceeds: false);
        $sessions = new InMemoryRefreshSessionRepository($unitOfWork);
        $events = new InMemoryEventDispatcher();
        $user = User::invite(UserId::generate(), EmailAddress::fromString('activate-race@example.test'));
        $users->add($user);
        $grant = $this->grant($user->getId());
        $grants->add($grant);
        $service = $this->service($users, $grants, $sessions, $unitOfWork, $events);

        $this->expectException(LogicException::class);
        try {
            $service->activate(
                $user->getId(),
                self::ACTIVATION_CREDENTIAL,
                'a sufficiently long initial password'
            );
        } finally {
            self::assertSame($user, $users->getById($user->getId()));
            self::assertSame([$grant], $grants->all());
            self::assertSame([], $sessions->all());
            self::assertInstanceOf(RedactedCommandFailed::class, $events->events()[0]);
        }
    }

    public function test_that_login_uses_fight_common_password_services_and_rehashes_after_success(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $users = new InMemoryUserRepository($unitOfWork);
        $sessions = new InMemoryRefreshSessionRepository($unitOfWork);
        $events = new InMemoryEventDispatcher();
        $user = $this->activeUserFor('person@example.test');
        $originalHash = $user->getPasswordHash()->toString();
        $users->add($user);
        $passwordSecurity = new TestPasswordSecurity(true);
        $service = $this->service(
            $users,
            new InMemoryActivationGrantRepository($unitOfWork),
            $sessions,
            $unitOfWork,
            $events,
            passwordHasher: $passwordSecurity,
            passwordValidator: $passwordSecurity
        );

        $tokenSet = $service->login('PERSON@example.test', 'correct-secret');

        $authoritativeUser = $users->getById($user->getId());
        self::assertInstanceOf(User::class, $authoritativeUser);
        $this->assertTokenSet($tokenSet, $authoritativeUser, $sessions->all()[0], false);
        self::assertNotSame($originalHash, $authoritativeUser->getPasswordHash()?->toString());
        self::assertTrue(password_verify(
            'correct-secret',
            $authoritativeUser->getPasswordHash()?->toString() ?? ''
        ));
        self::assertSame(1, $authoritativeUser->getAuthenticationAuthorityRevision());
        self::assertInstanceOf(UserLoggedIn::class, $events->events()[0]);
    }

    public function test_that_stale_login_rehash_cannot_overwrite_a_concurrent_password_reset(): void
    {
        $userState = new InMemoryUserRepositoryState();
        $sessionState = new InMemoryRefreshSessionRepositoryState();
        $seedUsers = new InMemoryUserRepository(state: $userState);
        $user = $this->activeUserFor('stale-login-rehash@example.test');
        $seedUsers->add($user);
        $resetUnitOfWork = new InMemoryUnitOfWork();
        $resetUsers = new InMemoryUserRepository($resetUnitOfWork, state: $userState);
        $resetSessions = new InMemoryRefreshSessionRepository($resetUnitOfWork, state: $sessionState);
        $passwordResetGrants = new InMemoryPasswordResetGrants($resetUnitOfWork);
        $auditEvidence = new InMemoryAuditEvidenceRepository($resetUnitOfWork);
        $resetEvents = new InMemoryEventDispatcher();
        $loginEvents = new InMemoryEventDispatcher();
        $passwordResetGrants->add(PasswordResetGrant::issue(
            $user->getId(),
            PasswordResetCredential::fromString(self::RESET_CREDENTIAL),
            new DateTimeImmutable('2026-08-19T11:00:00+00:00'),
            new DateTimeImmutable('2026-08-19T13:00:00+00:00'),
            $user->getEmail(),
            'ciphertext:reset-once'
        ));
        $resetService = $this->service(
            $resetUsers,
            new InMemoryActivationGrantRepository($resetUnitOfWork),
            $resetSessions,
            $resetUnitOfWork,
            $resetEvents,
            passwordResetGrantRepository: $passwordResetGrants,
            auditEvidenceRepository: $auditEvidence
        );
        $interleavingValidator = new readonly class (
            $resetService,
            $user->getId(),
            self::RESET_CREDENTIAL
        ) implements PasswordValidator {
            public function __construct(
                private AuthenticationService $resetService,
                private UserId $userId,
                private string $resetCredential
            ) {
            }

            public function validate(string $password, string $hash): bool
            {
                $matches = password_verify($password, $hash);
                $this->resetService->resetPassword(
                    $this->userId,
                    $this->resetCredential,
                    'a sufficiently long concurrent reset password'
                );

                return $matches;
            }

            public function needsRehash(string $hash): bool
            {
                return true;
            }
        };
        $loginUnitOfWork = new InMemoryUnitOfWork();
        $loginUsers = new InMemoryUserRepository($loginUnitOfWork, state: $userState);
        $loginSessions = new InMemoryRefreshSessionRepository($loginUnitOfWork, state: $sessionState);
        $loginService = $this->service(
            $loginUsers,
            new InMemoryActivationGrantRepository($loginUnitOfWork),
            $loginSessions,
            $loginUnitOfWork,
            $loginEvents,
            passwordValidator: $interleavingValidator
        );

        try {
            $loginService->login('stale-login-rehash@example.test', 'correct-secret');
            self::fail('Expected the stale login rehash to lose authentication-authority CAS.');
        } catch (LoginRejectedException $loginRejectedException) {
            self::assertSame('Login rejected.', $loginRejectedException->getMessage());
        }

        $authoritativeUser = $loginUsers->getById($user->getId());
        self::assertInstanceOf(User::class, $authoritativeUser);
        self::assertTrue(password_verify(
            'a sufficiently long concurrent reset password',
            $authoritativeUser->getPasswordHash()?->toString() ?? ''
        ));
        self::assertFalse(password_verify(
            'correct-secret',
            $authoritativeUser->getPasswordHash()?->toString() ?? ''
        ));
        self::assertSame(2, $authoritativeUser->getAuthenticationVersion());
        self::assertSame(1, $authoritativeUser->getAuthenticationAuthorityRevision());
        self::assertTrue($passwordResetGrants->all()[0]->isConsumed());
        self::assertSame('user.password_reset_completed', $auditEvidence->all()[0]->action());
        self::assertSame([], $loginSessions->all());
        self::assertCount(1, $resetEvents->events());
        self::assertInstanceOf(PasswordResetCompleted::class, $resetEvents->events()[0]);
        self::assertCount(1, $loginEvents->events());
        self::assertInstanceOf(RedactedCommandFailed::class, $loginEvents->events()[0]);
        self::assertSame([], $loginEvents->events()[0]->getRedactedCommandData());
        self::assertStringNotContainsString(
            'correct-secret',
            serialize($loginEvents->events()[0]->toArray())
        );
        self::assertStringNotContainsString(
            'a sufficiently long concurrent reset password',
            serialize($loginEvents->events()[0]->toArray())
        );
    }

    public function test_that_no_rehash_login_cannot_create_a_session_after_reset_serializes_authority(): void
    {
        $userState = new InMemoryUserRepositoryState();
        $sessionState = new InMemoryRefreshSessionRepositoryState();
        $seedUsers = new InMemoryUserRepository(state: $userState);
        $user = $this->activeUserFor('no-rehash-race@example.test');
        $seedUsers->add($user);
        $resetUnitOfWork = new InMemoryUnitOfWork();
        $resetUsers = new InMemoryUserRepository($resetUnitOfWork, state: $userState);
        $resetSessions = new InMemoryRefreshSessionRepository($resetUnitOfWork, state: $sessionState);
        $passwordResetGrants = new InMemoryPasswordResetGrants($resetUnitOfWork);
        $auditEvidence = new InMemoryAuditEvidenceRepository($resetUnitOfWork);
        $resetEvents = new InMemoryEventDispatcher();
        $loginEvents = new InMemoryEventDispatcher();
        $passwordResetGrants->add(PasswordResetGrant::issue(
            $user->getId(),
            PasswordResetCredential::fromString(self::RESET_CREDENTIAL),
            new DateTimeImmutable('2026-08-19T11:00:00+00:00'),
            new DateTimeImmutable('2026-08-19T13:00:00+00:00'),
            $user->getEmail(),
            'ciphertext:reset-once'
        ));
        $resetService = $this->service(
            $resetUsers,
            new InMemoryActivationGrantRepository($resetUnitOfWork),
            $resetSessions,
            $resetUnitOfWork,
            $resetEvents,
            passwordResetGrantRepository: $passwordResetGrants,
            auditEvidenceRepository: $auditEvidence
        );
        $interleavingValidator = new readonly class (
            $resetService,
            $user->getId(),
            self::RESET_CREDENTIAL
        ) implements PasswordValidator {
            public function __construct(
                private AuthenticationService $resetService,
                private UserId $userId,
                private string $resetCredential
            ) {
            }

            public function validate(string $password, string $hash): bool
            {
                $matches = password_verify($password, $hash);
                $this->resetService->resetPassword(
                    $this->userId,
                    $this->resetCredential,
                    'a sufficiently long serialized reset password'
                );

                return $matches;
            }

            public function needsRehash(string $hash): bool
            {
                return false;
            }
        };
        $loginUnitOfWork = new InMemoryUnitOfWork();
        $loginUsers = new InMemoryUserRepository($loginUnitOfWork, state: $userState);
        $loginSessions = new InMemoryRefreshSessionRepository($loginUnitOfWork, state: $sessionState);
        $loginService = $this->service(
            $loginUsers,
            new InMemoryActivationGrantRepository($loginUnitOfWork),
            $loginSessions,
            $loginUnitOfWork,
            $loginEvents,
            passwordValidator: $interleavingValidator
        );

        try {
            $loginService->login('no-rehash-race@example.test', 'correct-secret');
            self::fail('Expected stale no-rehash login authority to be rejected.');
        } catch (LoginRejectedException $loginRejectedException) {
            self::assertSame('Login rejected.', $loginRejectedException->getMessage());
        }

        $authoritativeUser = $loginUsers->getById($user->getId());
        self::assertInstanceOf(User::class, $authoritativeUser);
        self::assertTrue(password_verify(
            'a sufficiently long serialized reset password',
            $authoritativeUser->getPasswordHash()?->toString() ?? ''
        ));
        self::assertSame(2, $authoritativeUser->getAuthenticationVersion());
        self::assertSame(1, $authoritativeUser->getAuthenticationAuthorityRevision());
        self::assertSame([], $loginSessions->all());
        self::assertCount(1, $resetEvents->events());
        self::assertInstanceOf(PasswordResetCompleted::class, $resetEvents->events()[0]);
        self::assertCount(1, $loginEvents->events());
        self::assertInstanceOf(RedactedCommandFailed::class, $loginEvents->events()[0]);
        self::assertSame([], $loginEvents->events()[0]->getRedactedCommandData());
        self::assertStringNotContainsString(
            'correct-secret',
            serialize($loginEvents->events()[0]->toArray())
        );
    }

    public function test_that_atomic_login_commit_forces_stale_reset_to_lose_without_missing_its_session(): void
    {
        $userState = new InMemoryUserRepositoryState();
        $sessionState = new InMemoryRefreshSessionRepositoryState();
        $seedUsers = new InMemoryUserRepository(state: $userState);
        $user = $this->activeUserFor('atomic-login-first@example.test');
        $seedUsers->add($user);
        $loginUnitOfWork = new InMemoryUnitOfWork();
        $loginUsers = new InMemoryUserRepository($loginUnitOfWork, state: $userState);
        $loginSessions = new InMemoryRefreshSessionRepository($loginUnitOfWork, state: $sessionState);
        $loginEvents = new InMemoryEventDispatcher();
        $loginService = $this->service(
            $loginUsers,
            new InMemoryActivationGrantRepository($loginUnitOfWork),
            $loginSessions,
            $loginUnitOfWork,
            $loginEvents
        );
        $resetUnitOfWork = new InMemoryUnitOfWork();
        $resetUsers = new InMemoryUserRepository($resetUnitOfWork, state: $userState);
        $resetSessions = new InMemoryRefreshSessionRepository($resetUnitOfWork, state: $sessionState);
        $passwordResetGrants = new InMemoryPasswordResetGrants($resetUnitOfWork);
        $auditEvidence = new InMemoryAuditEvidenceRepository($resetUnitOfWork);
        $resetEvents = new InMemoryEventDispatcher();
        $passwordResetGrants->add(PasswordResetGrant::issue(
            $user->getId(),
            PasswordResetCredential::fromString(self::RESET_CREDENTIAL),
            new DateTimeImmutable('2026-08-19T11:00:00+00:00'),
            new DateTimeImmutable('2026-08-19T13:00:00+00:00'),
            $user->getEmail(),
            'ciphertext:reset-once'
        ));
        $interleavingHasher = new readonly class ($loginService) implements PasswordHasher {
            public function __construct(private AuthenticationService $loginService)
            {
            }

            public function hash(string $password): string
            {
                $this->loginService->login('atomic-login-first@example.test', 'correct-secret');

                return password_hash($password, PASSWORD_DEFAULT);
            }
        };
        $resetService = $this->service(
            $resetUsers,
            new InMemoryActivationGrantRepository($resetUnitOfWork),
            $resetSessions,
            $resetUnitOfWork,
            $resetEvents,
            passwordHasher: $interleavingHasher,
            passwordResetGrantRepository: $passwordResetGrants,
            auditEvidenceRepository: $auditEvidence
        );

        try {
            $resetService->resetPassword(
                $user->getId(),
                self::RESET_CREDENTIAL,
                'a sufficiently long stale reset password'
            );
            self::fail('Expected stale reset authority to lose to the atomic login commit.');
        } catch (PasswordResetRejectedException $passwordResetRejectedException) {
            self::assertSame('Password reset rejected.', $passwordResetRejectedException->getMessage());
        }

        $authoritativeUser = $resetUsers->getById($user->getId());
        self::assertInstanceOf(User::class, $authoritativeUser);
        self::assertTrue(password_verify(
            'correct-secret',
            $authoritativeUser->getPasswordHash()?->toString() ?? ''
        ));
        self::assertSame(1, $authoritativeUser->getAuthenticationVersion());
        self::assertSame(1, $authoritativeUser->getAuthenticationAuthorityRevision());
        self::assertCount(1, $loginSessions->all());
        self::assertFalse($loginSessions->all()[0]->isRevoked());
        self::assertFalse($passwordResetGrants->all()[0]->isConsumed());
        self::assertSame([], $auditEvidence->all());
        self::assertCount(1, $loginEvents->events());
        self::assertInstanceOf(UserLoggedIn::class, $loginEvents->events()[0]);
        self::assertCount(1, $resetEvents->events());
        self::assertInstanceOf(RedactedCommandFailed::class, $resetEvents->events()[0]);
        self::assertSame(
            ['user_id' => $user->getId()->toString()],
            $resetEvents->events()[0]->getRedactedCommandData()
        );
        self::assertStringNotContainsString(
            self::RESET_CREDENTIAL,
            serialize($resetEvents->events()[0]->toArray())
        );
        self::assertStringNotContainsString(
            'a sufficiently long stale reset password',
            serialize($resetEvents->events()[0]->toArray())
        );
    }

    public function test_that_reset_reading_after_atomic_login_observes_and_revokes_its_committed_session(): void
    {
        $userState = new InMemoryUserRepositoryState();
        $sessionState = new InMemoryRefreshSessionRepositoryState();
        $seedUsers = new InMemoryUserRepository(state: $userState);
        $user = $this->activeUserFor('atomic-login-before-reset-read@example.test');
        $seedUsers->add($user);
        $loginUnitOfWork = new InMemoryUnitOfWork();
        $loginUsers = new InMemoryUserRepository($loginUnitOfWork, state: $userState);
        $loginSessions = new InMemoryRefreshSessionRepository($loginUnitOfWork, state: $sessionState);
        $loginEvents = new InMemoryEventDispatcher();
        $loginService = $this->service(
            $loginUsers,
            new InMemoryActivationGrantRepository($loginUnitOfWork),
            $loginSessions,
            $loginUnitOfWork,
            $loginEvents
        );

        $loginService->login('atomic-login-before-reset-read@example.test', 'correct-secret');

        $resetUnitOfWork = new InMemoryUnitOfWork();
        $resetUsers = new InMemoryUserRepository($resetUnitOfWork, state: $userState);
        $resetSessions = new InMemoryRefreshSessionRepository($resetUnitOfWork, state: $sessionState);
        $passwordResetGrants = new InMemoryPasswordResetGrants($resetUnitOfWork);
        $auditEvidence = new InMemoryAuditEvidenceRepository($resetUnitOfWork);
        $resetEvents = new InMemoryEventDispatcher();
        $passwordResetGrants->add(PasswordResetGrant::issue(
            $user->getId(),
            PasswordResetCredential::fromString(self::RESET_CREDENTIAL),
            new DateTimeImmutable('2026-08-19T11:00:00+00:00'),
            new DateTimeImmutable('2026-08-19T13:00:00+00:00'),
            $user->getEmail(),
            'ciphertext:reset-once'
        ));
        $resetService = $this->service(
            $resetUsers,
            new InMemoryActivationGrantRepository($resetUnitOfWork),
            $resetSessions,
            $resetUnitOfWork,
            $resetEvents,
            passwordResetGrantRepository: $passwordResetGrants,
            auditEvidenceRepository: $auditEvidence
        );

        $resetService->resetPassword(
            $user->getId(),
            self::RESET_CREDENTIAL,
            'a sufficiently long authoritative reset password'
        );

        $authoritativeUser = $loginUsers->getById($user->getId());
        self::assertInstanceOf(User::class, $authoritativeUser);
        self::assertTrue(password_verify(
            'a sufficiently long authoritative reset password',
            $authoritativeUser->getPasswordHash()?->toString() ?? ''
        ));
        self::assertSame(2, $authoritativeUser->getAuthenticationVersion());
        self::assertSame(2, $authoritativeUser->getAuthenticationAuthorityRevision());
        self::assertCount(1, $loginSessions->all());
        self::assertTrue($loginSessions->all()[0]->isRevoked());
        self::assertTrue($passwordResetGrants->all()[0]->isConsumed());
        self::assertSame('user.password_reset_completed', $auditEvidence->all()[0]->action());
        self::assertCount(1, $loginEvents->events());
        self::assertInstanceOf(UserLoggedIn::class, $loginEvents->events()[0]);
        self::assertCount(1, $resetEvents->events());
        self::assertInstanceOf(PasswordResetCompleted::class, $resetEvents->events()[0]);
    }

    public function test_that_transaction_duration_authentication_fence_blocks_login_during_reset_scan_window(): void
    {
        $userState = new InMemoryUserRepositoryState();
        $sessionState = new InMemoryRefreshSessionRepositoryState();
        $seedUsers = new InMemoryUserRepository(state: $userState);
        $user = $this->activeUserFor('reset-fence-window@example.test');
        $seedUsers->add($user);
        $loginService = null;
        $loginFailure = null;
        $resetUnitOfWork = new InMemoryUnitOfWork();
        $resetUsers = new InMemoryUserRepository(
            $resetUnitOfWork,
            state: $userState,
            afterReplaceAuthenticationAuthority: static function () use (
                &$loginFailure,
                &$loginService
            ): void {
                self::assertInstanceOf(AuthenticationService::class, $loginService);
                try {
                    $loginService->login('reset-fence-window@example.test', 'correct-secret');
                    self::fail('Expected login to lose the reset transaction-duration authority fence.');
                } catch (LoginRejectedException $loginRejectedException) {
                    $loginFailure = $loginRejectedException;
                }
            }
        );
        $resetSessions = new InMemoryRefreshSessionRepository($resetUnitOfWork, state: $sessionState);
        $passwordResetGrants = new InMemoryPasswordResetGrants($resetUnitOfWork);
        $auditEvidence = new InMemoryAuditEvidenceRepository($resetUnitOfWork);
        $resetEvents = new InMemoryEventDispatcher();
        $passwordResetGrants->add(PasswordResetGrant::issue(
            $user->getId(),
            PasswordResetCredential::fromString(self::RESET_CREDENTIAL),
            new DateTimeImmutable('2026-08-19T11:00:00+00:00'),
            new DateTimeImmutable('2026-08-19T13:00:00+00:00'),
            $user->getEmail(),
            'ciphertext:reset-once'
        ));
        $loginUnitOfWork = new InMemoryUnitOfWork();
        $loginUsers = new InMemoryUserRepository($loginUnitOfWork, state: $userState);
        $loginSessions = new InMemoryRefreshSessionRepository($loginUnitOfWork, state: $sessionState);
        $loginUsers->bindRefreshSessionRepository($loginSessions);
        $loginReadSnapshot = clone $user;
        $snapshotUsers = new readonly class ($loginReadSnapshot, $loginUsers) implements UserRepository {
            public function __construct(
                private User $loginReadSnapshot,
                private InMemoryUserRepository $users
            ) {
            }

            public function getByEmail(EmailAddress $email): ?User
            {
                if ($this->loginReadSnapshot->getEmail()->canonical() === $email->canonical()) {
                    return $this->loginReadSnapshot;
                }

                return null;
            }

            public function getById(UserId $id): ?User
            {
                return $this->users->getById($id);
            }

            public function replaceAuthenticationAuthority(User $expected, User $replacement): bool
            {
                return $this->users->replaceAuthenticationAuthority($expected, $replacement);
            }

            public function replaceAuthenticationAuthorityAndAddRefreshSession(
                User $expected,
                User $replacement,
                RefreshSession $refreshSession
            ): bool {
                return $this->users->replaceAuthenticationAuthorityAndAddRefreshSession(
                    $expected,
                    $replacement,
                    $refreshSession
                );
            }

            public function replaceRoleAssignments(User $expected, User $replacement): bool
            {
                return $this->users->replaceRoleAssignments($expected, $replacement);
            }

            public function add(User $user): void
            {
                $this->users->add($user);
            }
        };
        $loginEvents = new InMemoryEventDispatcher();
        $loginService = $this->service(
            $snapshotUsers,
            new InMemoryActivationGrantRepository($loginUnitOfWork),
            $loginSessions,
            $loginUnitOfWork,
            $loginEvents
        );
        $resetService = $this->service(
            $resetUsers,
            new InMemoryActivationGrantRepository($resetUnitOfWork),
            $resetSessions,
            $resetUnitOfWork,
            $resetEvents,
            passwordResetGrantRepository: $passwordResetGrants,
            auditEvidenceRepository: $auditEvidence
        );

        $resetService->resetPassword(
            $user->getId(),
            self::RESET_CREDENTIAL,
            'a sufficiently long transaction-fenced reset password'
        );

        $authoritativeUser = $resetUsers->getById($user->getId());
        self::assertInstanceOf(User::class, $authoritativeUser);
        self::assertInstanceOf(LoginRejectedException::class, $loginFailure);
        self::assertSame('Login rejected.', $loginFailure->getMessage());
        self::assertSame(1, $userState->getBlockedAuthenticationAuthorityFenceAttempts());
        self::assertFalse($userState->isAuthenticationAuthorityFenceHeld($user->getId()));
        self::assertTrue(password_verify(
            'a sufficiently long transaction-fenced reset password',
            $authoritativeUser->getPasswordHash()?->toString() ?? ''
        ));
        self::assertSame(2, $authoritativeUser->getAuthenticationVersion());
        self::assertSame(1, $authoritativeUser->getAuthenticationAuthorityRevision());
        self::assertSame([], $loginSessions->all());
        self::assertTrue($passwordResetGrants->all()[0]->isConsumed());
        self::assertSame('user.password_reset_completed', $auditEvidence->all()[0]->action());
        self::assertCount(1, $loginEvents->events());
        self::assertInstanceOf(RedactedCommandFailed::class, $loginEvents->events()[0]);
        self::assertSame([], $loginEvents->events()[0]->getRedactedCommandData());
        self::assertStringNotContainsString(
            'correct-secret',
            serialize($loginEvents->events()[0]->toArray())
        );
        self::assertCount(1, $resetEvents->events());
        self::assertInstanceOf(PasswordResetCompleted::class, $resetEvents->events()[0]);
    }

    public function test_that_password_reset_atomically_changes_authority_and_revokes_every_active_session(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $users = new InMemoryUserRepository($unitOfWork);
        $passwordResetGrants = new InMemoryPasswordResetGrants($unitOfWork);
        $sessions = new InMemoryRefreshSessionRepository($unitOfWork);
        $auditEvidence = new InMemoryAuditEvidenceRepository($unitOfWork);
        $user = $this->activeUserFor('reset@example.test');
        $users->add($user);
        $issuedAt = new DateTimeImmutable('2026-08-19T11:00:00+00:00');
        $expiresAt = new DateTimeImmutable('2026-08-19T13:00:00+00:00');
        $passwordResetGrants->add(PasswordResetGrant::issue(
            $user->getId(),
            PasswordResetCredential::fromString(self::RESET_CREDENTIAL),
            $issuedAt,
            $expiresAt,
            $user->getEmail(),
            'ciphertext:reset-once'
        ));
        $sessions->add($this->session($user, $this->refreshCredential(), false));
        $sessions->add($this->session(
            $user,
            RefreshCredential::fromString(self::SIBLING_CREDENTIAL),
            true
        ));
        $events = new InMemoryEventDispatcher(static function () use (
            $auditEvidence,
            $passwordResetGrants,
            $sessions,
            $unitOfWork,
            $user,
            $users
        ): void {
            self::assertTrue($unitOfWork->transactionCompleted);
            self::assertTrue($passwordResetGrants->all()[0]->isConsumed());
            self::assertFalse($passwordResetGrants->all()[0]->getDelivery()->isRecoverable());
            self::assertTrue(array_all(
                $sessions->all(),
                static fn(RefreshSession $refreshSession): bool => $refreshSession->isRevoked()
            ));
            self::assertSame(2, $users->getById($user->getId())?->getAuthenticationVersion());
            self::assertCount(1, $auditEvidence->all());
        });
        $service = $this->service(
            $users,
            new InMemoryActivationGrantRepository($unitOfWork),
            $sessions,
            $unitOfWork,
            $events,
            passwordResetGrantRepository: $passwordResetGrants,
            auditEvidenceRepository: $auditEvidence
        );

        $service->resetPassword(
            $user->getId(),
            self::RESET_CREDENTIAL,
            'a sufficiently long replacement password'
        );

        $authoritativeUser = $users->getById($user->getId());
        self::assertInstanceOf(User::class, $authoritativeUser);
        self::assertSame(1, $unitOfWork->transactions);
        self::assertTrue(password_verify(
            'a sufficiently long replacement password',
            $authoritativeUser->getPasswordHash()?->toString() ?? ''
        ));
        self::assertSame(2, $authoritativeUser->getAuthenticationVersion());
        self::assertSame(1, $authoritativeUser->getAuthenticationAuthorityRevision());
        self::assertTrue($passwordResetGrants->all()[0]->isConsumed());
        self::assertSame(
            '2026-08-19T12:00:00+00:00',
            $passwordResetGrants->all()[0]->getConsumedAt()?->format(DATE_ATOM)
        );
        self::assertFalse($passwordResetGrants->all()[0]->getDelivery()->isRecoverable());
        self::assertTrue(array_all(
            $sessions->all(),
            static fn(RefreshSession $refreshSession): bool => $refreshSession->isRevoked()
        ));
        self::assertSame(1, $sessions->getAllActiveByUserIdCalls());
        self::assertSame(0, $sessions->getByUserIdCalls());
        self::assertSame($user->getId()->toString(), $auditEvidence->all()[0]->actorId());
        self::assertSame('user.password_reset_completed', $auditEvidence->all()[0]->action());
        self::assertSame($user->getId(), $auditEvidence->all()[0]->userId());
        self::assertSame([], $auditEvidence->all()[0]->context());
        self::assertCount(1, $events->events());
        self::assertInstanceOf(PasswordResetCompleted::class, $events->events()[0]);
        self::assertSame($user->getId(), $events->events()[0]->getUserId());
        self::assertSame('2026-08-19T12:00:00+00:00', $events->events()[0]->getCompletedAt()->format(DATE_ATOM));
        self::assertSame(
            $events->events()[0]->toArray(),
            PasswordResetCompleted::fromArray($events->events()[0]->toArray())->toArray()
        );
        self::assertStringNotContainsString(self::RESET_CREDENTIAL, serialize($events->events()[0]->toArray()));
        self::assertStringNotContainsString(
            'a sufficiently long replacement password',
            serialize($events->events()[0]->toArray())
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
        $users = new InMemoryUserRepository($unitOfWork);
        $passwordResetGrants = new InMemoryPasswordResetGrants(
            $unitOfWork,
            replaceConsumedSucceeds: false
        );
        $sessions = new InMemoryRefreshSessionRepository($unitOfWork);
        $auditEvidence = new InMemoryAuditEvidenceRepository($unitOfWork);
        $events = new InMemoryEventDispatcher();
        $user = $this->activeUserFor('reset-conflict@example.test');
        $users->add($user);
        $originalPasswordHash = $user->getPasswordHash()?->toString();
        $passwordResetGrant = PasswordResetGrant::issue(
            $user->getId(),
            PasswordResetCredential::fromString(self::RESET_CREDENTIAL),
            new DateTimeImmutable('2026-08-19T11:00:00+00:00'),
            new DateTimeImmutable('2026-08-19T13:00:00+00:00'),
            $user->getEmail(),
            'ciphertext:reset-once'
        );
        $passwordResetGrants->add($passwordResetGrant);
        $refreshSession = $this->session($user, $this->refreshCredential(), false);
        $sessions->add($refreshSession);
        $service = $this->service(
            $users,
            new InMemoryActivationGrantRepository($unitOfWork),
            $sessions,
            $unitOfWork,
            $events,
            passwordResetGrantRepository: $passwordResetGrants,
            auditEvidenceRepository: $auditEvidence
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
        self::assertSame(0, $user->getAuthenticationAuthorityRevision());
        self::assertSame([$passwordResetGrant], $passwordResetGrants->all());
        self::assertTrue($passwordResetGrant->isIssued());
        self::assertSame([$refreshSession], $sessions->all());
        self::assertFalse($refreshSession->isRevoked());
        self::assertSame([], $auditEvidence->all());
        self::assertCount(1, $events->events());
        self::assertInstanceOf(RedactedCommandFailed::class, $events->events()[0]);
        self::assertSame('Password reset rejected.', $events->events()[0]->getErrorMessage());
    }

    public function test_that_password_reset_rejects_concurrent_authentication_authority_change(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $users = new InMemoryUserRepository(
            $unitOfWork,
            replaceAuthenticationAuthoritySucceeds: false
        );
        $passwordResetGrants = new InMemoryPasswordResetGrants($unitOfWork);
        $sessions = new InMemoryRefreshSessionRepository($unitOfWork);
        $auditEvidence = new InMemoryAuditEvidenceRepository($unitOfWork);
        $events = new InMemoryEventDispatcher();
        $user = $this->activeUserFor('reset-authority-conflict@example.test');
        $users->add($user);
        $originalPasswordHash = $user->getPasswordHash()?->toString();
        $passwordResetGrant = PasswordResetGrant::issue(
            $user->getId(),
            PasswordResetCredential::fromString(self::RESET_CREDENTIAL),
            new DateTimeImmutable('2026-08-19T11:00:00+00:00'),
            new DateTimeImmutable('2026-08-19T13:00:00+00:00'),
            $user->getEmail(),
            'ciphertext:reset-once'
        );
        $passwordResetGrants->add($passwordResetGrant);
        $refreshSession = $this->session($user, $this->refreshCredential(), false);
        $sessions->add($refreshSession);
        $service = $this->service(
            $users,
            new InMemoryActivationGrantRepository($unitOfWork),
            $sessions,
            $unitOfWork,
            $events,
            passwordResetGrantRepository: $passwordResetGrants,
            auditEvidenceRepository: $auditEvidence
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

        self::assertSame($originalPasswordHash, $users->getById($user->getId())?->getPasswordHash()?->toString());
        self::assertSame(1, $users->getById($user->getId())?->getAuthenticationVersion());
        self::assertSame([$passwordResetGrant], $passwordResetGrants->all());
        self::assertTrue($passwordResetGrant->isIssued());
        self::assertSame([$refreshSession], $sessions->all());
        self::assertFalse($refreshSession->isRevoked());
        self::assertSame([], $auditEvidence->all());
        self::assertCount(1, $events->events());
        self::assertInstanceOf(RedactedCommandFailed::class, $events->events()[0]);
        self::assertSame(
            ['user_id' => $user->getId()->toString()],
            $events->events()[0]->getRedactedCommandData()
        );
    }

    #[DataProvider('nonAuthoritativePasswordResetDeliveryCases')]
    public function test_that_password_reset_succeeds_without_delivery_authority(bool $hasInvalidatedDelivery): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $users = new InMemoryUserRepository($unitOfWork);
        $passwordResetGrants = new InMemoryPasswordResetGrants($unitOfWork);
        $sessions = new InMemoryRefreshSessionRepository($unitOfWork);
        $auditEvidence = new InMemoryAuditEvidenceRepository($unitOfWork);
        $events = new InMemoryEventDispatcher();
        $user = $this->activeUserFor('reset-without-delivery@example.test');
        $users->add($user);
        $issuedPasswordResetGrant = PasswordResetGrant::issue(
            $user->getId(),
            PasswordResetCredential::fromString(self::RESET_CREDENTIAL),
            new DateTimeImmutable('2026-08-19T11:00:00+00:00'),
            new DateTimeImmutable('2026-08-19T13:00:00+00:00'),
            $user->getEmail(),
            'ciphertext:reset-once'
        );
        $passwordResetGrant = $issuedPasswordResetGrant;
        if ($hasInvalidatedDelivery) {
            $passwordResetGrant = $passwordResetGrant->invalidateDelivery();
        }

        self::assertTrue($passwordResetGrants->add($issuedPasswordResetGrant));
        if ($passwordResetGrant !== $issuedPasswordResetGrant) {
            self::assertTrue($passwordResetGrants->replace($issuedPasswordResetGrant, $passwordResetGrant));
        }

        $refreshSession = $this->session($user, $this->refreshCredential(), false);
        $sessions->add($refreshSession);
        $service = $this->service(
            $users,
            new InMemoryActivationGrantRepository($unitOfWork),
            $sessions,
            $unitOfWork,
            $events,
            passwordResetGrantRepository: $passwordResetGrants,
            auditEvidenceRepository: $auditEvidence
        );

        $service->resetPassword(
            $user->getId(),
            self::RESET_CREDENTIAL,
            'a sufficiently long replacement password'
        );

        $authoritativeUser = $users->getById($user->getId());
        self::assertInstanceOf(User::class, $authoritativeUser);
        self::assertSame(1, $unitOfWork->transactions);
        self::assertTrue(password_verify(
            'a sufficiently long replacement password',
            $authoritativeUser->getPasswordHash()?->toString() ?? ''
        ));
        self::assertSame(2, $authoritativeUser->getAuthenticationVersion());
        self::assertSame(1, $authoritativeUser->getAuthenticationAuthorityRevision());
        self::assertTrue($passwordResetGrants->all()[0]->isConsumed());
        self::assertTrue($sessions->all()[0]->isRevoked());
        self::assertFalse($passwordResetGrants->all()[0]->getDelivery()->isRecoverable());

        self::assertSame('user.password_reset_completed', $auditEvidence->all()[0]->action());
        self::assertCount(1, $events->events());
        self::assertInstanceOf(PasswordResetCompleted::class, $events->events()[0]);
    }

    #[DataProvider('rejectedPasswordResetAuthority')]
    public function test_that_password_reset_failures_are_generic_redacted_and_leave_authority_unchanged(
        string $scenario,
        string $presentedCredential
    ): void {
        $unitOfWork = new InMemoryUnitOfWork();
        $users = new InMemoryUserRepository($unitOfWork);
        $passwordResetGrants = new InMemoryPasswordResetGrants($unitOfWork);
        $sessions = new InMemoryRefreshSessionRepository($unitOfWork);
        $auditEvidence = new InMemoryAuditEvidenceRepository($unitOfWork);
        $user = $this->activeUserFor('reset-rejected@example.test');
        if ($scenario !== 'missing user authority') {
            $users->add($user);
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
            'ciphertext:reset-once'
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
            self::assertTrue($passwordResetGrants->add($issuedPasswordResetGrant));
            if ($passwordResetGrant !== $issuedPasswordResetGrant) {
                self::assertTrue($passwordResetGrants->replace($issuedPasswordResetGrant, $passwordResetGrant));
            }
        }

        $refreshSession = $this->session($user, $this->refreshCredential(), false);
        $sessions->add($refreshSession);
        $events = new InMemoryEventDispatcher();
        $service = $this->service(
            $users,
            new InMemoryActivationGrantRepository($unitOfWork),
            $sessions,
            $unitOfWork,
            $events,
            passwordResetGrantRepository: $passwordResetGrants,
            auditEvidenceRepository: $auditEvidence
        );

        try {
            $service->resetPassword(
                $user->getId(),
                $presentedCredential,
                'a sufficiently long rejected replacement password'
            );
            self::fail('Expected password reset rejection.');
        } catch (DomainException $domainException) {
            self::assertInstanceOf(PasswordResetRejectedException::class, $domainException);
            self::assertSame('Password reset rejected.', $domainException->getMessage());
        }

        self::assertSame(1, $unitOfWork->transactions);
        self::assertSame($originalPasswordHash, $user->getPasswordHash()?->toString());
        self::assertSame(1, $user->getAuthenticationVersion());
        self::assertSame(
            $scenario === 'missing grant authority' ? [] : [$passwordResetGrant],
            $passwordResetGrants->all()
        );
        self::assertSame([$refreshSession], $sessions->all());
        self::assertFalse($refreshSession->isRevoked());
        self::assertSame([], $auditEvidence->all());
        self::assertCount(1, $events->events());
        self::assertInstanceOf(RedactedCommandFailed::class, $events->events()[0]);
        self::assertSame(
            AuthenticationService::class.'::resetPassword',
            $events->events()[0]->getCommandClass()
        );
        self::assertSame(
            ['user_id' => $user->getId()->toString()],
            $events->events()[0]->getRedactedCommandData()
        );
        self::assertSame('Password reset rejected.', $events->events()[0]->getErrorMessage());
        if ($presentedCredential !== '') {
            self::assertStringNotContainsString(
                $presentedCredential,
                serialize($events->events()[0]->toArray())
            );
        }

        self::assertStringNotContainsString(
            'a sufficiently long rejected replacement password',
            serialize($events->events()[0]->toArray())
        );
    }

    public function test_that_a_late_password_reset_audit_failure_rolls_back_every_staged_authority_change(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $userState = new InMemoryUserRepositoryState();
        $users = new InMemoryUserRepository($unitOfWork, state: $userState);
        $passwordResetGrants = new InMemoryPasswordResetGrants($unitOfWork);
        $sessions = new InMemoryRefreshSessionRepository($unitOfWork);
        $auditEvidence = new InMemoryAuditEvidenceRepository($unitOfWork, failAfterSave: true);
        $user = $this->activeUserFor('reset-rollback@example.test');
        $users->add($user);
        $originalPasswordHash = $user->getPasswordHash()?->toString();
        $expiresAt = new DateTimeImmutable('2026-08-19T13:00:00+00:00');
        $passwordResetGrant = PasswordResetGrant::issue(
            $user->getId(),
            PasswordResetCredential::fromString(self::RESET_CREDENTIAL),
            new DateTimeImmutable('2026-08-19T11:00:00+00:00'),
            $expiresAt,
            $user->getEmail(),
            'ciphertext:reset-once'
        );
        $firstSession = $this->session($user, $this->refreshCredential(), false);
        $secondSession = $this->session(
            $user,
            RefreshCredential::fromString(self::SIBLING_CREDENTIAL),
            true
        );
        $passwordResetGrants->add($passwordResetGrant);
        $sessions->add($firstSession);
        $sessions->add($secondSession);

        $events = new InMemoryEventDispatcher();
        $service = $this->service(
            $users,
            new InMemoryActivationGrantRepository($unitOfWork),
            $sessions,
            $unitOfWork,
            $events,
            passwordResetGrantRepository: $passwordResetGrants,
            auditEvidenceRepository: $auditEvidence
        );

        try {
            $service->resetPassword(
                $user->getId(),
                self::RESET_CREDENTIAL,
                'a sufficiently long rolled back password'
            );
            self::fail('Expected audit persistence failure.');
        } catch (RuntimeException $runtimeException) {
            self::assertSame($auditEvidence->failure(), $runtimeException);
            self::assertSame('The audit persistence write failed.', $runtimeException->getMessage());
        }

        self::assertSame(1, $unitOfWork->transactions);
        self::assertSame($originalPasswordHash, $user->getPasswordHash()?->toString());
        self::assertSame(1, $user->getAuthenticationVersion());
        self::assertSame(0, $user->getAuthenticationAuthorityRevision());
        self::assertFalse($userState->isAuthenticationAuthorityFenceHeld($user->getId()));
        self::assertSame([$passwordResetGrant], $passwordResetGrants->all());
        self::assertFalse($passwordResetGrants->all()[0]->isConsumed());
        self::assertTrue($passwordResetGrants->all()[0]->getDelivery()->isRecoverable());
        self::assertSame([$firstSession, $secondSession], $sessions->all());
        self::assertFalse($firstSession->isRevoked());
        self::assertFalse($secondSession->isRevoked());
        self::assertSame([], $auditEvidence->all());
        self::assertCount(1, $events->events());
        self::assertInstanceOf(RedactedCommandFailed::class, $events->events()[0]);
        self::assertSame(
            AuthenticationService::class.'::resetPassword',
            $events->events()[0]->getCommandClass()
        );
        self::assertSame(
            ['user_id' => $user->getId()->toString()],
            $events->events()[0]->getRedactedCommandData()
        );
        self::assertSame('The audit persistence write failed.', $events->events()[0]->getErrorMessage());
        self::assertStringNotContainsString(self::RESET_CREDENTIAL, serialize($events->events()[0]->toArray()));
        self::assertStringNotContainsString(
            'a sufficiently long rolled back password',
            serialize($events->events()[0]->toArray())
        );
    }

    #[DataProvider('rejectedLoginStates')]
    public function test_that_all_ineligible_login_states_verify_once_and_fail_generically(
        ?User $user,
        bool $throttleAllows
    ): void {
        $unitOfWork = new InMemoryUnitOfWork();
        $users = new InMemoryUserRepository($unitOfWork);
        $events = new InMemoryEventDispatcher();
        $email = 'unknown@example.test';
        if ($user instanceof User) {
            $users->add($user);
            $email = $user->getEmail()->canonical();
        }

        $passwordValidator = new class implements PasswordValidator {
            public int $validations = 0;

            public function validate(string $password, string $hash): bool
            {
                ++$this->validations;

                return false;
            }

            public function needsRehash(string $hash): bool
            {
                return false;
            }
        };
        $service = $this->service(
            $users,
            new InMemoryActivationGrantRepository($unitOfWork),
            new InMemoryRefreshSessionRepository($unitOfWork),
            $unitOfWork,
            $events,
            loginThrottle: new FixedLoginThrottle($throttleAllows),
            passwordValidator: $passwordValidator
        );

        $this->expectException(LoginRejectedException::class);
        $this->expectExceptionMessage('Login rejected.');
        try {
            $service->login($email, 'wrong-secret');
        } finally {
            self::assertSame(1, $passwordValidator->validations);
            self::assertInstanceOf(RedactedCommandFailed::class, $events->events()[0]);
            self::assertSame([], $events->events()[0]->getRedactedCommandData());
            self::assertStringNotContainsString('wrong-secret', serialize($events->events()[0]->toArray()));
        }
    }

    #[DataProvider('refreshLifetimeCases')]
    public function test_that_refresh_rotates_once_without_changing_access_authority_or_absolute_lifetime(
        bool $remembered,
        string $expectedIdleExpiry,
        string $expectedAbsoluteExpiry
    ): void {
        $unitOfWork = new InMemoryUnitOfWork();
        $users = new InMemoryUserRepository($unitOfWork);
        $sessions = new InMemoryRefreshSessionRepository($unitOfWork);
        $user = $this->activeUserFor('refresh@example.test');
        $users->add($user);
        $session = $this->session($user, $this->refreshCredential(), $remembered);
        $sessions->add($session);
        $rotatedCredential = RefreshCredential::fromString(self::ROTATED_CREDENTIAL);
        $credentialGenerator = new class ($rotatedCredential) implements RefreshCredentialGenerator {
            public int $calls = 0;

            public function __construct(private readonly RefreshCredential $credential)
            {
            }

            public function generate(): RefreshCredential
            {
                ++$this->calls;

                return $this->credential;
            }
        };
        $tokenEncoder = new RecordingTokenEncoder();
        $service = $this->service(
            $users,
            new InMemoryActivationGrantRepository($unitOfWork),
            $sessions,
            $unitOfWork,
            new InMemoryEventDispatcher(),
            tokenEncoder: $tokenEncoder,
            refreshCredentialGenerator: $credentialGenerator
        );

        $refreshResult = $service->refresh(self::REFRESH_CREDENTIAL);
        $tokenSet = $refreshResult->getTokenSet();

        $rotatedSession = $sessions->getById($session->getId());
        self::assertInstanceOf(RefreshSession::class, $rotatedSession);
        self::assertSame(RefreshOutcome::ROTATED, $refreshResult->getOutcome());
        self::assertInstanceOf(TokenSet::class, $tokenSet);
        self::assertSame(1, $credentialGenerator->calls);
        self::assertSame(self::ROTATED_CREDENTIAL, $tokenSet->getRefreshCredential()->toString());
        self::assertSame($rotatedSession, $sessions->getByCredential($rotatedCredential));
        self::assertNull($sessions->getByCredential($this->refreshCredential()));
        self::assertEquals(new DateTimeImmutable('2026-08-19T12:00:00+00:00'), $rotatedSession->getLastActivityAt());
        self::assertEquals(new DateTimeImmutable($expectedIdleExpiry), $rotatedSession->getIdleExpiresAt());
        self::assertEquals(new DateTimeImmutable($expectedAbsoluteExpiry), $rotatedSession->getAbsoluteExpiresAt());
        self::assertSame($remembered, $rotatedSession->isRemembered());
        self::assertSame(1, $unitOfWork->transactions);
        self::assertSame($user->getId()->toString(), $tokenEncoder->claims['sub']);
        self::assertSame($session->getId()->toString(), $tokenEncoder->claims['sid']);
        self::assertSame(1, $tokenEncoder->claims['auth_version']);
        self::assertEquals(new DateTimeImmutable('2026-08-19T12:15:00+00:00'), $tokenEncoder->expiration);
        $this->assertTokenSet($tokenSet, $user, $rotatedSession, $remembered);
        self::assertFalse($session->isRevoked());
    }

    public function test_that_immediately_previous_refresh_credential_returns_a_secretless_conflict(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $users = new InMemoryUserRepository($unitOfWork);
        $sessions = new InMemoryRefreshSessionRepository($unitOfWork);
        $events = new InMemoryEventDispatcher();
        $user = $this->activeUserFor('refresh-conflict@example.test');
        $users->add($user);
        $sessions->add($this->session($user, $this->refreshCredential(), false));
        $service = $this->service(
            $users,
            new InMemoryActivationGrantRepository($unitOfWork),
            $sessions,
            $unitOfWork,
            $events,
            tokenPolicy: AuthenticationTokenPolicy::starterDefaults(new DateInterval('PT5S')),
            refreshCredentialGenerator: new FixedRefreshCredentialGenerator(
                RefreshCredential::fromString(self::ROTATED_CREDENTIAL)
            )
        );

        $winner = $service->refresh(self::REFRESH_CREDENTIAL);
        $conflict = $service->refresh(self::REFRESH_CREDENTIAL);

        self::assertSame(RefreshOutcome::ROTATED, $winner->getOutcome());
        self::assertNotNull($winner->getTokenSet());
        self::assertSame(RefreshOutcome::CONFLICT, $conflict->getOutcome());
        self::assertNull($conflict->getTokenSet());
        self::assertSame([], $events->events());
    }

    public function test_that_refresh_replay_outside_the_conflict_window_revokes_the_session_before_failing(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $users = new InMemoryUserRepository($unitOfWork);
        $sessions = new InMemoryRefreshSessionRepository($unitOfWork);
        $events = new InMemoryEventDispatcher();
        $user = $this->activeUserFor('refresh-replay@example.test');
        $users->add($user);
        $session = $this->session($user, $this->refreshCredential(), false)->rotate(
            RefreshCredential::fromString(self::ROTATED_CREDENTIAL),
            new DateTimeImmutable('2026-08-19T11:59:54+00:00'),
            new DateTimeImmutable('2026-08-20T11:59:54+00:00')
        );
        $sessions->add($session);
        $credentialGenerator = new class () implements RefreshCredentialGenerator {
            public int $calls = 0;

            public function generate(): RefreshCredential
            {
                ++$this->calls;

                return RefreshCredential::fromString(
                    '2222222222222222222222222222222222222222222222222222222222222222'
                );
            }
        };
        $service = $this->service(
            $users,
            new InMemoryActivationGrantRepository($unitOfWork),
            $sessions,
            $unitOfWork,
            $events,
            tokenPolicy: AuthenticationTokenPolicy::starterDefaults(new DateInterval('PT5S')),
            refreshCredentialGenerator: $credentialGenerator
        );

        try {
            $service->refresh(self::REFRESH_CREDENTIAL);
            self::fail('Expected replay outside the conflict window to fail.');
        } catch (RefreshSessionNotFoundException $refreshSessionNotFoundException) {
            self::assertSame(
                'The refresh session is not authoritative.',
                $refreshSessionNotFoundException->getMessage()
            );
        }

        self::assertTrue($unitOfWork->transactionCompleted);
        self::assertTrue($sessions->getById($session->getId())?->isRevoked());

        try {
            $service->refresh(self::ROTATED_CREDENTIAL);
            self::fail('Expected a compromised session to remain unusable.');
        } catch (RefreshSessionNotFoundException $refreshSessionNotFoundException) {
            self::assertSame(
                'The refresh session is not authoritative.',
                $refreshSessionNotFoundException->getMessage()
            );
        }

        self::assertSame(0, $credentialGenerator->calls);
        self::assertCount(2, $events->events());
        foreach ($events->events() as $event) {
            self::assertInstanceOf(RedactedCommandFailed::class, $event);
            self::assertSame(AuthenticationService::class.'::refresh', $event->getCommandClass());
            self::assertSame([], $event->getRedactedCommandData());
            self::assertStringNotContainsString(self::REFRESH_CREDENTIAL, serialize($event->toArray()));
            self::assertStringNotContainsString(self::ROTATED_CREDENTIAL, serialize($event->toArray()));
        }
    }

    public function test_that_older_used_credential_replay_revokes_the_authoritative_session_family(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $users = new InMemoryUserRepository($unitOfWork);
        $sessions = new InMemoryRefreshSessionRepository($unitOfWork);
        $events = new InMemoryEventDispatcher();
        $user = $this->activeUserFor('older-refresh-replay@example.test');
        $users->add($user);
        $session = $this->session($user, $this->refreshCredential(), false);
        $sessions->add($session);
        $credentialGenerator = new class ([
            RefreshCredential::fromString(self::ROTATED_CREDENTIAL),
            RefreshCredential::fromString(self::SECOND_ROTATED_CREDENTIAL),
        ]) implements RefreshCredentialGenerator {
            public int $calls = 0;

            /**
             * @param list<RefreshCredential> $credentials
             */
            public function __construct(private readonly array $credentials)
            {
            }

            public function generate(): RefreshCredential
            {
                $credential = $this->credentials[$this->calls];
                ++$this->calls;

                return $credential;
            }
        };
        $service = $this->service(
            $users,
            new InMemoryActivationGrantRepository($unitOfWork),
            $sessions,
            $unitOfWork,
            $events,
            refreshCredentialGenerator: $credentialGenerator
        );

        $firstRotation = $service->refresh(self::REFRESH_CREDENTIAL);
        $secondRotation = $service->refresh(self::ROTATED_CREDENTIAL);

        self::assertSame(RefreshOutcome::ROTATED, $firstRotation->getOutcome());
        self::assertSame(RefreshOutcome::ROTATED, $secondRotation->getOutcome());
        self::assertSame(
            self::SECOND_ROTATED_CREDENTIAL,
            $secondRotation->getTokenSet()?->getRefreshCredential()->toString()
        );

        try {
            $service->refresh(self::REFRESH_CREDENTIAL);
            self::fail('Expected an older used credential to revoke the session family.');
        } catch (RefreshSessionNotFoundException $refreshSessionNotFoundException) {
            self::assertSame(
                'The refresh session is not authoritative.',
                $refreshSessionNotFoundException->getMessage()
            );
        }

        $authoritativeSession = $sessions->getById($session->getId());
        self::assertInstanceOf(RefreshSession::class, $authoritativeSession);
        self::assertTrue($authoritativeSession->isRevoked());

        try {
            $service->refresh(self::SECOND_ROTATED_CREDENTIAL);
            self::fail('Expected the latest credential in a compromised family to remain unusable.');
        } catch (RefreshSessionNotFoundException $refreshSessionNotFoundException) {
            self::assertSame(
                'The refresh session is not authoritative.',
                $refreshSessionNotFoundException->getMessage()
            );
        }

        self::assertSame(2, $credentialGenerator->calls);
        self::assertCount(2, $events->events());
        foreach ($events->events() as $event) {
            self::assertInstanceOf(RedactedCommandFailed::class, $event);
            self::assertSame(AuthenticationService::class.'::refresh', $event->getCommandClass());
            self::assertSame([], $event->getRedactedCommandData());
            self::assertStringNotContainsString(self::REFRESH_CREDENTIAL, serialize($event->toArray()));
            self::assertStringNotContainsString(self::SECOND_ROTATED_CREDENTIAL, serialize($event->toArray()));
        }
    }

    public function test_that_expired_and_already_revoked_sessions_cannot_refresh(): void
    {
        foreach (['expired', 'revoked'] as $terminalState) {
            $unitOfWork = new InMemoryUnitOfWork();
            $users = new InMemoryUserRepository($unitOfWork);
            $sessions = new InMemoryRefreshSessionRepository($unitOfWork);
            $events = new InMemoryEventDispatcher();
            $user = $this->activeUserFor($terminalState.'-refresh@example.test');
            $users->add($user);
            $session = RefreshSession::start(
                RefreshSessionId::generate(),
                $user->getId(),
                $this->refreshCredential(),
                new DateTimeImmutable('2026-08-19T10:00:00+00:00'),
                new DateTimeImmutable(
                    $terminalState === 'expired' ? '2026-08-19T11:59:59+00:00' : '2026-08-20T10:00:00+00:00'
                ),
                new DateTimeImmutable('2026-08-21T10:00:00+00:00'),
                $user->getAuthenticationVersion(),
                false
            );
            if ($terminalState === 'revoked') {
                $session = $session->revoke();
            }

            $sessions->add($session);
            $credentialGenerator = new class () implements RefreshCredentialGenerator {
                public int $calls = 0;

                public function generate(): RefreshCredential
                {
                    ++$this->calls;

                    return RefreshCredential::fromString(
                        '2222222222222222222222222222222222222222222222222222222222222222'
                    );
                }
            };
            $service = $this->service(
                $users,
                new InMemoryActivationGrantRepository($unitOfWork),
                $sessions,
                $unitOfWork,
                $events,
                refreshCredentialGenerator: $credentialGenerator
            );

            try {
                $service->refresh(self::REFRESH_CREDENTIAL);
                self::fail('Expected a terminal refresh session to remain unusable.');
            } catch (RefreshSessionNotFoundException $exception) {
                self::assertSame('The refresh session is not authoritative.', $exception->getMessage());
            }

            self::assertSame(0, $credentialGenerator->calls);
            self::assertCount(1, $events->events());
            self::assertInstanceOf(RedactedCommandFailed::class, $events->events()[0]);
            self::assertSame([], $events->events()[0]->getRedactedCommandData());
        }
    }

    public function test_that_an_interleaved_stale_refresh_cannot_become_a_second_winner(): void
    {
        $users = new InMemoryUserRepository();
        $storedSessions = new InMemoryRefreshSessionRepository();
        $user = $this->activeUserFor('refresh-race@example.test');
        $users->add($user);
        $originalSession = $this->session($user, $this->refreshCredential(), false);
        $storedSessions->add($originalSession);
        $racingSessions = new class ($storedSessions) implements RefreshSessionRepository {
            public bool $interleaveNextReplace = false;

            public ?Closure $beforeReplace = null;

            public function __construct(private readonly InMemoryRefreshSessionRepository $sessions)
            {
            }

            public function add(RefreshSession $refreshSession): void
            {
                $this->sessions->add($refreshSession);
            }

            public function getById(RefreshSessionId $id): ?RefreshSession
            {
                return $this->sessions->getById($id);
            }

            public function getByUserId(
                UserId $userId,
                DateTimeImmutable $at,
                Pagination $pagination
            ): ResultSet {
                return $this->sessions->getByUserId($userId, $at, $pagination);
            }

            public function getAllActiveByUserId(UserId $userId, DateTimeImmutable $at): array
            {
                return $this->sessions->getAllActiveByUserId($userId, $at);
            }

            public function getByCredential(RefreshCredential $refreshCredential): ?RefreshSession
            {
                return $this->sessions->getByCredential($refreshCredential);
            }

            public function getByUsedCredential(RefreshCredential $refreshCredential): ?RefreshSession
            {
                return $this->sessions->getByUsedCredential($refreshCredential);
            }

            public function replace(RefreshSession $expected, RefreshSession $replacement): bool
            {
                if ($this->interleaveNextReplace && $this->beforeReplace instanceof Closure) {
                    $this->interleaveNextReplace = false;
                    ($this->beforeReplace)();
                }

                return $this->sessions->replace($expected, $replacement);
            }
        };
        $winnerEvents = new InMemoryEventDispatcher();
        $loserEvents = new InMemoryEventDispatcher();
        $winnerService = $this->service(
            $users,
            new InMemoryActivationGrantRepository(),
            $racingSessions,
            new InMemoryUnitOfWork(),
            $winnerEvents,
            authenticationClock: new FixedAuthenticationClock(
                new DateTimeImmutable('2026-08-19T12:00:01+00:00')
            ),
            refreshCredentialGenerator: new FixedRefreshCredentialGenerator(
                RefreshCredential::fromString(self::WINNER_CREDENTIAL)
            )
        );
        $loserClock = new class implements AuthenticationClock {
            private int $observations = 0;

            public function now(): DateTimeImmutable
            {
                $observedAt = '2026-08-19T12:00:00+00:00';
                if ($this->observations > 0) {
                    $observedAt = '2026-08-19T12:00:02+00:00';
                }

                ++$this->observations;

                return new DateTimeImmutable($observedAt);
            }
        };
        $loserService = $this->service(
            $users,
            new InMemoryActivationGrantRepository(),
            $racingSessions,
            new InMemoryUnitOfWork(),
            $loserEvents,
            authenticationClock: $loserClock,
            refreshCredentialGenerator: new FixedRefreshCredentialGenerator(
                RefreshCredential::fromString(self::ROTATED_CREDENTIAL)
            )
        );
        $winner = null;
        $racingSessions->beforeReplace = static function () use (&$winner, $winnerService): void {
            $winner = $winnerService->refresh(self::REFRESH_CREDENTIAL);
        };
        $racingSessions->interleaveNextReplace = true;

        $conflict = $loserService->refresh(self::REFRESH_CREDENTIAL);

        self::assertInstanceOf(RefreshResult::class, $winner);
        self::assertSame(RefreshOutcome::ROTATED, $winner->getOutcome());
        self::assertSame(
            self::WINNER_CREDENTIAL,
            $winner->getTokenSet()?->getRefreshCredential()->toString()
        );
        self::assertSame(RefreshOutcome::CONFLICT, $conflict->getOutcome());
        self::assertNull($conflict->getTokenSet());
        self::assertTrue($storedSessions->getById($originalSession->getId())?->matchesCredential(
            RefreshCredential::fromString(self::WINNER_CREDENTIAL)
        ));
        self::assertNull($storedSessions->getByCredential(
            RefreshCredential::fromString(self::ROTATED_CREDENTIAL)
        ));
        self::assertFalse($storedSessions->getById($originalSession->getId())->isRevoked());
        self::assertSame([], $winnerEvents->events());
        self::assertSame([], $loserEvents->events());
    }

    public function test_that_stale_rotation_cannot_resurrect_a_concurrently_revoked_session(): void
    {
        $users = new InMemoryUserRepository();
        $storedSessions = new InMemoryRefreshSessionRepository();
        $events = new InMemoryEventDispatcher();
        $user = $this->activeUserFor('refresh-revocation-race@example.test');
        $users->add($user);
        $originalSession = $this->session($user, $this->refreshCredential(), false);
        $storedSessions->add($originalSession);
        $racingSessions = new class ($storedSessions) implements RefreshSessionRepository {
            public bool $revokedDuringReplace = false;

            public function __construct(private readonly InMemoryRefreshSessionRepository $sessions)
            {
            }

            public function add(RefreshSession $refreshSession): void
            {
                $this->sessions->add($refreshSession);
            }

            public function getById(RefreshSessionId $id): ?RefreshSession
            {
                return $this->sessions->getById($id);
            }

            public function getByUserId(
                UserId $userId,
                DateTimeImmutable $at,
                Pagination $pagination
            ): ResultSet {
                return $this->sessions->getByUserId($userId, $at, $pagination);
            }

            public function getAllActiveByUserId(UserId $userId, DateTimeImmutable $at): array
            {
                return $this->sessions->getAllActiveByUserId($userId, $at);
            }

            public function getByCredential(RefreshCredential $refreshCredential): ?RefreshSession
            {
                return $this->sessions->getByCredential($refreshCredential);
            }

            public function getByUsedCredential(RefreshCredential $refreshCredential): ?RefreshSession
            {
                return $this->sessions->getByUsedCredential($refreshCredential);
            }

            public function replace(RefreshSession $expected, RefreshSession $replacement): bool
            {
                if (!$this->revokedDuringReplace) {
                    $this->revokedDuringReplace = true;
                    $concurrentlyRevoked = $expected->revoke();
                    if (!$this->sessions->replace($expected, $concurrentlyRevoked)) {
                        throw new LogicException('Expected the interleaved revocation to win.');
                    }
                }

                return $this->sessions->replace($expected, $replacement);
            }
        };
        $service = $this->service(
            $users,
            new InMemoryActivationGrantRepository(),
            $racingSessions,
            new InMemoryUnitOfWork(),
            $events,
            refreshCredentialGenerator: new FixedRefreshCredentialGenerator(
                RefreshCredential::fromString(self::ROTATED_CREDENTIAL)
            )
        );

        try {
            $service->refresh(self::REFRESH_CREDENTIAL);
            self::fail('Expected the concurrent revocation to remain authoritative.');
        } catch (RefreshSessionNotFoundException $refreshSessionNotFoundException) {
            self::assertSame(
                'The refresh session is not authoritative.',
                $refreshSessionNotFoundException->getMessage()
            );
        }

        $authoritativeSession = $storedSessions->getById($originalSession->getId());
        self::assertInstanceOf(RefreshSession::class, $authoritativeSession);
        self::assertTrue($authoritativeSession->isRevoked());
        self::assertTrue($authoritativeSession->matchesCredential($this->refreshCredential()));
        self::assertNull($storedSessions->getByCredential(RefreshCredential::fromString(self::ROTATED_CREDENTIAL)));
        self::assertTrue($racingSessions->revokedDuringReplace);
        self::assertCount(1, $events->events());
        self::assertInstanceOf(RedactedCommandFailed::class, $events->events()[0]);
    }

    public function test_that_non_conflicting_cas_contention_revokes_the_latest_authoritative_session(): void
    {
        $users = new InMemoryUserRepository();
        $storedSessions = new InMemoryRefreshSessionRepository();
        $events = new InMemoryEventDispatcher();
        $user = $this->activeUserFor('refresh-terminal-race@example.test');
        $users->add($user);
        $originalSession = $this->session($user, $this->refreshCredential(), false);
        $storedSessions->add($originalSession);
        $racingSessions = new class ($storedSessions) implements RefreshSessionRepository {
            public int $contentions = 0;

            public function __construct(private readonly InMemoryRefreshSessionRepository $sessions)
            {
            }

            public function add(RefreshSession $refreshSession): void
            {
                $this->sessions->add($refreshSession);
            }

            public function getById(RefreshSessionId $id): ?RefreshSession
            {
                return $this->sessions->getById($id);
            }

            public function getByUserId(
                UserId $userId,
                DateTimeImmutable $at,
                Pagination $pagination
            ): ResultSet {
                return $this->sessions->getByUserId($userId, $at, $pagination);
            }

            public function getAllActiveByUserId(UserId $userId, DateTimeImmutable $at): array
            {
                return $this->sessions->getAllActiveByUserId($userId, $at);
            }

            public function getByCredential(RefreshCredential $refreshCredential): ?RefreshSession
            {
                return $this->sessions->getByCredential($refreshCredential);
            }

            public function getByUsedCredential(RefreshCredential $refreshCredential): ?RefreshSession
            {
                return $this->sessions->getByUsedCredential($refreshCredential);
            }

            public function replace(RefreshSession $expected, RefreshSession $replacement): bool
            {
                if ($this->contentions === 0) {
                    ++$this->contentions;
                    $firstWinner = $expected->rotate(
                        RefreshCredential::fromString(
                            '2222222222222222222222222222222222222222222222222222222222222222'
                        ),
                        new DateTimeImmutable('2026-08-19T12:00:00+00:00'),
                        new DateTimeImmutable('2026-08-20T12:00:00+00:00')
                    );
                    $secondWinner = $firstWinner->rotate(
                        RefreshCredential::fromString(
                            '3333333333333333333333333333333333333333333333333333333333333333'
                        ),
                        new DateTimeImmutable('2026-08-19T12:00:00+00:00'),
                        new DateTimeImmutable('2026-08-20T12:00:00+00:00')
                    );
                    if (
                        !$this->sessions->replace($expected, $firstWinner)
                        || !$this->sessions->replace($firstWinner, $secondWinner)
                    ) {
                        throw new LogicException('Expected both interleaved rotations to win in sequence.');
                    }

                    return false;
                }

                if ($this->contentions === 1 && $replacement->isRevoked()) {
                    ++$this->contentions;
                    $concurrentlyRevoked = $expected->revoke();
                    if (!$this->sessions->replace($expected, $concurrentlyRevoked)) {
                        throw new LogicException('Expected the interleaved revocation to win.');
                    }

                    return false;
                }

                return $this->sessions->replace($expected, $replacement);
            }
        };
        $service = $this->service(
            $users,
            new InMemoryActivationGrantRepository(),
            $racingSessions,
            new InMemoryUnitOfWork(),
            $events,
            refreshCredentialGenerator: new FixedRefreshCredentialGenerator(
                RefreshCredential::fromString(self::ROTATED_CREDENTIAL)
            )
        );

        try {
            $service->refresh(self::REFRESH_CREDENTIAL);
            self::fail('Expected non-conflicting stale contention to fail closed.');
        } catch (RefreshSessionNotFoundException $refreshSessionNotFoundException) {
            self::assertSame(
                'The refresh session is not authoritative.',
                $refreshSessionNotFoundException->getMessage()
            );
        }

        $authoritativeSession = $storedSessions->getById($originalSession->getId());
        self::assertInstanceOf(RefreshSession::class, $authoritativeSession);
        self::assertTrue($authoritativeSession->isRevoked());
        self::assertTrue($authoritativeSession->matchesCredential(RefreshCredential::fromString(
            '3333333333333333333333333333333333333333333333333333333333333333'
        )));
        self::assertNull($storedSessions->getByCredential(RefreshCredential::fromString(self::ROTATED_CREDENTIAL)));
        self::assertSame(2, $racingSessions->contentions);
        self::assertCount(1, $events->events());
        self::assertInstanceOf(RedactedCommandFailed::class, $events->events()[0]);
        self::assertSame([], $events->events()[0]->getRedactedCommandData());
    }

    public function test_that_refresh_rejects_an_authentication_version_mismatch_without_leaking_the_credential(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $users = new InMemoryUserRepository($unitOfWork);
        $sessions = new InMemoryRefreshSessionRepository($unitOfWork);
        $events = new InMemoryEventDispatcher();
        $userId = UserId::generate();
        $user = UserFixture::withIdAndAuthenticationVersion($userId, 'version@example.test', UserState::ACTIVE, 2);
        $users->add($user);
        $sessionOwner = $this->activeUserFor('session-owner@example.test', $userId);
        $sessions->add($this->session($sessionOwner, $this->refreshCredential(), false));
        $service = $this->service($users, new InMemoryActivationGrantRepository(), $sessions, $unitOfWork, $events);

        $this->expectException(RefreshSessionNotFoundException::class);
        try {
            $service->refresh(self::REFRESH_CREDENTIAL);
        } finally {
            self::assertInstanceOf(RedactedCommandFailed::class, $events->events()[0]);
            self::assertStringNotContainsString(self::REFRESH_CREDENTIAL, serialize($events->events()[0]->toArray()));
        }
    }

    public function test_that_logout_revokes_only_the_credential_selected_session(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $users = new InMemoryUserRepository($unitOfWork);
        $sessions = new InMemoryRefreshSessionRepository($unitOfWork);
        $events = new InMemoryEventDispatcher();
        $user = $this->activeUserFor('logout@example.test');
        $users->add($user);
        $current = $this->session($user, $this->refreshCredential(), false);
        $siblingCredential = RefreshCredential::fromString(self::SIBLING_CREDENTIAL);
        $sibling = $this->session($user, $siblingCredential, true);
        $sessions->add($current);
        $sessions->add($sibling);

        $service = $this->service(
            $users,
            new InMemoryActivationGrantRepository(),
            $sessions,
            $unitOfWork,
            $events,
            refreshCredentialGenerator: new FixedRefreshCredentialGenerator(
                RefreshCredential::fromString(self::ROTATED_CREDENTIAL)
            )
        );

        $service->logout(self::REFRESH_CREDENTIAL);

        $revokedCurrent = $sessions->getById($current->getId());
        self::assertInstanceOf(RefreshSession::class, $revokedCurrent);
        self::assertFalse($current->isRevoked());
        self::assertTrue($revokedCurrent->isRevoked());
        self::assertSame(1, $revokedCurrent->getRevision());
        self::assertFalse($sibling->isRevoked());
        self::assertInstanceOf(CurrentSessionLoggedOut::class, $events->events()[0]);
        self::assertSame($current->getId(), $events->events()[0]->getRefreshSessionId());
        $siblingRefreshResult = $service->refresh(self::SIBLING_CREDENTIAL);
        $siblingTokenSet = $siblingRefreshResult->getTokenSet();
        $rotatedSibling = $sessions->getById($sibling->getId());
        self::assertInstanceOf(RefreshSession::class, $rotatedSibling);
        self::assertInstanceOf(TokenSet::class, $siblingTokenSet);
        $this->assertTokenSet($siblingTokenSet, $user, $rotatedSibling, true);
    }

    public function test_that_missing_logout_credentials_fail_with_redacted_context(): void
    {
        $events = new InMemoryEventDispatcher();
        $service = $this->service(
            new InMemoryUserRepository(),
            new InMemoryActivationGrantRepository(),
            new InMemoryRefreshSessionRepository(),
            new InMemoryUnitOfWork(),
            $events
        );

        $this->expectException(RefreshSessionNotFoundException::class);
        try {
            $service->logout(self::REFRESH_CREDENTIAL);
        } finally {
            self::assertInstanceOf(RedactedCommandFailed::class, $events->events()[0]);
            self::assertSame(AuthenticationService::class.'::logout', $events->events()[0]->getCommandClass());
            self::assertSame([], $events->events()[0]->getRedactedCommandData());
        }
    }

    public function test_that_logout_fails_closed_when_the_session_disappears_during_revocation(): void
    {
        $events = new InMemoryEventDispatcher();
        $session = $this->session(
            $this->activeUserFor('logout-disappeared@example.test'),
            $this->refreshCredential(),
            false
        );
        $sessions = new readonly class ($session) implements RefreshSessionRepository {
            public function __construct(private RefreshSession $session)
            {
            }

            public function add(RefreshSession $refreshSession): void
            {
            }

            public function getById(RefreshSessionId $id): ?RefreshSession
            {
                return null;
            }

            public function getByUserId(
                UserId $userId,
                DateTimeImmutable $at,
                Pagination $pagination
            ): ResultSet {
                $refreshSessions = [];
                if ($this->session->getUserId()->equals($userId) && $this->session->isUsableAt($at)) {
                    $refreshSessions[] = $this->session;
                }

                return new ResultSet(
                    $pagination->page(),
                    $pagination->perPage(),
                    count($refreshSessions),
                    ArrayList::of(RefreshSession::class)->replace(array_slice(
                        $refreshSessions,
                        $pagination->offset(),
                        $pagination->limit()
                    ))
                );
            }

            public function getAllActiveByUserId(UserId $userId, DateTimeImmutable $at): array
            {
                if ($this->session->getUserId()->equals($userId) && $this->session->isUsableAt($at)) {
                    return [$this->session];
                }

                return [];
            }

            public function getByCredential(RefreshCredential $refreshCredential): ?RefreshSession
            {
                return $this->session->matchesCredential($refreshCredential) ? $this->session : null;
            }

            public function getByUsedCredential(RefreshCredential $refreshCredential): ?RefreshSession
            {
                return null;
            }

            public function replace(RefreshSession $expected, RefreshSession $replacement): bool
            {
                return false;
            }
        };
        $service = $this->service(
            new InMemoryUserRepository(),
            new InMemoryActivationGrantRepository(),
            $sessions,
            new InMemoryUnitOfWork(),
            $events
        );

        try {
            $service->logout(self::REFRESH_CREDENTIAL);
            self::fail('Expected disappeared session state to fail closed.');
        } catch (RefreshSessionNotFoundException $refreshSessionNotFoundException) {
            self::assertSame(
                'The refresh session is not authoritative.',
                $refreshSessionNotFoundException->getMessage()
            );
        }

        self::assertFalse($session->isRevoked());
        self::assertCount(1, $events->events());
        self::assertInstanceOf(RedactedCommandFailed::class, $events->events()[0]);
        self::assertSame(AuthenticationService::class.'::logout', $events->events()[0]->getCommandClass());
    }

    public function test_that_logout_fails_closed_after_bounded_authoritative_revision_contention(): void
    {
        $events = new InMemoryEventDispatcher();
        $session = $this->session(
            $this->activeUserFor('logout-contention@example.test'),
            $this->refreshCredential(),
            false
        );
        $sessions = new class ($session) implements RefreshSessionRepository {
            public int $authoritativeReads = 0;

            public int $replaceAttempts = 0;

            public function __construct(private RefreshSession $authoritativeSession)
            {
            }

            public function add(RefreshSession $refreshSession): void
            {
            }

            public function getById(RefreshSessionId $id): ?RefreshSession
            {
                if (!$this->authoritativeSession->getId()->equals($id)) {
                    return null;
                }

                ++$this->authoritativeReads;

                return $this->authoritativeSession;
            }

            public function getByUserId(
                UserId $userId,
                DateTimeImmutable $at,
                Pagination $pagination
            ): ResultSet {
                $refreshSessions = [];
                if (
                    $this->authoritativeSession->getUserId()->equals($userId)
                    && $this->authoritativeSession->isUsableAt($at)
                ) {
                    $refreshSessions[] = $this->authoritativeSession;
                }

                return new ResultSet(
                    $pagination->page(),
                    $pagination->perPage(),
                    count($refreshSessions),
                    ArrayList::of(RefreshSession::class)->replace(array_slice(
                        $refreshSessions,
                        $pagination->offset(),
                        $pagination->limit()
                    ))
                );
            }

            public function getAllActiveByUserId(UserId $userId, DateTimeImmutable $at): array
            {
                if (
                    $this->authoritativeSession->getUserId()->equals($userId)
                    && $this->authoritativeSession->isUsableAt($at)
                ) {
                    return [$this->authoritativeSession];
                }

                return [];
            }

            public function getByCredential(RefreshCredential $refreshCredential): ?RefreshSession
            {
                $matchesCredential = $this->authoritativeSession->matchesCredential($refreshCredential);

                return $matchesCredential ? $this->authoritativeSession : null;
            }

            public function getByUsedCredential(RefreshCredential $refreshCredential): ?RefreshSession
            {
                return null;
            }

            public function replace(RefreshSession $expected, RefreshSession $replacement): bool
            {
                ++$this->replaceAttempts;
                if ($this->replaceAttempts > 10) {
                    throw new LogicException('Revocation contention was not bounded.');
                }

                $this->authoritativeSession = $this->authoritativeSession->rotate(
                    RefreshCredential::fromString(self::contentionCredential($this->replaceAttempts)),
                    new DateTimeImmutable('2026-08-19T12:00:00+00:00'),
                    new DateTimeImmutable('2026-08-20T12:00:00+00:00')
                );

                return false;
            }

            private static function contentionCredential(int $revision): string
            {
                return str_pad(dechex($revision), 64, '0', STR_PAD_LEFT);
            }
        };
        $service = $this->service(
            new InMemoryUserRepository(),
            new InMemoryActivationGrantRepository(),
            $sessions,
            new InMemoryUnitOfWork(),
            $events
        );

        try {
            $service->logout(self::REFRESH_CREDENTIAL);
            self::fail('Expected perpetual authoritative contention to fail closed.');
        } catch (RefreshSessionNotFoundException $refreshSessionNotFoundException) {
            self::assertSame(
                'The refresh session is not authoritative.',
                $refreshSessionNotFoundException->getMessage()
            );
        }

        self::assertSame(3, $sessions->replaceAttempts);
        self::assertSame(3, $sessions->authoritativeReads);
        self::assertCount(1, $events->events());
        self::assertInstanceOf(RedactedCommandFailed::class, $events->events()[0]);
        self::assertSame(AuthenticationService::class.'::logout', $events->events()[0]->getCommandClass());
        self::assertSame([], $events->events()[0]->getRedactedCommandData());
    }

    public function test_that_starter_token_lifetimes_and_value_validation_are_explicit(): void
    {
        $issuedAt = new DateTimeImmutable('2026-08-19T12:00:00+00:00');
        $policy = AuthenticationTokenPolicy::starterDefaults(new DateInterval('PT5S'));

        self::assertEquals(new DateTimeImmutable('2026-08-19T12:15:00+00:00'), $policy->accessExpiresAt($issuedAt));
        self::assertEquals(
            new DateTimeImmutable('2026-08-20T12:00:00+00:00'),
            $policy->refreshIdleExpiresAt($issuedAt, false)
        );
        self::assertEquals(
            new DateTimeImmutable('2026-08-21T12:00:00+00:00'),
            $policy->refreshAbsoluteExpiresAt($issuedAt, false)
        );
        self::assertEquals(
            new DateTimeImmutable('2026-09-03T12:00:00+00:00'),
            $policy->refreshIdleExpiresAt($issuedAt, true)
        );
        self::assertEquals(
            new DateTimeImmutable('2026-09-18T12:00:00+00:00'),
            $policy->refreshAbsoluteExpiresAt($issuedAt, true)
        );
        self::assertEquals(new DateInterval('PT5S'), $policy->refreshConflictWindow());
        self::assertSame(self::REFRESH_CREDENTIAL, $this->refreshCredential()->toString());
        self::assertSame(64, strlen($this->refreshCredential()->digest()));
        self::assertSame('encoded.jwt.token', AccessToken::fromString('encoded.jwt.token')->toString());

        $this->expectException(DomainException::class);
        AccessToken::fromString('');
    }

    public function test_that_refresh_credentials_and_session_lifetimes_reject_invalid_values(): void
    {
        try {
            RefreshCredential::fromString('not-a-refresh-credential');
            self::fail('Expected invalid refresh credential rejection.');
        } catch (DomainException $domainException) {
            self::assertInstanceOf(DomainException::class, $domainException);
        }

        $this->expectException(InvalidArgumentException::class);
        RefreshSession::start(
            RefreshSessionId::generate(),
            UserId::generate(),
            $this->refreshCredential(),
            new DateTimeImmutable('2026-08-19T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-18T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-21T12:00:00+00:00'),
            1,
            false
        );
    }

    private function activeUserFor(string $email, ?UserId $userId = null): User
    {
        $user = User::invite($userId ?? UserId::generate(), EmailAddress::fromString($email));
        $user->activate(PasswordHash::fromString(password_hash('correct-secret', PASSWORD_DEFAULT)));

        return $user;
    }

    private function assertPasswordChangeFailureIsRedacted(
        InMemoryEventDispatcher $events,
        UserId $userId,
        string $errorMessage,
        string $currentPassword,
        string $newPassword
    ): void {
        self::assertCount(1, $events->events());
        self::assertInstanceOf(RedactedCommandFailed::class, $events->events()[0]);
        self::assertSame(
            AuthenticationService::class.'::changePassword',
            $events->events()[0]->getCommandClass()
        );
        self::assertSame(
            ['user_id' => $userId->toString()],
            $events->events()[0]->getRedactedCommandData()
        );
        self::assertSame($errorMessage, $events->events()[0]->getErrorMessage());
        self::assertStringNotContainsString($currentPassword, serialize($events->events()[0]->toArray()));
        self::assertStringNotContainsString($newPassword, serialize($events->events()[0]->toArray()));
    }

    private function grant(UserId $userId): ActivationGrant
    {
        return ActivationGrant::issue(
            $userId,
            ActivationCredential::fromString(self::ACTIVATION_CREDENTIAL),
            new DateTimeImmutable('2026-08-18T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-22T12:00:00+00:00'),
            EmailAddress::fromString('alice@example.test'),
            'ciphertext:activation'
        );
    }

    private function refreshCredential(): RefreshCredential
    {
        return RefreshCredential::fromString(self::REFRESH_CREDENTIAL);
    }

    private function session(User $user, RefreshCredential $credential, bool $remembered): RefreshSession
    {
        $createdAt = new DateTimeImmutable('2026-08-19T11:00:00+00:00');

        return RefreshSession::start(
            RefreshSessionId::generate(),
            $user->getId(),
            $credential,
            $createdAt,
            new DateTimeImmutable($remembered ? '2026-09-03T11:00:00+00:00' : '2026-08-20T11:00:00+00:00'),
            new DateTimeImmutable($remembered ? '2026-09-18T11:00:00+00:00' : '2026-08-21T11:00:00+00:00'),
            $user->getAuthenticationVersion(),
            $remembered
        );
    }

    private function service(
        UserRepository $users,
        ActivationGrantRepository $grants,
        RefreshSessionRepository $sessions,
        InMemoryUnitOfWork $unitOfWork,
        InMemoryEventDispatcher $events,
        ?AuthenticationClock $authenticationClock = null,
        ?LoginThrottle $loginThrottle = null,
        ?PasswordHasher $passwordHasher = null,
        ?PasswordValidator $passwordValidator = null,
        ?TokenEncoder $tokenEncoder = null,
        ?RefreshCredentialGenerator $refreshCredentialGenerator = null,
        ?AuthenticationTokenPolicy $tokenPolicy = null,
        ?PasswordResetGrantRepository $passwordResetGrantRepository = null,
        ?AuditEvidenceRepository $auditEvidenceRepository = null
    ): AuthenticationService {
        $passwordSecurity = new TestPasswordSecurity();
        if (
            $users instanceof InMemoryUserRepository
            && $sessions instanceof InMemoryRefreshSessionRepository
        ) {
            $users->bindRefreshSessionRepository($sessions);
        }

        return new AuthenticationService(
            $users,
            $grants,
            $sessions,
            $unitOfWork,
            $authenticationClock ?? new FixedAuthenticationClock(
                new DateTimeImmutable('2026-08-19T12:00:00+00:00')
            ),
            $loginThrottle ?? new FixedLoginThrottle(true),
            $refreshCredentialGenerator ?? new FixedRefreshCredentialGenerator($this->refreshCredential()),
            $passwordHasher ?? $passwordSecurity,
            $passwordValidator ?? $passwordSecurity,
            $tokenEncoder ?? new RecordingTokenEncoder(),
            $tokenPolicy ?? AuthenticationTokenPolicy::starterDefaults(new DateInterval('PT5S')),
            PasswordHash::fromString(password_hash('dummy-password', PASSWORD_DEFAULT)),
            $events,
            $passwordResetGrantRepository ?? new InMemoryPasswordResetGrants($unitOfWork),
            $auditEvidenceRepository ?? new InMemoryAuditEvidenceRepository($unitOfWork)
        );
    }

    private function assertTokenSet(
        TokenSet $tokenSet,
        User $user,
        RefreshSession $refreshSession,
        bool $remembered
    ): void {
        self::assertSame($user->getId(), $tokenSet->getUserId());
        self::assertSame($refreshSession->getId(), $tokenSet->getRefreshSessionId());
        self::assertTrue($refreshSession->matchesCredential($tokenSet->getRefreshCredential()));
        self::assertSame($tokenSet->getRefreshCredential()->digest(), $refreshSession->getCredentialDigest());
        self::assertSame($refreshSession->getAbsoluteExpiresAt(), $tokenSet->getRefreshExpiresAt());
        self::assertSame($remembered, $tokenSet->isRemembered());
        self::assertSame('encoded.jwt.token', $tokenSet->getAccessToken()->toString());
        self::assertEquals(new DateTimeImmutable('2026-08-19T12:15:00+00:00'), $tokenSet->getAccessTokenExpiresAt());
        self::assertTrue($refreshSession->isUsableAt(new DateTimeImmutable('2026-08-19T12:00:00+00:00')));
        self::assertLessThanOrEqual($refreshSession->getAbsoluteExpiresAt(), $refreshSession->getIdleExpiresAt());
        self::assertInstanceOf(DateTimeImmutable::class, $refreshSession->getCreatedAt());
    }
}
