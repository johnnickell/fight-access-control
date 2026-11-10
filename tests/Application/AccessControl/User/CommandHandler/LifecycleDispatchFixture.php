<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User\CommandHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\RefreshSession\Service\SessionRevocationService;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\CorrectPendingInvitationHandler;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\DeleteUserHandler;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\DisableUserHandler;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\EnableUserHandler;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\InvitePendingUserHandler;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\RecoverPendingRegistrationHandler;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\RegisterUserHandler;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\RestoreUserHandler;
use Fight\AccessControl\Application\AccessControl\User\Service\RegistrationRecoveryAuthorization;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationCredential;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrant;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationOrigin;
use Fight\AccessControl\Domain\AccessControl\User\Command\CorrectPendingInvitation;
use Fight\AccessControl\Domain\AccessControl\User\Command\DeleteUser;
use Fight\AccessControl\Domain\AccessControl\User\Command\DisableUser;
use Fight\AccessControl\Domain\AccessControl\User\Command\EnableUser;
use Fight\AccessControl\Domain\AccessControl\User\Command\InvitePendingUser;
use Fight\AccessControl\Domain\AccessControl\User\Command\RecoverPendingRegistration;
use Fight\AccessControl\Domain\AccessControl\User\Command\RestoreUser;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Adapter\Messaging\Command\Sync\Routing\InMemoryCommandRouter;
use Fight\Common\Adapter\Messaging\Command\Sync\RoutingCommandBus;
use Fight\Common\Application\Messaging\Command\CommandHandler;
use Fight\Common\Application\Repository\TransactionalUnitOfWork;
use Fight\Common\Domain\Messaging\Command\Command;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\Service as ActivationService;
use Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\Service\FixedCredentialGenerator;
use Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\Service\PrefixInvitationDeliveryCipher;
use Fight\Test\AccessControl\Application\AccessControl\Audit\Repository\InMemoryAuditEvidenceRepository;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\PasswordResetGrant\Repository\InMemoryPasswordResetGrants;
use Fight\Test\AccessControl\Application\AccessControl\RefreshSession\Repository\InMemoryRefreshSessionRepository;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Domain\AccessControl\User\UserFixture;

final readonly class LifecycleDispatchFixture
{
    public RegistrationFixture $registration;

    public Command $command;

    public CommandHandler $handler;

    public RoutingCommandBus $bus;

    public InMemoryAuditEvidenceRepository $audit;

    public function __construct(
        string $operation,
        bool $failAudit = false,
        ?InMemoryEventDispatcher $eventDispatcher = null,
        ?TransactionalUnitOfWork $unitOfWork = null
    ) {
        $fixture = new RegistrationFixture(eventDispatcher: $eventDispatcher);
        $this->registration = $fixture;
        $this->audit = new InMemoryAuditEvidenceRepository($fixture->unitOfWork, failAfterSave: $failAudit);
        $clock = new FixedClock('2030-01-08T00:00:00Z');
        $actorId = UserId::generate();
        $userId = UserId::generate();
        $email = EmailAddress::fromString('next@example.test');
        $generator = new FixedCredentialGenerator('new-lifecycle-credential');
        $cipher = new PrefixInvitationDeliveryCipher();
        $transaction = $unitOfWork ?? $fixture->unitOfWork;
        $sessions = new SessionRevocationService(new InMemoryRefreshSessionRepository($fixture->unitOfWork));
        $resets = new InMemoryPasswordResetGrants($fixture->unitOfWork);
        $grant = null;
        if (!in_array($operation, ['register', 'invite'], true)) {
            $state = match ($operation) {
                'enable' => UserState::DISABLED,
                'restore' => UserState::DELETED,
                'correct', 'recover' => UserState::PENDING_ACTIVATION,
                default => UserState::ACTIVE
            };
            $user = UserFixture::withIdAndAuthenticationVersion($userId, 'current@example.test', $state, 1);
            $fixture->userRepository->add($user);
            if ($operation === 'correct' || $operation === 'recover') {
                $grant = ActivationGrant::issue(
                    $operation === 'recover' ? ActivationOrigin::REGISTRATION : ActivationOrigin::PRIVATE_INVITATION,
                    $userId,
                    ActivationCredential::fromString('previous-lifecycle-credential'),
                    new DateTimeImmutable('2030-01-01T00:00:00Z'),
                    new DateTimeImmutable('2030-01-08T00:00:00Z'),
                    $user->getEmail(),
                    'ciphertext:previous-lifecycle-credential'
                );
                $fixture->activationGrantRepository->add($grant);
            }
        }

        $this->command = match ($operation) {
            'register' => $fixture->command(),
            'invite' => new InvitePendingUser($actorId, $userId, $email),
            'enable' => new EnableUser($actorId, $userId),
            'disable' => new DisableUser($actorId, $userId),
            'delete' => new DeleteUser($actorId, $userId),
            'restore' => new RestoreUser($actorId, $userId, UserState::ACTIVE),
            'correct' => new CorrectPendingInvitation($actorId, $userId, $email),
            default => new RecoverPendingRegistration($actorId, $userId, $grant->getDelivery()->getId(), $email)
        };
        $this->handler = match ($operation) {
            'register' => new RegisterUserHandler(
                $fixture->userRepository,
                $fixture->roleRepository,
                $fixture->activationGrantRepository,
                $this->audit,
                $transaction,
                $generator,
                $cipher,
                $clock,
                $fixture->eventDispatcher,
                ['ROLE_MEMBER', 'ROLE_READER']
            ),
            'invite' => new InvitePendingUserHandler(
                $fixture->userRepository,
                $fixture->activationGrantRepository,
                $this->audit,
                $transaction,
                $generator,
                $cipher,
                $clock,
                $fixture->eventDispatcher
            ),
            'enable' => new EnableUserHandler(
                $fixture->userRepository,
                $this->audit,
                $transaction,
                $fixture->eventDispatcher,
                $clock
            ),
            'disable' => new DisableUserHandler(
                $fixture->userRepository,
                $sessions,
                $clock,
                $this->audit,
                $transaction,
                $fixture->eventDispatcher,
                $resets
            ),
            'delete' => new DeleteUserHandler(
                $fixture->userRepository,
                $sessions,
                $clock,
                $this->audit,
                $transaction,
                $fixture->eventDispatcher,
                $resets
            ),
            'restore' => new RestoreUserHandler(
                $fixture->userRepository,
                $fixture->activationGrantRepository,
                $generator,
                $cipher,
                $clock,
                $this->audit,
                $transaction,
                $fixture->eventDispatcher
            ),
            'correct' => new CorrectPendingInvitationHandler(
                $fixture->userRepository,
                $fixture->activationGrantRepository,
                new ActivationService\FixedInvitationAdministrationAuthorization(true),
                $this->audit,
                $transaction,
                $generator,
                $cipher,
                $clock,
                $fixture->eventDispatcher
            ),
            default => new RecoverPendingRegistrationHandler(
                $fixture->userRepository,
                $fixture->activationGrantRepository,
                new class implements RegistrationRecoveryAuthorization {
                    public function assertCanRecoverRegistration(UserId $actorId, UserId $userId): void
                    {
                    }
                },
                $this->audit,
                $transaction,
                $generator,
                $cipher,
                $clock,
                $fixture->eventDispatcher
            )
        };
        $router = new InMemoryCommandRouter();
        $router->registerHandler($this->handler::commandRegistration(), $this->handler);

        $this->bus = new RoutingCommandBus($router);
    }
}
