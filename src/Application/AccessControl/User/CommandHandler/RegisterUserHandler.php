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
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidenceRepository;
use Fight\AccessControl\Domain\AccessControl\Role\Role;
use Fight\AccessControl\Domain\AccessControl\Role\RoleName;
use Fight\AccessControl\Domain\AccessControl\Role\RoleRepository;
use Fight\AccessControl\Domain\AccessControl\User\Command\RegisterUser;
use Fight\AccessControl\Domain\AccessControl\User\Event\UserRegistered;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserRepository;
use Fight\Common\Application\Messaging\Command\CommandHandler;
use Fight\Common\Application\Messaging\Event\EventDispatcher;
use Fight\Common\Application\Repository\TransactionalUnitOfWork;
use Fight\Common\Domain\Exception\DomainException;
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
        private AuditEvidenceRepository $auditEvidenceRepository,
        private TransactionalUnitOfWork $unitOfWork,
        private ActivationCredentialGenerator $credentials,
        private InvitationDeliveryCipher $cipher,
        private Clock $clock,
        private EventDispatcher $eventDispatcher,
        array $defaultRoleNames
    ) {
        if ($defaultRoleNames === []) {
            throw new DomainException('Registration requires default Roles.');
        }

        $names = [];
        foreach ($defaultRoleNames as $name) {
            $roleName = RoleName::fromString($name);
            if ($roleName->toString() === Role::SUPER_ADMIN_NAME) {
                throw new DomainException('The Super Admin Role cannot be a registration default.');
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
            $deliveryId = $this->unitOfWork->commitTransactional(function () use (
                $command,
                $issuedAt
            ): ActivationDeliveryId {
                $roles = [];
                $user = User::invite($command->getUserId(), $command->getEmail(), $issuedAt);
                foreach ($this->defaultRoleNames as $name) {
                    $role = $this->roleRepository->getByName($name);
                    if (!$role instanceof Role || !$role->getName()->equals($name)) {
                        throw new DomainException('A registration default Role is unavailable.');
                    }

                    $roles[] = $role;
                    $user->assignRole($role->getId(), $issuedAt);
                }

                if (!$this->userRepository->addWithRoleAssignments($user, $roles)) {
                    throw new DomainException('Registration default Roles are no longer authoritative.');
                }

                $credential = $this->credentials->generate();
                $grant = ActivationGrant::issue(
                    ActivationOrigin::REGISTRATION,
                    $user->getId(),
                    $credential,
                    $issuedAt,
                    $issuedAt->add(new DateInterval('P7D')),
                    $user->getEmail(),
                    $this->cipher->encrypt($credential->toString())
                );
                if (!$this->activationGrantRepository->add($grant)) {
                    throw new LogicException('The activation grant could not be added.');
                }

                $this->auditEvidenceRepository->add(AuditEvidence::record(
                    'registration',
                    'user.registered',
                    $user->getId()
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
