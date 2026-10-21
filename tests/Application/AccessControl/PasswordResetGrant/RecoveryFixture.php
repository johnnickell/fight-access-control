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
use Fight\AccessControl\Application\AccessControl\User\Security\AuthenticationService;
use Fight\AccessControl\Application\AccessControl\User\Security\AuthenticationTokenPolicy;
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

    public readonly InMemoryUserRepository $users;

    public readonly InMemoryPasswordResetGrants $grants;

    public readonly InMemoryRefreshSessionRepository $sessions;

    public readonly InMemoryEmailChangeGrantRepository $emailGrants;

    public readonly InMemoryAuditEvidenceRepository $audit;

    public InMemoryEventDispatcher $events;

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
        $this->users = new InMemoryUserRepository(
            $this->unitOfWork,
            state: $userState,
            beforeValidateRecoveryAuthority: $beforeAdmission
        );
        $this->grants = new InMemoryPasswordResetGrants(
            $this->unitOfWork,
            replaceSucceeds: $grantReplaceSucceeds,
            state: $grantState,
            authorityState: $userState
        );
        $this->sessions = new InMemoryRefreshSessionRepository($this->unitOfWork, state: $sessionState);
        $this->users->bindRefreshSessionRepository($this->sessions);
        $this->emailGrants = new InMemoryEmailChangeGrantRepository($this->unitOfWork);
        $this->audit = new InMemoryAuditEvidenceRepository($this->unitOfWork, failAfterSave: $auditFails);
        $this->events = new InMemoryEventDispatcher();
        $this->now = new DateTimeImmutable('2030-01-01T12:00:00Z');
        $this->userId = $userId ?? UserId::generate();
        if ($userId === null) {
            $user = User::invite($this->userId, EmailAddress::fromString('recovery@example.test'), $this->now);
            $user->activate(PasswordHash::fromString(password_hash('correct-secret', PASSWORD_DEFAULT)), $this->now);
            $this->users->add($user);
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
        $user = $this->users->getById($this->userId);
        assert($user instanceof User);

        return $user;
    }

    public function grant(): PasswordResetGrant
    {
        $grant = $this->grants->getLatestByUserId($this->userId);
        assert($grant instanceof PasswordResetGrant);

        return $grant;
    }

    public function request(string $credential = 'recovery-fixture'): void
    {
        new RequestPasswordResetHandler(
            $this->users,
            $this->grants,
            $this->audit,
            $this->unitOfWork,
            new FixedPasswordResetCredentialGenerator($credential),
            new PrefixPasswordResetDeliveryCipher(),
            new FixedClock($this->now),
            $this->events
        )->handle(CommandMessage::create(new RequestPasswordReset($this->user()->getEmail())));
    }

    public function service(bool $rehash = false): AuthenticationService
    {
        $passwords = new TestPasswordSecurity($rehash);

        return new AuthenticationService(
            $this->users,
            new InMemoryActivationGrantRepository($this->unitOfWork),
            $this->sessions,
            new SessionRevocationService($this->sessions),
            $this->unitOfWork,
            new FixedClock($this->now),
            new FixedLoginThrottle(true),
            new FixedRefreshCredentialGenerator(RefreshCredential::fromString(str_repeat('a', 64))),
            $passwords,
            $passwords,
            new RecordingTokenEncoder(),
            AuthenticationTokenPolicy::starterDefaults(new DateInterval('PT5S')),
            PasswordHash::fromString(password_hash('dummy', PASSWORD_DEFAULT)),
            $this->events,
            $this->grants,
            $this->audit,
            $this->emailGrants
        );
    }

    public function deliver(
        ?Closure $onDeliver = null,
        CredentialDeliveryOutcome $outcome = CredentialDeliveryOutcome::DELIVERED
    ): RecordingCredentialDeliveryProvider {
        $provider = new RecordingCredentialDeliveryProvider(results: [$outcome], onDeliver: $onDeliver);
        new DeliverPasswordResetHandler(
            $this->grants,
            $this->audit,
            $this->unitOfWork,
            new PrefixPasswordResetDeliveryCipher(),
            $provider,
            new FixedClock($this->now),
            $this->events,
            $this->users
        )->handle(CommandMessage::create(new DeliverPasswordReset(
            'worker',
            $this->userId,
            $this->grant()->getDelivery()->getId()
        )));

        return $provider;
    }

    public function disable(): void
    {
        new DisableUserHandler(
            $this->users,
            new SessionRevocationService($this->sessions),
            new FixedClock($this->now),
            $this->audit,
            $this->unitOfWork,
            $this->events,
            $this->grants
        )->handle(CommandMessage::create(new DisableUser(UserId::generate(), $this->userId)));
    }

    public function delete(): void
    {
        new DeleteUserHandler(
            $this->users,
            new SessionRevocationService($this->sessions),
            new FixedClock($this->now),
            $this->audit,
            $this->unitOfWork,
            $this->events,
            $this->grants
        )->handle(CommandMessage::create(new DeleteUser(UserId::generate(), $this->userId)));
    }

    public function enable(): void
    {
        new EnableUserHandler($this->users, $this->audit, $this->unitOfWork, $this->events, new FixedClock($this->now))
            ->handle(CommandMessage::create(new EnableUser(UserId::generate(), $this->userId)));
    }

    public function restore(UserState $state = UserState::ACTIVE): void
    {
        new RestoreUserHandler(
            $this->users,
            new InMemoryActivationGrantRepository($this->unitOfWork),
            new FixedCredentialGenerator('activation-fixture'),
            new PrefixInvitationDeliveryCipher(),
            new FixedClock($this->now),
            $this->audit,
            $this->unitOfWork,
            $this->events
        )->handle(CommandMessage::create(new RestoreUser(UserId::generate(), $this->userId, $state)));
    }

    public function expireEmail(): void
    {
        $grant = $this->emailGrants->getLatestByUserId($this->userId);
        new ExpireEmailChangeHandler($this->users, $this->emailGrants, $this->unitOfWork, $this->events)
            ->handle(CommandMessage::create(new ExpireEmailChange(
                'worker',
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
            $this->users,
            $this->emailGrants,
            new EmailServices\FixedEmailChangeAdministrationAuthorization(true),
            $this->audit,
            $this->unitOfWork,
            new FixedEmailChangeCredentialGenerator($credential),
            new PrefixEmailChangeDeliveryCipher(),
            new FixedClock($this->now),
            $this->events
        )->handle(CommandMessage::create(new RequestEmailChange(
            $this->userId,
            $this->userId,
            EmailAddress::fromString($email)
        )));
    }

    public function cancelEmail(): void
    {
        new CancelEmailChangeHandler(
            $this->users,
            $this->emailGrants,
            new EmailServices\FixedEmailChangeAdministrationAuthorization(true),
            $this->audit,
            $this->unitOfWork,
            new FixedClock($this->now),
            $this->events
        )->handle(CommandMessage::create(new CancelEmailChange($this->userId, $this->userId)));
    }
}
