<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\EmailChangeGrant;

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
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeCredential;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeGrant;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Exception\EmailChangeConfirmationRejectedException;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\Exception\PasswordResetRejectedException;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrant;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\Exception\RefreshSessionNotFoundException;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshCredential;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSession;
use Fight\AccessControl\Domain\AccessControl\User\Event\EmailChangeConfirmed;
use Fight\AccessControl\Domain\AccessControl\User\Event\PasswordChanged;
use Fight\AccessControl\Domain\AccessControl\User\Event\PasswordResetCompleted;
use Fight\AccessControl\Domain\AccessControl\User\Event\UserActivated;
use Fight\AccessControl\Domain\AccessControl\User\Event\UserLoggedIn;
use Fight\AccessControl\Domain\AccessControl\User\Exception\PasswordChangeRejectedException;
use Fight\AccessControl\Domain\AccessControl\User\PasswordHash;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use Fight\Test\AccessControl\Application\AccessControl\Audit\Repository\InMemoryAuditEvidenceRepository;
use Fight\Test\AccessControl\Application\AccessControl\EmailChangeGrant\Repository\InMemoryEmailChangeGrantRepository;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\RefreshSession\Repository\InMemoryRefreshSessionRepository;
use Fight\Test\AccessControl\Application\AccessControl\User\Authentication\SynchronousAuthenticationFixture;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryUserRepository;
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
#[CoversClass(PasswordResetRejectedException::class)]
#[CoversClass(RefreshCredential::class)]
#[CoversClass(RefreshSession::class)]
#[CoversClass(TokenSet::class)]
#[CoversClass(UserActivated::class)]
#[CoversClass(UserLoggedIn::class)]
#[CoversClass(User::class)]
final class EmailChangeConfirmationTest extends TestCase
{
    use SynchronousAuthenticationFixture;

    private const string SIBLING_CREDENTIAL = 'abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789';

    public function test_that_email_confirmation_atomically_promotes_identity_and_invalidates_authentication(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $userRepository = new InMemoryUserRepository($unitOfWork);
        $user = $this->activeUserFor('old@example.test');
        $userRepository->add($user);
        $reservedUser = clone $user;
        $reservedUser->requestEmailChange(
            EmailAddress::fromString('new@example.test'),
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        );
        self::assertTrue($userRepository->replaceEmailChangeReservation($user, $reservedUser));
        $emailChangeGrantRepository = new InMemoryEmailChangeGrantRepository($unitOfWork);
        self::assertTrue($emailChangeGrantRepository->add(EmailChangeGrant::issue(
            $user->getId(),
            EmailChangeCredential::fromString(self::EMAIL_CHANGE_CREDENTIAL),
            new DateTimeImmutable('2026-08-19T11:00:00+00:00'),
            new DateTimeImmutable('2026-08-19T13:00:00+00:00'),
            EmailAddress::fromString('new@example.test'),
            'ciphertext:confirm-email-once',
            $reservedUser->getEmailChangeReservationRevision()
        )));
        $refreshSessionRepository = new InMemoryRefreshSessionRepository($unitOfWork);
        $refreshSessionRepository->add($this->session($reservedUser, $this->refreshCredential(), false));
        $refreshSessionRepository->add($this->session(
            $reservedUser,
            RefreshCredential::fromString(self::SIBLING_CREDENTIAL),
            false
        ));
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork);
        $eventDispatcher = new InMemoryEventDispatcher(
            static function ($event) use ($auditEvidenceRepository, $unitOfWork): void {
                if ($event instanceof EmailChangeConfirmed) {
                    self::assertCount(1, $auditEvidenceRepository->all());
                    self::assertTrue($unitOfWork->transactionCompleted);
                }
            }
        );
        $service = $this->emailChangeConfirmation(
            userRepository: $userRepository,
            refreshSessionRepository: $refreshSessionRepository,
            unitOfWork: $unitOfWork,
            eventDispatcher: $eventDispatcher,
            auditEvidenceRepository: $auditEvidenceRepository,
            emailChangeGrantRepository: $emailChangeGrantRepository
        );

        $service->confirmEmail($user->getId(), self::EMAIL_CHANGE_CREDENTIAL);

        $storedUser = $userRepository->getById($user->getId());
        self::assertInstanceOf(User::class, $storedUser);
        self::assertSame('new@example.test', $storedUser->getEmail()->canonical());
        self::assertNull($userRepository->getByEmail(EmailAddress::fromString('old@example.test')));
        self::assertSame($storedUser, $userRepository->getByEmail(EmailAddress::fromString('new@example.test')));
        self::assertNull($storedUser->getPendingEmailChange());
        self::assertSame(2, $storedUser->getAuthenticationVersion());
        self::assertSame(2, $storedUser->getAuthenticationAuthorityRevision());
        self::assertTrue($emailChangeGrantRepository->all()[0]->isConsumed());
        self::assertFalse($emailChangeGrantRepository->all()[0]->getDelivery()->hasRecoverableMaterial());
        self::assertCount(2, $refreshSessionRepository->all());
        self::assertTrue($refreshSessionRepository->all()[0]->isRevoked());
        self::assertTrue($refreshSessionRepository->all()[1]->isRevoked());
        self::assertSame('user.email_change_confirmed', $auditEvidenceRepository->all()[0]->action());
        self::assertSame(1, $unitOfWork->transactions);
        self::assertCount(1, $eventDispatcher->events());
        self::assertInstanceOf(EmailChangeConfirmed::class, $eventDispatcher->events()[0]);

        try {
            $this->sessionLifecycle(
                $userRepository,
                $refreshSessionRepository,
                $unitOfWork,
                $eventDispatcher
            )->refresh(self::REFRESH_CREDENTIAL);
            self::fail('A pre-confirmation refresh credential remained authoritative.');
        } catch (RefreshSessionNotFoundException) {
            self::addToAssertionCount(1);
        }
    }

    public function test_that_email_confirmation_event_round_trips_without_identity_or_secret_material(): void
    {
        $userId = UserId::generate();
        $event = new EmailChangeConfirmed($userId, new DateTimeImmutable('2026-08-19T12:00:00+00:00'));

        self::assertEquals($event, EmailChangeConfirmed::fromArray($event->toArray()));
        self::assertSame($userId, $event->getUserId());
        self::assertSame('2026-08-19T12:00:00+00:00', $event->getConfirmedAt()->format(DATE_ATOM));
        self::assertSame([
            'user_id'      => $userId->toString(),
            'confirmed_at' => '2026-08-19T12:00:00+00:00'
        ], $event->toArray());

        foreach (['user_id', 'confirmed_at'] as $missing) {
            $data = $event->toArray();
            unset($data[$missing]);

            try {
                EmailChangeConfirmed::fromArray($data);
                self::fail('Missing event data was accepted.');
            } catch (DomainException) {
            }
        }

        self::addToAssertionCount(2);
    }

    #[DataProvider('rejectedEmailConfirmationAuthority')]
    public function test_that_non_authoritative_email_confirmation_is_generic_redacted_and_mutation_free(
        string $condition,
        string $credential
    ): void {
        $unitOfWork = new InMemoryUnitOfWork();
        $userRepository = new InMemoryUserRepository($unitOfWork);
        $user = $this->activeUserFor('old@example.test');
        $reservedUser = clone $user;
        $reservedUser->requestEmailChange(
            EmailAddress::fromString('new@example.test'),
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        );
        if ($condition !== 'missing_user') {
            $userRepository->add($condition === 'missing_reservation' ? $user : $reservedUser);
        }

        $emailChangeGrantRepository = new InMemoryEmailChangeGrantRepository($unitOfWork);
        if ($condition !== 'missing_grant') {
            $expiresAt = new DateTimeImmutable('2026-08-19T13:00:00+00:00');
            if ($condition === 'expired') {
                $expiresAt = new DateTimeImmutable('2026-08-19T12:00:00+00:00');
            }

            $grant = EmailChangeGrant::issue(
                $user->getId(),
                EmailChangeCredential::fromString(self::EMAIL_CHANGE_CREDENTIAL),
                new DateTimeImmutable('2026-08-19T11:00:00+00:00'),
                $expiresAt,
                EmailAddress::fromString(
                    $condition === 'mismatched' ? 'mismatched@example.test' : 'new@example.test'
                ),
                'ciphertext:confirm-email-once',
                $reservedUser->getEmailChangeReservationRevision()
            );
            self::assertTrue($emailChangeGrantRepository->add($grant));
            if ($condition === 'consumed') {
                self::assertTrue($emailChangeGrantRepository->replace(
                    $grant,
                    $grant->consume(new DateTimeImmutable('2026-08-19T11:30:00+00:00'))
                ));
            } elseif ($condition === 'revoked') {
                self::assertTrue($emailChangeGrantRepository->replace(
                    $grant,
                    $grant->revoke(new DateTimeImmutable('2026-08-19T11:30:00+00:00'))
                ));
            }
        }

        $originalGrant = $emailChangeGrantRepository->all()[0] ?? null;
        $refreshSessionRepository = new InMemoryRefreshSessionRepository($unitOfWork);
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork);
        $eventDispatcher = new InMemoryEventDispatcher();
        $service = $this->emailChangeConfirmation(
            userRepository: $userRepository,
            refreshSessionRepository: $refreshSessionRepository,
            unitOfWork: $unitOfWork,
            eventDispatcher: $eventDispatcher,
            auditEvidenceRepository: $auditEvidenceRepository,
            emailChangeGrantRepository: $emailChangeGrantRepository
        );

        $this->expectException(EmailChangeConfirmationRejectedException::class);
        try {
            $service->confirmEmail($user->getId(), $credential);
        } finally {
            self::assertSame(
                $condition === 'missing_user' ? null : ($condition === 'missing_reservation' ? $user : $reservedUser),
                $userRepository->getById($user->getId())
            );
            self::assertSame($originalGrant, $emailChangeGrantRepository->all()[0] ?? null);
            self::assertSame([], $auditEvidenceRepository->all());
            $this->assertEmailConfirmationFailureIsRedacted($eventDispatcher, $user->getId(), $credential);
        }
    }

    public function test_that_confirmation_cas_losses_roll_back_every_authority_change(): void
    {
        foreach (['grant', 'user'] as $casLoss) {
            $unitOfWork = new InMemoryUnitOfWork();
            $userRepository = new InMemoryUserRepository(
                $unitOfWork,
                replaceEmailChangeConfirmationSucceeds: $casLoss !== 'user'
            );
            $user = $this->activeUserFor('old@example.test');
            $userRepository->add($user);
            $reservedUser = clone $user;
            $reservedUser->requestEmailChange(
                EmailAddress::fromString('new@example.test'),
                new DateTimeImmutable('2026-01-01T00:00:00+00:00')
            );
            self::assertTrue($userRepository->replaceEmailChangeReservation($user, $reservedUser));
            $emailChangeGrantRepository = new InMemoryEmailChangeGrantRepository(
                $unitOfWork,
                replaceSucceeds: $casLoss !== 'grant'
            );
            self::assertTrue($emailChangeGrantRepository->add($this->emailChangeGrant($user->getId())));
            $eventDispatcher = new InMemoryEventDispatcher();

            try {
                $this->emailChangeConfirmation(
                    userRepository: $userRepository,
                    refreshSessionRepository: new InMemoryRefreshSessionRepository($unitOfWork),
                    unitOfWork: $unitOfWork,
                    eventDispatcher: $eventDispatcher,
                    emailChangeGrantRepository: $emailChangeGrantRepository
                )->confirmEmail($user->getId(), self::EMAIL_CHANGE_CREDENTIAL);
                self::fail('A stale confirmation compare-and-set was accepted.');
            } catch (EmailChangeConfirmationRejectedException) {
                self::assertSame($reservedUser, $userRepository->getById($user->getId()));
                self::assertTrue($emailChangeGrantRepository->all()[0]->isIssued());
                $this->assertEmailConfirmationFailureIsRedacted(
                    $eventDispatcher,
                    $user->getId(),
                    self::EMAIL_CHANGE_CREDENTIAL
                );
            }
        }
    }

    public function test_that_session_revocation_failure_rolls_back_confirmation(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $userRepository = new InMemoryUserRepository($unitOfWork);
        $user = $this->activeUserFor('old@example.test');
        $userRepository->add($user);
        $reservedUser = clone $user;
        $reservedUser->requestEmailChange(
            EmailAddress::fromString('new@example.test'),
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        );
        self::assertTrue($userRepository->replaceEmailChangeReservation($user, $reservedUser));
        $emailChangeGrantRepository = new InMemoryEmailChangeGrantRepository($unitOfWork);
        self::assertTrue($emailChangeGrantRepository->add($this->emailChangeGrant($user->getId())));
        $refreshSessionRepository = new InMemoryRefreshSessionRepository(
            $unitOfWork,
            beforeReplace: static function (): never {
                throw new RuntimeException('Session persistence failed after secret-bearing confirmation.');
            }
        );
        $refreshSessionRepository->add($this->session($reservedUser, $this->refreshCredential(), false));

        $eventDispatcher = new InMemoryEventDispatcher();

        $this->expectException(EmailChangeConfirmationRejectedException::class);
        try {
            $this->emailChangeConfirmation(
                userRepository: $userRepository,
                refreshSessionRepository: $refreshSessionRepository,
                unitOfWork: $unitOfWork,
                eventDispatcher: $eventDispatcher,
                emailChangeGrantRepository: $emailChangeGrantRepository
            )->confirmEmail($user->getId(), self::EMAIL_CHANGE_CREDENTIAL);
        } finally {
            self::assertSame($reservedUser, $userRepository->getById($user->getId()));
            self::assertTrue($emailChangeGrantRepository->all()[0]->isIssued());
            self::assertFalse($refreshSessionRepository->all()[0]->isRevoked());
            $this->assertEmailConfirmationFailureIsRedacted(
                $eventDispatcher,
                $user->getId(),
                self::EMAIL_CHANGE_CREDENTIAL
            );
        }
    }

    public function test_that_late_confirmation_audit_failure_rolls_back_all_staged_authority(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $userRepository = new InMemoryUserRepository($unitOfWork);
        $user = $this->activeUserFor('old@example.test');
        $userRepository->add($user);
        $reservedUser = clone $user;
        $reservedUser->requestEmailChange(
            EmailAddress::fromString('new@example.test'),
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        );
        self::assertTrue($userRepository->replaceEmailChangeReservation($user, $reservedUser));
        $emailChangeGrantRepository = new InMemoryEmailChangeGrantRepository($unitOfWork);
        self::assertTrue($emailChangeGrantRepository->add($this->emailChangeGrant($user->getId())));
        $refreshSessionRepository = new InMemoryRefreshSessionRepository($unitOfWork);
        $refreshSessionRepository->add($this->session($reservedUser, $this->refreshCredential(), false));

        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork, failAfterSave: true);
        $eventDispatcher = new InMemoryEventDispatcher();

        $this->expectException(EmailChangeConfirmationRejectedException::class);
        try {
            $this->emailChangeConfirmation(
                userRepository: $userRepository,
                refreshSessionRepository: $refreshSessionRepository,
                unitOfWork: $unitOfWork,
                eventDispatcher: $eventDispatcher,
                auditEvidenceRepository: $auditEvidenceRepository,
                emailChangeGrantRepository: $emailChangeGrantRepository
            )->confirmEmail($user->getId(), self::EMAIL_CHANGE_CREDENTIAL);
        } finally {
            self::assertSame($reservedUser, $userRepository->getById($user->getId()));
            self::assertTrue($emailChangeGrantRepository->all()[0]->isIssued());
            self::assertFalse($refreshSessionRepository->all()[0]->isRevoked());
            self::assertSame([], $auditEvidenceRepository->all());
            $this->assertEmailConfirmationFailureIsRedacted(
                $eventDispatcher,
                $user->getId(),
                self::EMAIL_CHANGE_CREDENTIAL
            );
        }
    }
}
