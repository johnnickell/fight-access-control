<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User\Authentication;

use DateInterval;
use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\EmailChangeGrant\EmailChangeConfirmation;
use Fight\AccessControl\Application\AccessControl\RefreshSession\CommandHandler\RevokeSessionHandler;
use Fight\AccessControl\Application\AccessControl\RefreshSession\Service\SessionRevocationService;
use Fight\AccessControl\Application\AccessControl\RefreshSession\SessionLifecycle;
use Fight\AccessControl\Application\AccessControl\User\Authentication\TokenIssuer;
use Fight\AccessControl\Application\AccessControl\User\Authentication\UserAuthentication;
use Fight\AccessControl\Application\AccessControl\User\PasswordManagement;
use Fight\AccessControl\Application\AccessControl\User\Security\AuthenticationTokenPolicy;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrantRepository;
use Fight\AccessControl\Domain\AccessControl\Audit\AnonymousId;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeGrantRepository;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetCredential;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrant;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrantRepository;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\Command\RevokeSession;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshCredential;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionId;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionRepository;
use Fight\AccessControl\Domain\AccessControl\User\RecoveryAuthority;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\AccessControl\Domain\AccessControl\User\UserRepository;
use Fight\Common\Application\Error\Exception\AuthenticationRequired;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Test\AccessControl\Application\AccessControl\Audit\Repository\InMemoryAuditEvidenceRepository;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\RefreshSession\Repository\InMemoryRefreshSessionRepository;
use Fight\Test\AccessControl\Application\AccessControl\RefreshSession\Service\FixedRefreshCredentialGenerator;
use Fight\Test\AccessControl\Application\AccessControl\RefreshSession\Service\FixedSessionAdministrationAuthorization;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use Fight\Test\AccessControl\Application\AccessControl\User\Security\RecordingTokenEncoder;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

#[CoversClass(UserAuthentication::class)]
#[CoversClass(PasswordManagement::class)]
#[CoversClass(EmailChangeConfirmation::class)]
#[CoversClass(SessionLifecycle::class)]
#[CoversClass(SessionRevocationService::class)]
#[CoversClass(RevokeSessionHandler::class)]
#[CoversClass(TokenIssuer::class)]
final class SynchronousContractTest extends TestCase
{
    use SynchronousAuthenticationFixture;

    public function test_token_issuance_rejects_unrelated_session_authority_before_encoding(): void
    {
        $user = $this->activeUserFor('owner@example.test');
        $foreign = $this->activeUserFor('foreign@example.test');
        $tokenIssuer = new TokenIssuer(
            new RecordingTokenEncoder(),
            AuthenticationTokenPolicy::starterDefaults(new DateInterval('PT5S'))
        );
        $this->expectException(LogicException::class);
        $tokenIssuer->issue(
            $user,
            $this->session($foreign, $this->refreshCredential(), false),
            $this->refreshCredential(),
            new DateTimeImmutable('2026-08-19T12:00:00+00:00')
        );
    }

    public function test_sensitive_identity_lookups_reject_wrong_users_as_internal_faults(): void
    {
        foreach (['activate', 'changePassword', 'resetPassword', 'confirmEmail', 'refresh'] as $operation) {
            $unitOfWork = new InMemoryUnitOfWork();
            $userRepository = $this->createStub(UserRepository::class);
            $userRepository->method('getById')->willReturn($this->activeUserFor('foreign@example.test'));
            $refreshSessionRepository = new InMemoryRefreshSessionRepository($unitOfWork);
            $owner = $this->activeUserFor('owner@example.test');
            $refreshSessionRepository->add($this->session($owner, $this->refreshCredential(), false));
            $eventDispatcher = new InMemoryEventDispatcher();
            try {
                match ($operation) {
                    'activate' => $this->authentication(
                        $userRepository,
                        $this->createStub(ActivationGrantRepository::class),
                        $refreshSessionRepository,
                        $unitOfWork,
                        $eventDispatcher
                    )->activate($owner->getId(), self::ACTIVATION_CREDENTIAL, 'password'),
                    'changePassword' => $this->passwordManagement(
                        $userRepository,
                        $refreshSessionRepository, $unitOfWork, $eventDispatcher
                    )->changePassword($owner->getId(), 'correct-secret', 'password'),
                    'resetPassword' => $this->passwordManagement(
                        $userRepository,
                        $refreshSessionRepository, $unitOfWork, $eventDispatcher
                    )->resetPassword($owner->getId(), self::RESET_CREDENTIAL, 'password', AnonymousId::generate()),
                    'confirmEmail' => $this->emailChangeConfirmation(
                        $userRepository,
                        $refreshSessionRepository, $unitOfWork, $eventDispatcher
                    )->confirmEmail($owner->getId(), self::EMAIL_CHANGE_CREDENTIAL, AnonymousId::generate()),
                    'refresh' => $this->sessionLifecycle(
                        $userRepository,
                        $refreshSessionRepository,
                        $unitOfWork,
                        $eventDispatcher
                    )->refresh(self::REFRESH_CREDENTIAL)
                };
                self::fail('A different User was treated as ordinary credential rejection.');
            } catch (LogicException) {
                self::assertFalse($refreshSessionRepository->all()[0]->isRevoked());
                self::assertCount(1, $eventDispatcher->events());
            }
        }
    }

    public function test_sensitive_grant_lookups_reject_wrong_owners_as_internal_faults(): void
    {
        foreach (['activate', 'resetPassword', 'confirmEmail'] as $operation) {
            $unitOfWork = new InMemoryUnitOfWork();
            $owner = $this->activeUserFor('owner@example.test');
            $foreign = $this->activeUserFor('foreign@example.test');
            $userRepository = $this->createStub(UserRepository::class);
            $userRepository->method('getById')->willReturn($owner);
            $userRepository->method('validateRecoveryAuthority')->willReturn(true);
            $refreshSessionRepository = new InMemoryRefreshSessionRepository($unitOfWork);
            $eventDispatcher = new InMemoryEventDispatcher();
            try {
                if ($operation === 'activate') {
                    $grants = $this->createStub(ActivationGrantRepository::class);
                    $grants->method('getLatestByUserId')->willReturn($this->grant($foreign->getId()));
                    $this->authentication(
                        $userRepository,
                        $grants,
                        $refreshSessionRepository,
                        $unitOfWork,
                        $eventDispatcher
                    )->activate($owner->getId(), self::ACTIVATION_CREDENTIAL, 'password');
                } elseif ($operation === 'resetPassword') {
                    $grants = $this->createStub(PasswordResetGrantRepository::class);
                    $grants->method('getLatestByUserId')->willReturn(PasswordResetGrant::issue(
                        $foreign->getId(),
                        PasswordResetCredential::fromString(self::RESET_CREDENTIAL),
                        new DateTimeImmutable('2026-08-19T11:00:00+00:00'),
                        new DateTimeImmutable('2026-08-19T13:00:00+00:00'),
                        $foreign->getEmail(),
                        'ciphertext:test',
                        new RecoveryAuthority(
                            $foreign->getAuthenticationVersion(),
                            $foreign->getCanonicalEmailRevision()
                        )
                    ));
                    $this->passwordManagement(
                        $userRepository,
                        $refreshSessionRepository,
                        $unitOfWork,
                        $eventDispatcher,
                        passwordResetGrantRepository: $grants
                    )->resetPassword($owner->getId(), self::RESET_CREDENTIAL, 'password', AnonymousId::generate());
                } else {
                    $grants = $this->createStub(EmailChangeGrantRepository::class);
                    $grants->method('getLatestByUserId')->willReturn($this->emailChangeGrant($foreign->getId()));
                    $this->emailChangeConfirmation(
                        $userRepository,
                        $refreshSessionRepository,
                        $unitOfWork,
                        $eventDispatcher,
                        emailChangeGrantRepository: $grants
                    )->confirmEmail($owner->getId(), self::EMAIL_CHANGE_CREDENTIAL, AnonymousId::generate());
                }

                self::fail('Unrelated grant authority was treated as ordinary rejection.');
            } catch (LogicException) {
                self::assertCount(1, $eventDispatcher->events());
            }
        }
    }

    public function test_malformed_presented_credentials_use_the_same_safe_authentication_category(): void
    {
        foreach (['activate', 'refresh', 'logout'] as $operation) {
            $unitOfWork = new InMemoryUnitOfWork();
            $users = $this->createStub(UserRepository::class);
            $sessions = new InMemoryRefreshSessionRepository($unitOfWork);
            $events = new InMemoryEventDispatcher();
            try {
                if ($operation === 'activate') {
                    $this->authentication(
                        $users,
                        $this->createStub(ActivationGrantRepository::class),
                        $sessions,
                        $unitOfWork,
                        $events
                    )->activate(UserId::generate(), '', 'password');
                } else {
                    $this->sessionLifecycle($users, $sessions, $unitOfWork, $events)->{$operation}('');
                }

                self::fail('Malformed credential was accepted.');
            } catch (AuthenticationRequired) {
                self::assertCount(1, $events->events());
            }
        }
    }

    public function test_malformed_login_email_is_a_generic_authentication_refusal(): void
    {
        $this->expectException(AuthenticationRequired::class);
        $this->authentication(
            $this->createStub(UserRepository::class),
            $this->createStub(ActivationGrantRepository::class),
            new InMemoryRefreshSessionRepository(),
            new InMemoryUnitOfWork(),
            new InMemoryEventDispatcher()
        )->login('', 'password');
    }

    public function test_used_credential_lookup_cannot_revoke_an_unrelated_session(): void
    {
        $sessions = $this->createMock(RefreshSessionRepository::class);
        $sessions->method('getByCredential')->willReturn(null);
        $sessions->method('getByUsedCredential')->willReturn($this->session(
            $this->activeUserFor('owner@example.test'),
            $this->refreshCredential(),
            false
        ));
        $sessions->expects(self::never())->method('replace');
        $this->expectException(LogicException::class);
        $this->sessionLifecycle(
            $this->createStub(UserRepository::class),
            $sessions,
            new InMemoryUnitOfWork(),
            new InMemoryEventDispatcher()
        )->refresh(self::REFRESH_CREDENTIAL);
    }

    public function test_bulk_revocation_rejects_a_non_list_result(): void
    {
        $repository = $this->createMock(RefreshSessionRepository::class);
        $repository->method('getAllActiveByUserId')->willReturn(['invalid-key' => $this->session(
            $this->activeUserFor('owner@example.test'),
            $this->refreshCredential(),
            false
        )]);
        $repository->expects(self::never())->method('replace');
        $this->expectException(LogicException::class);
        new SessionRevocationService($repository)->revokeAllActiveFor(
            UserId::generate(),
            new DateTimeImmutable('2026-08-19T12:00:00Z')
        );
    }

    public function test_rotation_retry_rejects_a_result_without_the_presented_history(): void
    {
        $owner = $this->activeUserFor('owner@example.test');
        $session = $this->session($owner, $this->refreshCredential(), false);
        $repository = $this->createStub(RefreshSessionRepository::class);
        $repository->method('getByCredential')->willReturn($session);
        $repository->method('replace')->willReturn(false);
        $repository->method('getById')->willReturn($session);
        $users = $this->createStub(UserRepository::class);
        $users->method('getById')->willReturn($owner);
        $this->expectException(LogicException::class);
        $this->sessionLifecycle(
            $users,
            $repository,
            new InMemoryUnitOfWork(),
            new InMemoryEventDispatcher(),
            refreshCredentialGenerator: new FixedRefreshCredentialGenerator(
                RefreshCredential::fromString(str_repeat('b', 64))
            )
        )->refresh(self::REFRESH_CREDENTIAL);
    }

    public function test_login_rejects_a_repository_result_for_a_different_email_before_verification(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->method('getByEmail')->willReturn($this->activeUserFor('other@example.test'));
        $userRepository->expects(self::never())->method('replaceAuthenticationAuthorityAndAddRefreshSession');
        $this->expectException(LogicException::class);
        $this->authentication(
            $userRepository,
            $this->createStub(ActivationGrantRepository::class),
            new InMemoryRefreshSessionRepository($unitOfWork),
            $unitOfWork,
            new InMemoryEventDispatcher()
        )->login('requested@example.test', 'correct-secret');
    }

    public function test_email_confirmation_preserves_unknown_storage_failure_and_secondary_notification_failure(): void
    {
        $primary = new RuntimeException('Synthetic private diagnostic.');
        $userRepository = $this->createStub(UserRepository::class);
        $userRepository->method('getById')->willThrowException($primary);
        $eventDispatcher = new InMemoryEventDispatcher(static function (): never {
            throw new RuntimeException('Secondary notification failure.');
        });
        try {
            $this->emailChangeConfirmation(
                $userRepository,
                new InMemoryRefreshSessionRepository(),
                new InMemoryUnitOfWork(),
                $eventDispatcher
            )->confirmEmail(UserId::generate(), self::EMAIL_CHANGE_CREDENTIAL, AnonymousId::generate());
            self::fail('An unknown fault was swallowed.');
        } catch (Throwable $throwable) {
            self::assertSame($primary, $throwable);
        }
    }

    public function test_refresh_and_logout_reject_a_repository_result_not_matching_the_presented_credential(): void
    {
        foreach (['refresh', 'logout'] as $operation) {
            $unitOfWork = new InMemoryUnitOfWork();
            $user = $this->activeUserFor('owner@example.test');
            $userRepository = $this->createStub(UserRepository::class);
            $userRepository->method('getById')->willReturn($user);
            $refreshSessionRepository = $this->createMock(RefreshSessionRepository::class);
            $refreshSessionRepository->method('getByCredential')->willReturn($this->session(
                $user,
                RefreshCredential::fromString(str_repeat('b', 64)),
                false
            ));
            $refreshSessionRepository->expects(self::never())->method('replace');
            try {
                $this->sessionLifecycle(
                    $userRepository,
                    $refreshSessionRepository,
                    $unitOfWork,
                    new InMemoryEventDispatcher()
                )->{$operation}(self::REFRESH_CREDENTIAL);
                self::fail('An unrelated credential lookup was accepted.');
            } catch (LogicException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_bulk_revocation_rejects_a_foreign_session_before_mutation(): void
    {
        $refreshSessionRepository = $this->createMock(RefreshSessionRepository::class);
        $refreshSessionRepository->method('getAllActiveByUserId')->willReturn([
            $this->session($this->activeUserFor('foreign@example.test'), $this->refreshCredential(), false)
        ]);
        $refreshSessionRepository->expects(self::never())->method('replace');
        $this->expectException(LogicException::class);
        new SessionRevocationService($refreshSessionRepository)->revokeAllActiveFor(
            UserId::generate(),
            new DateTimeImmutable('2026-08-19T12:00:00+00:00')
        );
    }

    public function test_revocation_retry_rejects_a_different_session_identity(): void
    {
        $user = $this->activeUserFor('owner@example.test');
        $session = $this->session($user, $this->refreshCredential(), false);
        $refreshSessionRepository = $this->createMock(RefreshSessionRepository::class);
        $refreshSessionRepository->expects(self::once())->method('replace')->willReturn(false);
        $refreshSessionRepository->method('getById')->willReturn(
            $this->session($user, $this->refreshCredential(), false)
        );
        $this->expectException(LogicException::class);
        new SessionRevocationService($refreshSessionRepository)->revoke($session);
    }

    public function test_revoke_session_rejects_a_different_target_identity_before_mutation(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $actorId = UserId::generate();
        $user = $this->activeUserFor('owner@example.test', $actorId);
        $refreshSessionRepository = $this->createMock(RefreshSessionRepository::class);
        $refreshSessionRepository->method('getById')->willReturn(
            $this->session($user, $this->refreshCredential(), false)
        );
        $refreshSessionRepository->expects(self::never())->method('replace');
        $handler = new RevokeSessionHandler(
            $refreshSessionRepository,
            new FixedClock(new DateTimeImmutable('2026-08-19T12:00:00+00:00')),
            new FixedSessionAdministrationAuthorization(false),
            new InMemoryAuditEvidenceRepository($unitOfWork),
            $unitOfWork,
            new InMemoryEventDispatcher()
        );
        $this->expectException(LogicException::class);
        $handler->handle(CommandMessage::create(new RevokeSession(
            $actorId,
            RefreshSessionId::generate(),
            RefreshSessionId::generate()
        )));
    }
}
