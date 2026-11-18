<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\User\CommandHandler;

use DateInterval;
use Fight\AccessControl\Application\AccessControl\ActivationGrant\Service\ActivationCredentialGenerator;
use Fight\AccessControl\Application\AccessControl\ActivationGrant\Service\InvitationDeliveryCipher;
use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationDeliveryId;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrant;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrantRepository;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationOrigin;
use Fight\AccessControl\Domain\AccessControl\Audit\AnonymousId;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditAction;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditCorrelation;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidenceRepository;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditGrantState;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditSubject;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditUserOutcome;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditUserRevisions;
use Fight\AccessControl\Domain\AccessControl\Role\Role;
use Fight\AccessControl\Domain\AccessControl\Role\RoleName;
use Fight\AccessControl\Domain\AccessControl\Role\RoleRepository;
use Fight\AccessControl\Domain\AccessControl\User\Command\RegisterUser;
use Fight\AccessControl\Domain\AccessControl\User\Event\UserRegistered;
use Fight\AccessControl\Domain\AccessControl\User\Exception\DuplicateEmailException;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserRepository;
use Fight\Common\Application\Error\Exception\StateConflict;
use Fight\Common\Application\Messaging\Command\CommandHandler;
use Fight\Common\Application\Messaging\Event\EventDispatcher;
use Fight\Common\Application\Repository\TransactionalUnitOfWork;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use LogicException;
use Throwable;

/**
 * Class RegisterUserHandler
 */
final readonly class RegisterUserHandler implements CommandHandler
{
    /** @var list<RoleName> */
    private array $defaultRoleNames;

    /**
     * Constructs RegisterUserHandler
     *
     * @phpstan-param list<string> $defaultRoleNames Consumer configuration, never public request input.
     */
    public function __construct(
        private UserRepository $userRepository,
        private RoleRepository $roleRepository,
        private ActivationGrantRepository $activationGrantRepository,
        private TransactionalUnitOfWork $unitOfWork,
        private ActivationCredentialGenerator $activationCredentialGenerator,
        private InvitationDeliveryCipher $invitationDeliveryCipher,
        private Clock $clock,
        private EventDispatcher $eventDispatcher,
        array $defaultRoleNames,
        private ?AuditEvidenceRepository $auditEvidenceRepository = null
    ) {
        if ($defaultRoleNames === []) {
            throw new LogicException('Registration requires default Roles.');
        }

        $names = [];
        foreach ($defaultRoleNames as $name) {
            $roleName = RoleName::fromString($name);
            if ($roleName->toString() === Role::SUPER_ADMIN_NAME) {
                throw new LogicException('The Super Admin Role cannot be a registration default.');
            }

            $names[$name] = $roleName;
        }

        $this->defaultRoleNames = array_values($names);
    }

    /**
     * @inheritDoc
     */
    public static function commandRegistration(): string
    {
        return RegisterUser::class;
    }

    /**
     * @inheritDoc
     */
    public function handle(CommandMessage $commandMessage): void
    {
        /** @var RegisterUser $command */
        $command = $commandMessage->payload();

        try {
            $issuedAt = $this->clock->now();
            /** @var ActivationDeliveryId $deliveryId */
            $deliveryId = $this->unitOfWork->commitTransactional(function () use (
                $command,
                $commandMessage,
                $issuedAt
            ): ActivationDeliveryId {
                $roles = [];
                $user = User::invite($command->getUserId(), $command->getEmail(), $issuedAt);
                foreach ($this->defaultRoleNames as $name) {
                    $role = $this->roleRepository->getByName($name);
                    if ($role === null || !$role->getName()->equals($name)) {
                        throw new LogicException('A registration default Role is unavailable.');
                    }

                    if (
                        array_any(
                            $roles,
                            static fn (Role $resolved): bool => $resolved->getId()->equals($role->getId())
                        )
                    ) {
                        throw new LogicException('Registration default Role identities conflict.');
                    }

                    $roles[] = $role;
                    $user->assignRole($role->getId(), $issuedAt);
                }

                try {
                    $added = $this->userRepository->addWithRoleAssignments($user, $roles);
                } catch (DuplicateEmailException $duplicateEmailException) {
                    throw new StateConflict(
                        'The registration email is already claimed.',
                        previous: $duplicateEmailException
                    );
                }

                if (!$added) {
                    throw new StateConflict('Registration default Roles are no longer authoritative.');
                }

                $credential = $this->activationCredentialGenerator->generate();
                $grant = ActivationGrant::issue(
                    ActivationOrigin::REGISTRATION,
                    $user->getId(),
                    $credential,
                    $issuedAt,
                    $issuedAt->add(new DateInterval('P7D')),
                    $user->getEmail(),
                    $this->invitationDeliveryCipher->encrypt($credential->toString())
                );
                if (!$this->activationGrantRepository->add($grant)) {
                    throw new LogicException('The activation grant could not be added.');
                }

                $this->auditEvidenceRepository?->add(AuditEvidence::record(
                    AnonymousId::generate(),
                    new AuditSubject($user->getId()),
                    AuditAction::USER_REGISTERED,
                    $issuedAt,
                    new AuditUserOutcome(
                        null,
                        AuditUserRevisions::fromUser($user),
                        null,
                        AuditGrantState::fromGrant($grant)
                    ),
                    new AuditCorrelation($commandMessage->id(), null)
                ));

                return $grant->getDelivery()->getId();
            });

            $this->eventDispatcher->trigger(new UserRegistered(
                $command->getUserId(),
                $deliveryId,
                $command->getEmail(),
                $issuedAt
            ));
        } catch (Throwable $throwable) {
            try {
                // Storage and subscriber diagnostics can contain credential material.
                $this->eventDispatcher->trigger(new CommandFailedEvent($command, 'User registration failed.'));
            } catch (Throwable) {
                // Notification failure must not replace the original operation failure.
            }

            throw $throwable;
        }
    }
}
