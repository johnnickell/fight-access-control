<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User\CommandHandler;

use Closure;
use DateInterval;
use Fight\AccessControl\Application\AccessControl\ActivationGrant\CommandHandler\DeliverUserInvitationHandler;
use Fight\AccessControl\Application\AccessControl\ActivationGrant\CommandHandler\ResendInvitationDeliveryHandler;
use Fight\AccessControl\Application\AccessControl\User\Authentication\TokenIssuer;
use Fight\AccessControl\Application\AccessControl\User\Authentication\UserAuthentication;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\RecoverPendingRegistrationHandler;
use Fight\AccessControl\Application\AccessControl\User\Security\AuthenticationTokenPolicy;
use Fight\AccessControl\Application\AccessControl\User\Service\RegistrationRecoveryAuthorization;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationDeliveryId;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrant;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Command\DeliverUserInvitation;
use Fight\AccessControl\Domain\AccessControl\Audit\AnonymousId;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshCredential;
use Fight\AccessControl\Domain\AccessControl\User\Command\RecoverPendingRegistration;
use Fight\AccessControl\Domain\AccessControl\User\Exception\RegistrationRecoveryAuthorizationException;
use Fight\AccessControl\Domain\AccessControl\User\PasswordHash;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Application\Repository\TransactionalUnitOfWork;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\Repository\InMemoryActivationGrantRepository;
use Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\Service\FixedCredentialGenerator;
use Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\Service\PrefixInvitationDeliveryCipher;
use Fight\Test\AccessControl\Application\AccessControl\Audit\Repository\InMemoryAuditEvidenceRepository;
use Fight\Test\AccessControl\Application\AccessControl\CredentialDelivery\Service\RecordingCredentialDeliveryProvider;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\RefreshSession\Repository\InMemoryRefreshSessionRepository;
use Fight\Test\AccessControl\Application\AccessControl\RefreshSession\Service\FixedRefreshCredentialGenerator;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryUserRepository;
use Fight\Test\AccessControl\Application\AccessControl\User\Security\RecordingTokenEncoder;
use Fight\Test\AccessControl\Application\AccessControl\User\Security\TestPasswordSecurity;
use Fight\Test\AccessControl\Application\AccessControl\User\Service\FixedLoginThrottle;
use LogicException;

final class RegistrationRecoveryFixture
{
    public readonly RegistrationFixture $registration;

    public InMemoryUnitOfWork $unitOfWork;

    public InMemoryUserRepository $userRepository;

    public InMemoryActivationGrantRepository $activationGrantRepository;

    public InMemoryAuditEvidenceRepository $auditEvidenceRepository;

    public InMemoryEventDispatcher $eventDispatcher;

    public readonly User $initialUser;

    public readonly ActivationGrant $initial;

    public readonly UserId $actorId;

    public function __construct(?Closure $beforeReplace = null)
    {
        $this->registration = new RegistrationFixture();
        $this->registration->handler()->handle(CommandMessage::create($this->registration->command()));
        $this->unitOfWork = $this->registration->unitOfWork;
        $this->initialUser = $this->registration->userRepository->all()[0];
        $this->initial = $this->registration->activationGrantRepository->all()[0];
        $this->actorId = UserId::generate();
        $this->userRepository = new InMemoryUserRepository(
            $this->unitOfWork,
            state: $this->registration->userState,
            authorizationReferences: $this->unitOfWork->authorizationReferenceState(),
            beforeReplacePendingRegistration: $beforeReplace
        );
        $this->activationGrantRepository = $this->registration->activationGrantRepository;
        $this->auditEvidenceRepository = new InMemoryAuditEvidenceRepository($this->unitOfWork);
        $this->eventDispatcher = new InMemoryEventDispatcher();
    }

    public function command(
        string $email = 'corrected@example.test',
        ?ActivationDeliveryId $expected = null,
        ?UserId $actor = null
    ): RecoverPendingRegistration {
        return new RecoverPendingRegistration(
            $actor ?? $this->actorId,
            $this->initialUser->getId(),
            $expected ?? $this->initial->getDelivery()->getId(),
            EmailAddress::fromString($email)
        );
    }

    public function handler(
        string $at = '2030-01-02T00:00:00Z',
        ?InMemoryEventDispatcher $eventDispatcher = null,
        ?TransactionalUnitOfWork $unitOfWork = null,
        ?RegistrationRecoveryAuthorization $authorization = null
    ): RecoverPendingRegistrationHandler {
        return new RecoverPendingRegistrationHandler(
            $this->userRepository,
            $this->activationGrantRepository,
            $authorization ?? $this->authorization(),
            $this->auditEvidenceRepository,
            $unitOfWork ?? $this->unitOfWork,
            new FixedCredentialGenerator('recovered-registration-credential'),
            new PrefixInvitationDeliveryCipher(),
            new FixedClock($at),
            $eventDispatcher ?? $this->eventDispatcher
        );
    }

    public function authorization(): RegistrationRecoveryAuthorization
    {
        return new readonly class ($this) implements RegistrationRecoveryAuthorization {
            public function __construct(private RegistrationRecoveryFixture $fixture)
            {
            }

            public function assertCanRecoverRegistration(UserId $actorId, UserId $userId): void
            {
                if (!$this->fixture->unitOfWork->transactionActive) {
                    throw new LogicException('Authorization must be transactional.');
                }

                if (
                    !$actorId->equals($this->fixture->actorId)
                    || !$userId->equals($this->fixture->initialUser->getId())
                ) {
                    throw new RegistrationRecoveryAuthorizationException('Registration recovery is not authorized.');
                }
            }
        };
    }

    public function connection(
        ?Closure $beforeRecovery = null,
        ?Closure $beforeSuccessor = null,
        ?Closure $beforeGrantReplace = null
    ): self {
        $connection = clone $this;
        $connection->unitOfWork = new InMemoryUnitOfWork();
        $connection->userRepository = new InMemoryUserRepository(
            $connection->unitOfWork,
            state: $this->registration->userState,
            authorizationReferences: $this->unitOfWork->authorizationReferenceState(),
            beforeReplacePendingRegistration: $beforeRecovery
        );
        $connection->activationGrantRepository = new InMemoryActivationGrantRepository(
            $connection->unitOfWork,
            beforeReplace: $beforeGrantReplace,
            activationGrantRepository: $this->activationGrantRepository,
            beforeSuccessor: $beforeSuccessor
        );
        $connection->auditEvidenceRepository = new InMemoryAuditEvidenceRepository($connection->unitOfWork);
        $connection->eventDispatcher = new InMemoryEventDispatcher();

        return $connection;
    }

    public function current(): ActivationGrant
    {
        $grant = $this->activationGrantRepository->getLatestByUserId($this->initialUser->getId());
        if (!$grant instanceof ActivationGrant) {
            throw new LogicException('Missing fixture grant.');
        }

        return $grant;
    }

    public function worker(
        RecordingCredentialDeliveryProvider $provider,
        string $at = '2030-01-02T00:00:00Z'
    ): DeliverUserInvitationHandler {
        return new DeliverUserInvitationHandler(
            $this->activationGrantRepository,
            $this->auditEvidenceRepository,
            $this->unitOfWork,
            new PrefixInvitationDeliveryCipher(),
            $provider,
            new FixedClock($at),
            new InMemoryEventDispatcher()
        );
    }

    public function delivery(?ActivationGrant $grant = null): DeliverUserInvitation
    {
        $grant ??= $this->current();
        $actorId = $this->actorId;
        if ($grant->getId()->equals($this->initial->getId())) {
            $actorId = AnonymousId::generate();
        }

        return new DeliverUserInvitation(
            $actorId,
            $this->initialUser->getId(),
            $grant->getDelivery()->getId()
        );
    }

    public function resend(?InMemoryUnitOfWork $unitOfWork = null): ResendInvitationDeliveryHandler
    {
        $uow = $unitOfWork ?? $this->unitOfWork;

        return new ResendInvitationDeliveryHandler(
            $unitOfWork === null ? $this->activationGrantRepository : new InMemoryActivationGrantRepository(
                $uow,
                activationGrantRepository: $this->activationGrantRepository
            ),
            $unitOfWork === null ? $this->auditEvidenceRepository : new InMemoryAuditEvidenceRepository($uow),
            $uow,
            new FixedCredentialGenerator('resent-registration-credential'),
            new PrefixInvitationDeliveryCipher(),
            new FixedClock('2030-01-02T00:00:00Z'),
            new InMemoryEventDispatcher()
        );
    }

    public function authentication(string $at = '2030-01-02T00:00:01Z'): UserAuthentication
    {
        $refreshSessionRepository = new InMemoryRefreshSessionRepository($this->unitOfWork);
        $this->userRepository->bindRefreshSessionRepository($refreshSessionRepository);
        $passwords = new TestPasswordSecurity();

        return new UserAuthentication(
            $this->userRepository,
            $this->activationGrantRepository,
            $this->unitOfWork,
            new FixedClock($at),
            new FixedLoginThrottle(true),
            new FixedRefreshCredentialGenerator(RefreshCredential::fromString(str_repeat('a', 64))),
            $passwords,
            $passwords,
            new TokenIssuer(
                new RecordingTokenEncoder(),
                AuthenticationTokenPolicy::starterDefaults(new DateInterval('PT5S'))
            ),
            PasswordHash::fromString($passwords->hash('unused-dummy-password')),
            new InMemoryEventDispatcher()
        );
    }
}
