<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\PasswordResetGrant;

use Closure;
use DateInterval;
use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\CredentialDelivery\Service\CredentialDeliveryOutcome;
use Fight\AccessControl\Application\AccessControl\EmailChangeGrant\CommandHandler\CancelEmailChangeHandler;
use Fight\AccessControl\Application\AccessControl\EmailChangeGrant\CommandHandler\ExpireEmailChangeHandler;
use Fight\AccessControl\Application\AccessControl\EmailChangeGrant\CommandHandler\RequestEmailChangeHandler;
use Fight\AccessControl\Application\AccessControl\PasswordResetGrant\CommandHandler\DeliverPasswordResetHandler;
use Fight\AccessControl\Application\AccessControl\PasswordResetGrant\CommandHandler\RequestPasswordResetHandler;
use Fight\AccessControl\Application\AccessControl\RefreshSession\Service\SessionRevocationService;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\DeleteUserHandler;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\DisableUserHandler;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\EnableUserHandler;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\RestoreUserHandler;
use Fight\AccessControl\Application\AccessControl\EmailChangeGrant\EmailChangeConfirmation;
use Fight\AccessControl\Application\AccessControl\RefreshSession\SessionLifecycle;
use Fight\AccessControl\Application\AccessControl\User\Authentication\TokenIssuer;
use Fight\AccessControl\Application\AccessControl\User\Authentication\UserAuthentication;
use Fight\AccessControl\Application\AccessControl\User\PasswordManagement;
use Fight\AccessControl\Application\AccessControl\User\Security\AuthenticationTokenPolicy;
use Fight\AccessControl\Domain\AccessControl\Audit\AnonymousId;
use Fight\AccessControl\Domain\AccessControl\Audit\SystemId;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Command\CancelEmailChange;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Command\ExpireEmailChange;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Command\RequestEmailChange;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\Command\DeliverPasswordReset;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\Command\RequestPasswordReset;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrant;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshCredential;
use Fight\AccessControl\Domain\AccessControl\User\Command\DeleteUser;
use Fight\AccessControl\Domain\AccessControl\User\Command\DisableUser;
use Fight\AccessControl\Domain\AccessControl\User\Command\EnableUser;
use Fight\AccessControl\Domain\AccessControl\User\Command\RestoreUser;
use Fight\AccessControl\Domain\AccessControl\User\PasswordHash;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\Repository\InMemoryActivationGrantRepository;
use Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\Service\FixedCredentialGenerator;
use Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\Service\PrefixInvitationDeliveryCipher;
use Fight\Test\AccessControl\Application\AccessControl\Audit\Repository\InMemoryAuditEvidenceRepository;
use Fight\Test\AccessControl\Application\AccessControl\CredentialDelivery\Service\RecordingCredentialDeliveryProvider;
use Fight\Test\AccessControl\Application\AccessControl\EmailChangeGrant\Repository\InMemoryEmailChangeGrantRepository;
use Fight\Test\AccessControl\Application\AccessControl\EmailChangeGrant\Service as EmailServices;
use Fight\Test\AccessControl\Application\AccessControl\EmailChangeGrant\Service\FixedEmailChangeCredentialGenerator;
use Fight\Test\AccessControl\Application\AccessControl\EmailChangeGrant\Service\PrefixEmailChangeDeliveryCipher;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\PasswordResetGrant\Repository\InMemoryPasswordResetGrants;
use Fight\Test\AccessControl\Application\AccessControl\PasswordResetGrant\Repository\InMemoryPasswordResetGrantState;
use Fight\Test\AccessControl\Application\AccessControl\PasswordResetGrant\Service\FixedPasswordResetCredentialGenerator;
use Fight\Test\AccessControl\Application\AccessControl\PasswordResetGrant\Service\PrefixPasswordResetDeliveryCipher;
use Fight\Test\AccessControl\Application\AccessControl\RefreshSession\Repository\InMemoryRefreshSessionRepository;
use Fight\Test\AccessControl\Application\AccessControl\RefreshSession\Repository\{
    InMemoryRefreshSessionRepositoryState as SessionState
};
use Fight\Test\AccessControl\Application\AccessControl\RefreshSession\Service\FixedRefreshCredentialGenerator;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryUserRepository;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryUserRepositoryState;
use Fight\Test\AccessControl\Application\AccessControl\User\Security\RecordingTokenEncoder;
use Fight\Test\AccessControl\Application\AccessControl\User\Security\TestPasswordSecurity;
use Fight\Test\AccessControl\Application\AccessControl\User\Service\FixedLoginThrottle;

final class RecoveryFixture
{
    public readonly InMemoryUnitOfWork $unitOfWork;

    public readonly InMemoryUserRepository $userRepository;

    public readonly InMemoryPasswordResetGrants $passwordResetGrantRepository;

    public readonly InMemoryRefreshSessionRepository $refreshSessionRepository;

    public readonly InMemoryEmailChangeGrantRepository $emailChangeGrantRepository;

    public readonly InMemoryAuditEvidenceRepository $auditEvidenceRepository;

    public InMemoryEventDispatcher $eventDispatcher;

    public DateTimeImmutable $now;

    public readonly UserId $userId;

    public function __construct(
        public readonly InMemoryUserRepositoryState $userState = new InMemoryUserRepositoryState(),
        public readonly InMemoryPasswordResetGrantState $grantState = new InMemoryPasswordResetGrantState(),
        public readonly SessionState $sessionState = new SessionState(),
        ?UserId $userId = null,
        ?Closure $beforeAdmission = null,
        bool $auditFails = false,
        bool $grantReplaceSucceeds = true
    ) {
        $this->unitOfWork = new InMemoryUnitOfWork();
        $this->userRepository = new InMemoryUserRepository(
            $this->unitOfWork,
            state: $userState,
            beforeValidateRecoveryAuthority: $beforeAdmission
        );
        $this->passwordResetGrantRepository = new InMemoryPasswordResetGrants(
            $this->unitOfWork,
            replaceSucceeds: $grantReplaceSucceeds,
            state: $grantState,
            authorityState: $userState
        );
        $this->refreshSessionRepository = new InMemoryRefreshSessionRepository($this->unitOfWork, state: $sessionState);
        $this->userRepository->bindRefreshSessionRepository($this->refreshSessionRepository);
        $this->emailChangeGrantRepository = new InMemoryEmailChangeGrantRepository($this->unitOfWork);
        $this->auditEvidenceRepository = new InMemoryAuditEvidenceRepository(
            $this->unitOfWork,
            failAfterSave: $auditFails
        );
        $this->eventDispatcher = new InMemoryEventDispatcher();
        $this->now = new DateTimeImmutable('2030-01-01T12:00:00Z');
        $this->userId = $userId ?? UserId::generate();
        if ($userId === null) {
            $user = User::invite($this->userId, EmailAddress::fromString('recovery@example.test'), $this->now);
            $user->activate(PasswordHash::fromString(password_hash('correct-secret', PASSWORD_DEFAULT)), $this->now);
            $this->userRepository->add($user);
        }
    }

    public function connection(?Closure $beforeAdmission = null, bool $auditFails = false): self
    {
        $connection = new self(
            $this->userState,
            $this->grantState,
            $this->sessionState,
            $this->userId,
            $beforeAdmission,
            $auditFails
        );
        $connection->now = $this->now;

        return $connection;
    }

    public function user(): User
    {
        $user = $this->userRepository->getById($this->userId);
        assert($user instanceof User);

        return $user;
    }

    public function grant(): PasswordResetGrant
    {
        $grant = $this->passwordResetGrantRepository->getLatestByUserId($this->userId);
        assert($grant instanceof PasswordResetGrant);

        return $grant;
    }

    public function request(string $credential = 'recovery-fixture'): void
    {
        new RequestPasswordResetHandler(
            $this->userRepository,
            $this->passwordResetGrantRepository,
            $this->auditEvidenceRepository,
            $this->unitOfWork,
            new FixedPasswordResetCredentialGenerator($credential),
            new PrefixPasswordResetDeliveryCipher(),
            new FixedClock($this->now),
            $this->eventDispatcher
        )->handle(CommandMessage::create(new RequestPasswordReset($this->user()->getEmail())));
    }

    public function authentication(bool $rehash = false): UserAuthentication
    {
        $passwords = new TestPasswordSecurity($rehash);

        return new UserAuthentication(
            $this->userRepository,
            new InMemoryActivationGrantRepository($this->unitOfWork),
            $this->unitOfWork,
            new FixedClock($this->now),
            new FixedLoginThrottle(true),
            new FixedRefreshCredentialGenerator(RefreshCredential::fromString(str_repeat('a', 64))),
            $passwords,
            $passwords,
            $this->tokenIssuer(),
            PasswordHash::fromString(password_hash('dummy', PASSWORD_DEFAULT)),
            $this->eventDispatcher
        );
    }

    public function passwordManagement(): PasswordManagement
    {
        $passwords = new TestPasswordSecurity();

        return new PasswordManagement(
            $this->userRepository,
            $this->passwordResetGrantRepository,
            new SessionRevocationService($this->refreshSessionRepository),
            $this->auditEvidenceRepository,
            $this->unitOfWork,
            new FixedClock($this->now),
            $passwords,
            $passwords,
            PasswordHash::fromString(password_hash('dummy', PASSWORD_DEFAULT)),
            $this->eventDispatcher
        );
    }

    public function emailChangeConfirmation(): EmailChangeConfirmation
    {
        return new EmailChangeConfirmation(
            $this->userRepository,
            $this->emailChangeGrantRepository,
            $this->passwordResetGrantRepository,
            new SessionRevocationService($this->refreshSessionRepository),
            $this->auditEvidenceRepository,
            $this->unitOfWork,
            new FixedClock($this->now),
            $this->eventDispatcher
        );
    }

    public function sessionLifecycle(): SessionLifecycle
    {
        return new SessionLifecycle(
            $this->userRepository,
            $this->refreshSessionRepository,
            new SessionRevocationService($this->refreshSessionRepository),
            $this->unitOfWork,
            new FixedClock($this->now),
            new FixedRefreshCredentialGenerator(RefreshCredential::fromString(str_repeat('b', 64))),
            $this->tokenIssuer(),
            AuthenticationTokenPolicy::starterDefaults(new DateInterval('PT5S')),
            $this->eventDispatcher
        );
    }

    public function deliver(
        ?Closure $onDeliver = null,
        CredentialDeliveryOutcome $outcome = CredentialDeliveryOutcome::DELIVERED
    ): RecordingCredentialDeliveryProvider {
        $provider = new RecordingCredentialDeliveryProvider(results: [$outcome], onDeliver: $onDeliver);
        new DeliverPasswordResetHandler(
            $this->passwordResetGrantRepository,
            $this->auditEvidenceRepository,
            $this->unitOfWork,
            new PrefixPasswordResetDeliveryCipher(),
            $provider,
            new FixedClock($this->now),
            $this->eventDispatcher,
            $this->userRepository
        )->handle(CommandMessage::create(new DeliverPasswordReset(
            AnonymousId::generate(),
            $this->userId,
            $this->grant()->getDelivery()->getId()
        )));

        return $provider;
    }

    public function disable(): void
    {
        new DisableUserHandler(
            $this->userRepository,
            new SessionRevocationService($this->refreshSessionRepository),
            new FixedClock($this->now),
            $this->unitOfWork,
            $this->eventDispatcher,
            $this->passwordResetGrantRepository,
            $this->auditEvidenceRepository
        )->handle(CommandMessage::create(new DisableUser(UserId::generate(), $this->userId)));
    }

    public function delete(): void
    {
        new DeleteUserHandler(
            $this->userRepository,
            new SessionRevocationService($this->refreshSessionRepository),
            new FixedClock($this->now),
            $this->unitOfWork,
            $this->eventDispatcher,
            $this->passwordResetGrantRepository,
            $this->auditEvidenceRepository
        )->handle(CommandMessage::create(new DeleteUser(UserId::generate(), $this->userId)));
    }

    public function enable(): void
    {
        new EnableUserHandler(
            $this->userRepository,
            $this->unitOfWork,
            $this->eventDispatcher,
            new FixedClock($this->now),
            $this->auditEvidenceRepository
        )
            ->handle(CommandMessage::create(new EnableUser(UserId::generate(), $this->userId)));
    }

    public function restore(UserState $state = UserState::ACTIVE): void
    {
        new RestoreUserHandler(
            $this->userRepository,
            new InMemoryActivationGrantRepository($this->unitOfWork),
            new FixedCredentialGenerator('activation-fixture'),
            new PrefixInvitationDeliveryCipher(),
            new FixedClock($this->now),
            $this->unitOfWork,
            $this->eventDispatcher,
            $this->auditEvidenceRepository
        )->handle(CommandMessage::create(new RestoreUser(UserId::generate(), $this->userId, $state)));
    }

    public function expireEmail(): void
    {
        $grant = $this->emailChangeGrantRepository->getLatestByUserId($this->userId);
        new ExpireEmailChangeHandler(
            $this->userRepository,
            $this->emailChangeGrantRepository,
            $this->unitOfWork,
            $this->eventDispatcher
        )
            ->handle(CommandMessage::create(new ExpireEmailChange(
                SystemId::generate(),
                $this->userId,
                $grant->getId(),
                $grant->getExpiresAt()
            )));
    }

    public function requestEmail(
        string $email = 'destination@example.test',
        string $credential = 'email-fixture'
    ): void {
        new RequestEmailChangeHandler(
            $this->userRepository,
            $this->emailChangeGrantRepository,
            new EmailServices\FixedEmailChangeAdministrationAuthorization(true),
            $this->unitOfWork,
            new FixedEmailChangeCredentialGenerator($credential),
            new PrefixEmailChangeDeliveryCipher(),
            new FixedClock($this->now),
            $this->eventDispatcher,
            $this->auditEvidenceRepository
        )->handle(CommandMessage::create(new RequestEmailChange(
            $this->userId,
            $this->userId,
            EmailAddress::fromString($email)
        )));
    }

    public function cancelEmail(): void
    {
        new CancelEmailChangeHandler(
            $this->userRepository,
            $this->emailChangeGrantRepository,
            new EmailServices\FixedEmailChangeAdministrationAuthorization(true),
            $this->unitOfWork,
            new FixedClock($this->now),
            $this->eventDispatcher,
            $this->auditEvidenceRepository
        )->handle(CommandMessage::create(new CancelEmailChange($this->userId, $this->userId)));
    }

    private function tokenIssuer(): TokenIssuer
    {
        return new TokenIssuer(
            new RecordingTokenEncoder(),
            AuthenticationTokenPolicy::starterDefaults(new DateInterval('PT5S'))
        );
    }
}
