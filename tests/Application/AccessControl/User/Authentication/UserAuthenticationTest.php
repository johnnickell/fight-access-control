<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User\Authentication;

use DateInterval;
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
use Fight\AccessControl\Domain\AccessControl\Role\Role;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\User\Event\EmailChangeConfirmed;
use Fight\AccessControl\Domain\AccessControl\User\Event\PasswordChanged;
use Fight\AccessControl\Domain\AccessControl\User\Event\PasswordResetCompleted;
use Fight\AccessControl\Domain\AccessControl\User\Event\RedactedCommandFailed;
use Fight\AccessControl\Domain\AccessControl\User\Event\UserActivated;
use Fight\AccessControl\Domain\AccessControl\User\Event\UserLoggedIn;
use Fight\AccessControl\Domain\AccessControl\User\Exception\PasswordChangeRejectedException;
use Fight\AccessControl\Domain\AccessControl\User\PasswordHash;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\AccessControl\Domain\AccessControl\User\UserRepository;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Application\Auth\Security\PasswordValidator;
use Fight\Common\Application\Error\Exception\AuthenticationRequired as PasswordResetRejectedException;
use Fight\Common\Application\Error\Exception\AuthenticationRequired as LoginRejectedException;
use Fight\Common\Application\Error\Exception\StateConflict;
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
use Fight\Test\AccessControl\Application\AccessControl\User\Authentication\SynchronousAuthenticationFixture;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryUserRepository;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryUserRepositoryState;
use Fight\Test\AccessControl\Application\AccessControl\User\Security\RecordingTokenEncoder;
use Fight\Test\AccessControl\Application\AccessControl\User\Security\TestPasswordSecurity;
use Fight\Test\AccessControl\Application\AccessControl\User\Service\FixedLoginThrottle;
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
#[CoversClass(PasswordChangeRejectedException::class)]
#[CoversClass(PasswordResetCompleted::class)]
#[CoversClass(PasswordResetGrant::class)]
#[CoversClass(RefreshCredential::class)]
#[CoversClass(RefreshSession::class)]
#[CoversClass(TokenSet::class)]
#[CoversClass(UserActivated::class)]
#[CoversClass(UserLoggedIn::class)]
#[CoversClass(User::class)]
final class UserAuthenticationTest extends TestCase
{
    use SynchronousAuthenticationFixture;

    public function test_that_activation_hashes_secrets_and_commits_a_token_set_before_safe_publication(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $userRepository = new InMemoryUserRepository($unitOfWork);
        $activationGrantRepository = new InMemoryActivationGrantRepository($unitOfWork);
        $refreshSessionRepository = new InMemoryRefreshSessionRepository($unitOfWork);
        $user = User::invite(
            UserId::generate(),
            EmailAddress::fromString('activate@example.test'),
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        );
        $userRepository->add($user);
        $activationGrantRepository->add($this->grant($user->getId()));
        $eventDispatcher = new InMemoryEventDispatcher(static function () use ($unitOfWork): void {
            self::assertTrue($unitOfWork->transactionCompleted);
        });
        $tokenEncoder = new RecordingTokenEncoder();
        $service = $this->authentication(
            userRepository: $userRepository,
            activationGrantRepository: $activationGrantRepository,
            refreshSessionRepository: $refreshSessionRepository,
            unitOfWork: $unitOfWork,
            eventDispatcher: $eventDispatcher,
            tokenEncoder: $tokenEncoder
        );

        $tokenSet = $service->activate(
            $user->getId(),
            self::ACTIVATION_CREDENTIAL,
            'a sufficiently long initial password',
            true
        );

        $authoritativeUser = $userRepository->getById($user->getId());
        self::assertInstanceOf(User::class, $authoritativeUser);
        self::assertSame(1, $unitOfWork->transactions);
        self::assertSame(UserState::ACTIVE, $authoritativeUser->getState());
        self::assertTrue(password_verify(
            'a sufficiently long initial password',
            $authoritativeUser->getPasswordHash()?->toString() ?? ''
        ));
        self::assertSame(1, $authoritativeUser->getAuthenticationAuthorityRevision());
        self::assertTrue($activationGrantRepository->all()[0]->isConsumed());
        self::assertCount(1, $refreshSessionRepository->all());
        $this->assertTokenSet($tokenSet, $authoritativeUser, $refreshSessionRepository->all()[0], true);
        self::assertSame('access', $tokenEncoder->claims['type']);
        self::assertSame($user->getId()->toString(), $tokenEncoder->claims['sub']);
        self::assertSame($refreshSessionRepository->all()[0]->getId()->toString(), $tokenEncoder->claims['sid']);
        self::assertSame(1, $tokenEncoder->claims['auth_version']);
        self::assertSame(1787140800, $tokenEncoder->claims['iat']);
        self::assertEquals(new DateTimeImmutable('2026-08-19T12:15:00+00:00'), $tokenEncoder->expiration);
        self::assertInstanceOf(UserActivated::class, $eventDispatcher->events()[0]);
        self::assertStringNotContainsString(
            self::ACTIVATION_CREDENTIAL,
            serialize($eventDispatcher->events()[0]->toArray())
        );
        self::assertStringNotContainsString(
            'a sufficiently long initial password',
            serialize($eventDispatcher->events()[0]->toArray())
        );
    }

    public function test_that_activation_rejects_a_wrong_credential_with_only_redacted_context(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $userRepository = new InMemoryUserRepository($unitOfWork);
        $activationGrantRepository = new InMemoryActivationGrantRepository($unitOfWork);
        $refreshSessionRepository = new InMemoryRefreshSessionRepository($unitOfWork);
        $eventDispatcher = new InMemoryEventDispatcher();
        $user = User::invite(
            UserId::generate(),
            EmailAddress::fromString('activate@example.test'),
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        );
        $userRepository->add($user);
        $activationGrantRepository->add($this->grant($user->getId()));
        $service = $this->authentication(
            userRepository: $userRepository,
            activationGrantRepository: $activationGrantRepository,
            refreshSessionRepository: $refreshSessionRepository,
            unitOfWork: $unitOfWork,
            eventDispatcher: $eventDispatcher
        );

        $this->expectException(LoginRejectedException::class);
        try {
            $service->activate($user->getId(), 'wrong-credential', 'a sufficiently long initial password');
        } finally {
            self::assertSame(UserState::PENDING_ACTIVATION, $user->getState());
            self::assertFalse($activationGrantRepository->all()[0]->isConsumed());
            self::assertCount(0, $refreshSessionRepository->all());
            self::assertInstanceOf(RedactedCommandFailed::class, $eventDispatcher->events()[0]);
            self::assertSame(
                UserAuthentication::class.'::activate',
                $eventDispatcher->events()[0]->getCommandClass()
            );
            self::assertSame(
                ['user_id' => $user->getId()->toString()],
                $eventDispatcher->events()[0]->getRedactedCommandData()
            );
            self::assertStringNotContainsString(
                'wrong-credential',
                serialize($eventDispatcher->events()[0]->toArray())
            );
        }
    }

    public function test_that_activation_rejects_concurrent_authentication_authority_change(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $userRepository = new InMemoryUserRepository(
            $unitOfWork,
            replaceAuthenticationAuthoritySucceeds: false
        );
        $activationGrantRepository = new InMemoryActivationGrantRepository($unitOfWork);
        $refreshSessionRepository = new InMemoryRefreshSessionRepository($unitOfWork);
        $eventDispatcher = new InMemoryEventDispatcher();
        $user = User::invite(
            UserId::generate(),
            EmailAddress::fromString('activate-conflict@example.test'),
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        );
        $userRepository->add($user);
        $grant = $this->grant($user->getId());
        $activationGrantRepository->add($grant);
        $service = $this->authentication(
            userRepository: $userRepository,
            activationGrantRepository: $activationGrantRepository,
            refreshSessionRepository: $refreshSessionRepository,
            unitOfWork: $unitOfWork,
            eventDispatcher: $eventDispatcher
        );

        try {
            $service->activate(
                $user->getId(),
                self::ACTIVATION_CREDENTIAL,
                'a sufficiently long initial password'
            );
            self::fail('Expected concurrent activation authority to be rejected.');
        } catch (StateConflict $stateConflict) {
            self::assertSame('The invited account changed concurrently.', $stateConflict->getMessage());
        }

        self::assertSame($user, $userRepository->getById($user->getId()));
        self::assertSame(UserState::PENDING_ACTIVATION, $user->getState());
        self::assertNull($user->getPasswordHash());
        self::assertSame([$grant], $activationGrantRepository->all());
        self::assertSame([], $refreshSessionRepository->all());
        self::assertCount(1, $eventDispatcher->events());
        self::assertInstanceOf(RedactedCommandFailed::class, $eventDispatcher->events()[0]);
        self::assertSame(
            ['user_id' => $user->getId()->toString()],
            $eventDispatcher->events()[0]->getRedactedCommandData()
        );
    }

    public function test_that_activation_rejects_concurrent_grant_consumption(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $userRepository = new InMemoryUserRepository($unitOfWork);
        $activationGrantRepository = new InMemoryActivationGrantRepository($unitOfWork, replaceSucceeds: false);
        $refreshSessionRepository = new InMemoryRefreshSessionRepository($unitOfWork);
        $eventDispatcher = new InMemoryEventDispatcher();
        $user = User::invite(
            UserId::generate(),
            EmailAddress::fromString('activate-race@example.test'),
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        );
        $userRepository->add($user);
        $grant = $this->grant($user->getId());
        $activationGrantRepository->add($grant);
        $service = $this->authentication(
            userRepository: $userRepository,
            activationGrantRepository: $activationGrantRepository,
            refreshSessionRepository: $refreshSessionRepository,
            unitOfWork: $unitOfWork,
            eventDispatcher: $eventDispatcher
        );

        $this->expectException(StateConflict::class);
        try {
            $service->activate(
                $user->getId(),
                self::ACTIVATION_CREDENTIAL,
                'a sufficiently long initial password'
            );
        } finally {
            self::assertSame($user, $userRepository->getById($user->getId()));
            self::assertSame([$grant], $activationGrantRepository->all());
            self::assertSame([], $refreshSessionRepository->all());
            self::assertInstanceOf(RedactedCommandFailed::class, $eventDispatcher->events()[0]);
        }
    }

    public function test_that_login_uses_fight_common_password_services_and_rehashes_after_success(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $userRepository = new InMemoryUserRepository($unitOfWork);
        $refreshSessionRepository = new InMemoryRefreshSessionRepository($unitOfWork);
        $eventDispatcher = new InMemoryEventDispatcher();
        $user = $this->activeUserFor('person@example.test');
        $originalHash = $user->getPasswordHash()->toString();
        $userRepository->add($user);
        $passwordSecurity = new TestPasswordSecurity(true);
        $service = $this->authentication(
            userRepository: $userRepository,
            activationGrantRepository: new InMemoryActivationGrantRepository($unitOfWork),
            refreshSessionRepository: $refreshSessionRepository,
            unitOfWork: $unitOfWork,
            eventDispatcher: $eventDispatcher,
            passwordHasher: $passwordSecurity,
            passwordValidator: $passwordSecurity
        );

        $tokenSet = $service->login('PERSON@example.test', 'correct-secret');

        $authoritativeUser = $userRepository->getById($user->getId());
        self::assertInstanceOf(User::class, $authoritativeUser);
        $this->assertTokenSet($tokenSet, $authoritativeUser, $refreshSessionRepository->all()[0], false);
        self::assertNotSame($originalHash, $authoritativeUser->getPasswordHash()?->toString());
        self::assertTrue(password_verify(
            'correct-secret',
            $authoritativeUser->getPasswordHash()?->toString() ?? ''
        ));
        self::assertSame(2, $authoritativeUser->getAuthenticationAuthorityRevision());
        self::assertSame(1, $authoritativeUser->getAuthenticationVersion());
        self::assertEquals($user->getRecoveryAuthority(), $authoritativeUser->getRecoveryAuthority());
        self::assertEquals(new DateTimeImmutable('2026-08-19T12:00:00Z'), $authoritativeUser->getUpdatedAt());
        self::assertInstanceOf(UserLoggedIn::class, $eventDispatcher->events()[0]);
    }

    public function test_that_no_rehash_login_commits_one_revision_and_session_preserving_security_authority(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $userRepository = new InMemoryUserRepository($unitOfWork);
        $refreshSessionRepository = new InMemoryRefreshSessionRepository($unitOfWork);
        $user = $this->activeUserFor('no-rehash@example.test');
        $userRepository->add($user);
        $eventDispatcher = new InMemoryEventDispatcher(static function ($event) use ($unitOfWork): void {
            self::assertInstanceOf(UserLoggedIn::class, $event);
            self::assertTrue($unitOfWork->transactionCompleted);
        });
        $service = $this->authentication(
            userRepository: $userRepository,
            activationGrantRepository: new InMemoryActivationGrantRepository($unitOfWork),
            refreshSessionRepository: $refreshSessionRepository,
            unitOfWork: $unitOfWork,
            eventDispatcher: $eventDispatcher
        );

        $tokens = $service->login('no-rehash@example.test', 'correct-secret');

        $stored = $userRepository->getById($user->getId());
        self::assertInstanceOf(User::class, $stored);
        self::assertSame(2, $stored->getAuthenticationAuthorityRevision());
        self::assertSame($user->getAuthenticationVersion(), $stored->getAuthenticationVersion());
        self::assertEquals($user->getRecoveryAuthority(), $stored->getRecoveryAuthority());
        self::assertSame($user->getPasswordHash(), $stored->getPasswordHash());
        self::assertSame($user->getUpdatedAt(), $stored->getUpdatedAt());
        self::assertCount(1, $refreshSessionRepository->all());
        $this->assertTokenSet($tokens, $stored, $refreshSessionRepository->all()[0], false);
        self::assertCount(1, $eventDispatcher->events());
    }

    public function test_that_login_publication_failure_keeps_committed_session_and_primary_failure(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $userRepository = new InMemoryUserRepository($unitOfWork);
        $refreshSessionRepository = new InMemoryRefreshSessionRepository($unitOfWork);
        $user = $this->activeUserFor('publication@example.test');
        $userRepository->add($user);
        $primary = new RuntimeException('Synthetic publication detail.');
        $attempts = [];
        $eventDispatcher = new InMemoryEventDispatcher(static function ($event) use (&$attempts, $primary): never {
            $attempts[] = $event;
            if ($event instanceof UserLoggedIn) {
                throw $primary;
            }

            throw new RuntimeException('Synthetic notification failure.');
        });
        $service = $this->authentication(
            userRepository: $userRepository,
            activationGrantRepository: new InMemoryActivationGrantRepository($unitOfWork),
            refreshSessionRepository: $refreshSessionRepository,
            unitOfWork: $unitOfWork,
            eventDispatcher: $eventDispatcher
        );

        try {
            $service->login('publication@example.test', 'correct-secret');
            self::fail('Publication failure must not return tokens.');
        } catch (RuntimeException $runtimeException) {
            self::assertSame($primary, $runtimeException);
        }

        self::assertTrue($unitOfWork->transactionCompleted);
        $stored = $userRepository->getById($user->getId());
        self::assertInstanceOf(User::class, $stored);
        self::assertSame(2, $stored->getAuthenticationAuthorityRevision());
        self::assertSame(1, $stored->getAuthenticationVersion());
        self::assertCount(1, $refreshSessionRepository->all());
        self::assertFalse($refreshSessionRepository->all()[0]->isRevoked());
        self::assertCount(2, $attempts);
        self::assertInstanceOf(RedactedCommandFailed::class, $attempts[1]);
        self::assertSame([], $attempts[1]->getRedactedCommandData());
        self::assertSame('Login failed.', $attempts[1]->getErrorMessage());
        self::assertStringNotContainsString('correct-secret', serialize($attempts[1]->toArray()));
        self::assertStringNotContainsString($primary->getMessage(), serialize($attempts[1]->toArray()));
    }

    public function test_that_stale_login_rehash_cannot_overwrite_a_concurrent_password_reset(): void
    {
        $userState = new InMemoryUserRepositoryState();
        $sessionState = new InMemoryRefreshSessionRepositoryState();
        $seedUserRepository = new InMemoryUserRepository(state: $userState);
        $user = $this->activeUserFor('stale-login-rehash@example.test');
        $seedUserRepository->add($user);
        $resetUnitOfWork = new InMemoryUnitOfWork();
        $resetUserRepository = new InMemoryUserRepository($resetUnitOfWork, state: $userState);
        $resetRefreshSessionRepository = new InMemoryRefreshSessionRepository($resetUnitOfWork, state: $sessionState);
        $passwordResetGrantRepository = new InMemoryPasswordResetGrants($resetUnitOfWork);
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($resetUnitOfWork);
        $resetEventDispatcher = new InMemoryEventDispatcher();
        $loginEventDispatcher = new InMemoryEventDispatcher();
        $passwordResetGrantRepository->add(PasswordResetGrant::issue(
            $user->getId(),
            PasswordResetCredential::fromString(self::RESET_CREDENTIAL),
            new DateTimeImmutable('2026-08-19T11:00:00+00:00'),
            new DateTimeImmutable('2026-08-19T13:00:00+00:00'),
            $user->getEmail(),
            'ciphertext:reset-once',
            $user->getRecoveryAuthority()
        ));
        $resetService = $this->passwordManagement(
            userRepository: $resetUserRepository,
            refreshSessionRepository: $resetRefreshSessionRepository,
            unitOfWork: $resetUnitOfWork,
            eventDispatcher: $resetEventDispatcher,
            passwordResetGrantRepository: $passwordResetGrantRepository,
            auditEvidenceRepository: $auditEvidenceRepository
        );
        $interleavingValidator = new readonly class (
            $resetService,
            $user->getId(),
            self::RESET_CREDENTIAL
        ) implements PasswordValidator {
            public function __construct(
                private PasswordManagement $resetService,
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
        $loginUserRepository = new InMemoryUserRepository($loginUnitOfWork, state: $userState);
        $loginRefreshSessionRepository = new InMemoryRefreshSessionRepository($loginUnitOfWork, state: $sessionState);
        $loginService = $this->authentication(
            userRepository: $loginUserRepository,
            activationGrantRepository: new InMemoryActivationGrantRepository($loginUnitOfWork),
            refreshSessionRepository: $loginRefreshSessionRepository,
            unitOfWork: $loginUnitOfWork,
            eventDispatcher: $loginEventDispatcher,
            passwordValidator: $interleavingValidator
        );

        try {
            $loginService->login('stale-login-rehash@example.test', 'correct-secret');
            self::fail('Expected the stale login rehash to lose authentication-authority CAS.');
        } catch (LoginRejectedException $loginRejectedException) {
            self::assertSame('Login rejected.', $loginRejectedException->getMessage());
        }

        $authoritativeUser = $loginUserRepository->getById($user->getId());
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
        self::assertSame(2, $authoritativeUser->getAuthenticationAuthorityRevision());
        self::assertTrue($passwordResetGrantRepository->all()[0]->isConsumed());
        self::assertSame('user.password_reset_completed', $auditEvidenceRepository->all()[0]->action());
        self::assertSame([], $loginRefreshSessionRepository->all());
        self::assertCount(1, $resetEventDispatcher->events());
        self::assertInstanceOf(PasswordResetCompleted::class, $resetEventDispatcher->events()[0]);
        self::assertCount(1, $loginEventDispatcher->events());
        self::assertInstanceOf(RedactedCommandFailed::class, $loginEventDispatcher->events()[0]);
        self::assertSame([], $loginEventDispatcher->events()[0]->getRedactedCommandData());
        self::assertStringNotContainsString(
            'correct-secret',
            serialize($loginEventDispatcher->events()[0]->toArray())
        );
        self::assertStringNotContainsString(
            'a sufficiently long concurrent reset password',
            serialize($loginEventDispatcher->events()[0]->toArray())
        );
    }

    public function test_that_no_rehash_login_cannot_create_a_session_after_reset_serializes_authority(): void
    {
        $userState = new InMemoryUserRepositoryState();
        $sessionState = new InMemoryRefreshSessionRepositoryState();
        $seedUserRepository = new InMemoryUserRepository(state: $userState);
        $user = $this->activeUserFor('no-rehash-race@example.test');
        $seedUserRepository->add($user);
        $resetUnitOfWork = new InMemoryUnitOfWork();
        $resetUserRepository = new InMemoryUserRepository($resetUnitOfWork, state: $userState);
        $resetRefreshSessionRepository = new InMemoryRefreshSessionRepository($resetUnitOfWork, state: $sessionState);
        $passwordResetGrantRepository = new InMemoryPasswordResetGrants($resetUnitOfWork);
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($resetUnitOfWork);
        $resetEventDispatcher = new InMemoryEventDispatcher();
        $loginEventDispatcher = new InMemoryEventDispatcher();
        $passwordResetGrantRepository->add(PasswordResetGrant::issue(
            $user->getId(),
            PasswordResetCredential::fromString(self::RESET_CREDENTIAL),
            new DateTimeImmutable('2026-08-19T11:00:00+00:00'),
            new DateTimeImmutable('2026-08-19T13:00:00+00:00'),
            $user->getEmail(),
            'ciphertext:reset-once',
            $user->getRecoveryAuthority()
        ));
        $resetService = $this->passwordManagement(
            userRepository: $resetUserRepository,
            refreshSessionRepository: $resetRefreshSessionRepository,
            unitOfWork: $resetUnitOfWork,
            eventDispatcher: $resetEventDispatcher,
            passwordResetGrantRepository: $passwordResetGrantRepository,
            auditEvidenceRepository: $auditEvidenceRepository
        );
        $interleavingValidator = new readonly class (
            $resetService,
            $user->getId(),
            self::RESET_CREDENTIAL
        ) implements PasswordValidator {
            public function __construct(
                private PasswordManagement $resetService,
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
        $loginUserRepository = new InMemoryUserRepository($loginUnitOfWork, state: $userState);
        $loginRefreshSessionRepository = new InMemoryRefreshSessionRepository($loginUnitOfWork, state: $sessionState);
        $loginService = $this->authentication(
            userRepository: $loginUserRepository,
            activationGrantRepository: new InMemoryActivationGrantRepository($loginUnitOfWork),
            refreshSessionRepository: $loginRefreshSessionRepository,
            unitOfWork: $loginUnitOfWork,
            eventDispatcher: $loginEventDispatcher,
            passwordValidator: $interleavingValidator
        );

        try {
            $loginService->login('no-rehash-race@example.test', 'correct-secret');
            self::fail('Expected stale no-rehash login authority to be rejected.');
        } catch (LoginRejectedException $loginRejectedException) {
            self::assertSame('Login rejected.', $loginRejectedException->getMessage());
        }

        $authoritativeUser = $loginUserRepository->getById($user->getId());
        self::assertInstanceOf(User::class, $authoritativeUser);
        self::assertTrue(password_verify(
            'a sufficiently long serialized reset password',
            $authoritativeUser->getPasswordHash()?->toString() ?? ''
        ));
        self::assertSame(2, $authoritativeUser->getAuthenticationVersion());
        self::assertSame(2, $authoritativeUser->getAuthenticationAuthorityRevision());
        self::assertSame([], $loginRefreshSessionRepository->all());
        self::assertCount(1, $resetEventDispatcher->events());
        self::assertInstanceOf(PasswordResetCompleted::class, $resetEventDispatcher->events()[0]);
        self::assertCount(1, $loginEventDispatcher->events());
        self::assertInstanceOf(RedactedCommandFailed::class, $loginEventDispatcher->events()[0]);
        self::assertSame([], $loginEventDispatcher->events()[0]->getRedactedCommandData());
        self::assertStringNotContainsString(
            'correct-secret',
            serialize($loginEventDispatcher->events()[0]->toArray())
        );
    }

    public function test_that_atomic_login_commit_forces_stale_reset_to_lose_without_missing_its_session(): void
    {
        $userState = new InMemoryUserRepositoryState();
        $sessionState = new InMemoryRefreshSessionRepositoryState();
        $seedUserRepository = new InMemoryUserRepository(state: $userState);
        $user = $this->activeUserFor('atomic-login-first@example.test');
        $seedUserRepository->add($user);
        $loginUnitOfWork = new InMemoryUnitOfWork();
        $loginUserRepository = new InMemoryUserRepository($loginUnitOfWork, state: $userState);
        $loginRefreshSessionRepository = new InMemoryRefreshSessionRepository($loginUnitOfWork, state: $sessionState);
        $loginEventDispatcher = new InMemoryEventDispatcher();
        $loginService = $this->authentication(
            userRepository: $loginUserRepository,
            activationGrantRepository: new InMemoryActivationGrantRepository($loginUnitOfWork),
            refreshSessionRepository: $loginRefreshSessionRepository,
            unitOfWork: $loginUnitOfWork,
            eventDispatcher: $loginEventDispatcher
        );
        $resetUnitOfWork = new InMemoryUnitOfWork();
        $resetRefreshSessionRepository = new InMemoryRefreshSessionRepository($resetUnitOfWork, state: $sessionState);
        $passwordResetGrantRepository = new InMemoryPasswordResetGrants($resetUnitOfWork);
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($resetUnitOfWork);
        $resetEventDispatcher = new InMemoryEventDispatcher();
        $passwordResetGrantRepository->add(PasswordResetGrant::issue(
            $user->getId(),
            PasswordResetCredential::fromString(self::RESET_CREDENTIAL),
            new DateTimeImmutable('2026-08-19T11:00:00+00:00'),
            new DateTimeImmutable('2026-08-19T13:00:00+00:00'),
            $user->getEmail(),
            'ciphertext:reset-once',
            $user->getRecoveryAuthority()
        ));
        $resetUserRepository = new InMemoryUserRepository(
            $resetUnitOfWork,
            state: $userState,
            beforeValidateRecoveryAuthority: static function () use ($loginService): void {
                $loginService->login('atomic-login-first@example.test', 'correct-secret');
            }
        );
        $resetService = $this->passwordManagement(
            userRepository: $resetUserRepository,
            refreshSessionRepository: $resetRefreshSessionRepository,
            unitOfWork: $resetUnitOfWork,
            eventDispatcher: $resetEventDispatcher,
            passwordResetGrantRepository: $passwordResetGrantRepository,
            auditEvidenceRepository: $auditEvidenceRepository
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

        $authoritativeUser = $resetUserRepository->getById($user->getId());
        self::assertInstanceOf(User::class, $authoritativeUser);
        self::assertTrue(password_verify(
            'correct-secret',
            $authoritativeUser->getPasswordHash()?->toString() ?? ''
        ));
        self::assertSame(1, $authoritativeUser->getAuthenticationVersion());
        self::assertSame(2, $authoritativeUser->getAuthenticationAuthorityRevision());
        self::assertCount(1, $loginRefreshSessionRepository->all());
        self::assertFalse($loginRefreshSessionRepository->all()[0]->isRevoked());
        self::assertFalse($passwordResetGrantRepository->all()[0]->isConsumed());
        self::assertSame([], $auditEvidenceRepository->all());
        self::assertCount(1, $loginEventDispatcher->events());
        self::assertInstanceOf(UserLoggedIn::class, $loginEventDispatcher->events()[0]);
        self::assertCount(1, $resetEventDispatcher->events());
        self::assertInstanceOf(RedactedCommandFailed::class, $resetEventDispatcher->events()[0]);
        self::assertSame(
            ['user_id' => $user->getId()->toString()],
            $resetEventDispatcher->events()[0]->getRedactedCommandData()
        );
        self::assertStringNotContainsString(
            self::RESET_CREDENTIAL,
            serialize($resetEventDispatcher->events()[0]->toArray())
        );
        self::assertStringNotContainsString(
            'a sufficiently long stale reset password',
            serialize($resetEventDispatcher->events()[0]->toArray())
        );
    }

    public function test_that_reset_reading_after_atomic_login_observes_and_revokes_its_committed_session(): void
    {
        $userState = new InMemoryUserRepositoryState();
        $sessionState = new InMemoryRefreshSessionRepositoryState();
        $seedUserRepository = new InMemoryUserRepository(state: $userState);
        $user = $this->activeUserFor('atomic-login-before-reset-read@example.test');
        $seedUserRepository->add($user);
        $loginUnitOfWork = new InMemoryUnitOfWork();
        $loginUserRepository = new InMemoryUserRepository($loginUnitOfWork, state: $userState);
        $loginRefreshSessionRepository = new InMemoryRefreshSessionRepository($loginUnitOfWork, state: $sessionState);
        $loginEventDispatcher = new InMemoryEventDispatcher();
        $loginService = $this->authentication(
            userRepository: $loginUserRepository,
            activationGrantRepository: new InMemoryActivationGrantRepository($loginUnitOfWork),
            refreshSessionRepository: $loginRefreshSessionRepository,
            unitOfWork: $loginUnitOfWork,
            eventDispatcher: $loginEventDispatcher
        );

        $loginService->login('atomic-login-before-reset-read@example.test', 'correct-secret');

        $resetUnitOfWork = new InMemoryUnitOfWork();
        $resetUserRepository = new InMemoryUserRepository($resetUnitOfWork, state: $userState);
        $resetRefreshSessionRepository = new InMemoryRefreshSessionRepository($resetUnitOfWork, state: $sessionState);
        $passwordResetGrantRepository = new InMemoryPasswordResetGrants($resetUnitOfWork);
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($resetUnitOfWork);
        $resetEventDispatcher = new InMemoryEventDispatcher();
        $passwordResetGrantRepository->add(PasswordResetGrant::issue(
            $user->getId(),
            PasswordResetCredential::fromString(self::RESET_CREDENTIAL),
            new DateTimeImmutable('2026-08-19T11:00:00+00:00'),
            new DateTimeImmutable('2026-08-19T13:00:00+00:00'),
            $user->getEmail(),
            'ciphertext:reset-once',
            $user->getRecoveryAuthority()
        ));
        $resetService = $this->passwordManagement(
            userRepository: $resetUserRepository,
            refreshSessionRepository: $resetRefreshSessionRepository,
            unitOfWork: $resetUnitOfWork,
            eventDispatcher: $resetEventDispatcher,
            passwordResetGrantRepository: $passwordResetGrantRepository,
            auditEvidenceRepository: $auditEvidenceRepository
        );

        $resetService->resetPassword(
            $user->getId(),
            self::RESET_CREDENTIAL,
            'a sufficiently long authoritative reset password'
        );

        $authoritativeUser = $loginUserRepository->getById($user->getId());
        self::assertInstanceOf(User::class, $authoritativeUser);
        self::assertTrue(password_verify(
            'a sufficiently long authoritative reset password',
            $authoritativeUser->getPasswordHash()?->toString() ?? ''
        ));
        self::assertSame(2, $authoritativeUser->getAuthenticationVersion());
        self::assertSame(3, $authoritativeUser->getAuthenticationAuthorityRevision());
        self::assertCount(1, $loginRefreshSessionRepository->all());
        self::assertTrue($loginRefreshSessionRepository->all()[0]->isRevoked());
        self::assertTrue($passwordResetGrantRepository->all()[0]->isConsumed());
        self::assertSame('user.password_reset_completed', $auditEvidenceRepository->all()[0]->action());
        self::assertCount(1, $loginEventDispatcher->events());
        self::assertInstanceOf(UserLoggedIn::class, $loginEventDispatcher->events()[0]);
        self::assertCount(1, $resetEventDispatcher->events());
        self::assertInstanceOf(PasswordResetCompleted::class, $resetEventDispatcher->events()[0]);
    }

    public function test_that_transaction_duration_authentication_fence_blocks_login_during_reset_scan_window(): void
    {
        $userState = new InMemoryUserRepositoryState();
        $sessionState = new InMemoryRefreshSessionRepositoryState();
        $seedUserRepository = new InMemoryUserRepository(state: $userState);
        $user = $this->activeUserFor('reset-fence-window@example.test');
        $seedUserRepository->add($user);
        $loginService = null;
        $loginFailure = null;
        $resetUnitOfWork = new InMemoryUnitOfWork();
        $resetUserRepository = new InMemoryUserRepository(
            $resetUnitOfWork,
            state: $userState,
            afterReplaceAuthenticationAuthority: static function () use (
                &$loginFailure,
                &$loginService
            ): void {
                self::assertInstanceOf(UserAuthentication::class, $loginService);
                try {
                    $loginService->login('reset-fence-window@example.test', 'correct-secret');
                    self::fail('Expected login to lose the reset transaction-duration authority fence.');
                } catch (LoginRejectedException $loginRejectedException) {
                    $loginFailure = $loginRejectedException;
                }
            }
        );
        $resetRefreshSessionRepository = new InMemoryRefreshSessionRepository($resetUnitOfWork, state: $sessionState);
        $passwordResetGrantRepository = new InMemoryPasswordResetGrants($resetUnitOfWork);
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($resetUnitOfWork);
        $resetEventDispatcher = new InMemoryEventDispatcher();
        $passwordResetGrantRepository->add(PasswordResetGrant::issue(
            $user->getId(),
            PasswordResetCredential::fromString(self::RESET_CREDENTIAL),
            new DateTimeImmutable('2026-08-19T11:00:00+00:00'),
            new DateTimeImmutable('2026-08-19T13:00:00+00:00'),
            $user->getEmail(),
            'ciphertext:reset-once',
            $user->getRecoveryAuthority()
        ));
        $loginUnitOfWork = new InMemoryUnitOfWork();
        $loginUserRepository = new InMemoryUserRepository($loginUnitOfWork, state: $userState);
        $loginRefreshSessionRepository = new InMemoryRefreshSessionRepository($loginUnitOfWork, state: $sessionState);
        $loginUserRepository->bindRefreshSessionRepository($loginRefreshSessionRepository);
        $loginReadSnapshot = clone $user;
        $snapshotUserRepository = new readonly class (
            $loginReadSnapshot,
            $loginUserRepository
        ) implements UserRepository {
            public function __construct(
                private User $loginReadSnapshot,
                private InMemoryUserRepository $userRepository
            ) {
            }

            public function hasRoleAssignment(RoleId $roleId): bool
            {
                return $this->userRepository->hasRoleAssignment($roleId);
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
                return $this->userRepository->getById($id);
            }

            public function validateRecoveryAuthority(User $expected): bool
            {
                return $this->userRepository->validateRecoveryAuthority($expected);
            }

            public function replaceAuthenticationAuthority(User $expected, User $replacement): bool
            {
                return $this->userRepository->replaceAuthenticationAuthority($expected, $replacement);
            }

            public function replaceAuthenticationAuthorityAndAddRefreshSession(
                User $expected,
                User $replacement,
                RefreshSession $refreshSession
            ): bool {
                return $this->userRepository->replaceAuthenticationAuthorityAndAddRefreshSession(
                    $expected,
                    $replacement,
                    $refreshSession
                );
            }

            public function replaceRoleAssignments(User $expected, User $replacement): bool
            {
                return $this->userRepository->replaceRoleAssignments($expected, $replacement);
            }

            public function validateRoleAssignmentReference(RoleId $roleId): bool
            {
                return $this->userRepository->validateRoleAssignmentReference($roleId);
            }

            public function replaceEmailChangeReservation(User $expected, User $replacement): bool
            {
                return $this->userRepository->replaceEmailChangeReservation($expected, $replacement);
            }

            public function replaceEmailChangeConfirmation(User $expected, User $replacement): bool
            {
                return $this->userRepository->replaceEmailChangeConfirmation($expected, $replacement);
            }

            public function replacePendingInvitationEmail(User $expected, User $replacement): bool
            {
                return $this->userRepository->replacePendingInvitationEmail($expected, $replacement);
            }

            public function replacePendingRegistration(User $expected, User $replacement): bool
            {
                return $this->userRepository->replacePendingRegistration($expected, $replacement);
            }

            public function replaceLifecycleState(User $expected, User $replacement): bool
            {
                return $this->userRepository->replaceLifecycleState($expected, $replacement);
            }

            /** @return ResultSet<User> */
            public function getAll(Pagination $pagination): ResultSet
            {
                return $this->userRepository->getAll($pagination);
            }

            public function add(User $user): void
            {
                $this->userRepository->add($user);
            }

            /** @param list<Role> $expectedRoles */
            public function addWithRoleAssignments(User $user, array $expectedRoles): bool
            {
                return $this->userRepository->addWithRoleAssignments($user, $expectedRoles);
            }
        };
        $loginEventDispatcher = new InMemoryEventDispatcher();
        $loginService = $this->authentication(
            userRepository: $snapshotUserRepository,
            activationGrantRepository: new InMemoryActivationGrantRepository($loginUnitOfWork),
            refreshSessionRepository: $loginRefreshSessionRepository,
            unitOfWork: $loginUnitOfWork,
            eventDispatcher: $loginEventDispatcher
        );
        $resetService = $this->passwordManagement(
            userRepository: $resetUserRepository,
            refreshSessionRepository: $resetRefreshSessionRepository,
            unitOfWork: $resetUnitOfWork,
            eventDispatcher: $resetEventDispatcher,
            passwordResetGrantRepository: $passwordResetGrantRepository,
            auditEvidenceRepository: $auditEvidenceRepository
        );

        $resetService->resetPassword(
            $user->getId(),
            self::RESET_CREDENTIAL,
            'a sufficiently long transaction-fenced reset password'
        );

        $authoritativeUser = $resetUserRepository->getById($user->getId());
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
        self::assertSame(2, $authoritativeUser->getAuthenticationAuthorityRevision());
        self::assertSame([], $loginRefreshSessionRepository->all());
        self::assertTrue($passwordResetGrantRepository->all()[0]->isConsumed());
        self::assertSame('user.password_reset_completed', $auditEvidenceRepository->all()[0]->action());
        self::assertCount(1, $loginEventDispatcher->events());
        self::assertInstanceOf(RedactedCommandFailed::class, $loginEventDispatcher->events()[0]);
        self::assertSame([], $loginEventDispatcher->events()[0]->getRedactedCommandData());
        self::assertStringNotContainsString(
            'correct-secret',
            serialize($loginEventDispatcher->events()[0]->toArray())
        );
        self::assertCount(1, $resetEventDispatcher->events());
        self::assertInstanceOf(PasswordResetCompleted::class, $resetEventDispatcher->events()[0]);
    }

    #[DataProvider('rejectedLoginStates')]
    public function test_that_all_ineligible_login_states_verify_once_and_fail_generically(
        ?User $user,
        bool $throttleAllows
    ): void {
        $unitOfWork = new InMemoryUnitOfWork();
        $userRepository = new InMemoryUserRepository($unitOfWork);
        $eventDispatcher = new InMemoryEventDispatcher();
        $email = 'unknown@example.test';
        if ($user instanceof User) {
            $userRepository->add($user);
            $email = $user->getEmail()->canonical();
        }

        $before = $user instanceof User ? clone $user : null;
        $refreshSessionRepository = new InMemoryRefreshSessionRepository($unitOfWork);
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
        $service = $this->authentication(
            userRepository: $userRepository,
            activationGrantRepository: new InMemoryActivationGrantRepository($unitOfWork),
            refreshSessionRepository: $refreshSessionRepository,
            unitOfWork: $unitOfWork,
            eventDispatcher: $eventDispatcher,
            loginThrottle: new FixedLoginThrottle($throttleAllows),
            passwordValidator: $passwordValidator
        );

        $this->expectException(LoginRejectedException::class);
        $this->expectExceptionMessage('Login rejected.');
        try {
            $service->login($email, 'wrong-secret');
        } finally {
            self::assertSame(1, $passwordValidator->validations);
            if ($user instanceof User) {
                self::assertEquals($before, $userRepository->getById($user->getId()));
            }

            self::assertSame([], $refreshSessionRepository->all());
            self::assertFalse($unitOfWork->transactionCompleted);
            self::assertCount(1, $eventDispatcher->events());
            self::assertInstanceOf(RedactedCommandFailed::class, $eventDispatcher->events()[0]);
            self::assertSame([], $eventDispatcher->events()[0]->getRedactedCommandData());
            self::assertStringNotContainsString('wrong-secret', serialize($eventDispatcher->events()[0]->toArray()));
        }
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
}
