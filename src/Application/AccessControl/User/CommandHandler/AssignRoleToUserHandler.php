<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\User\CommandHandler;

use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Domain\AccessControl\Role\Exception\ManagedRoleException;
use Fight\AccessControl\Domain\AccessControl\Role\Role;
use Fight\AccessControl\Domain\AccessControl\Role\RoleName;
use Fight\AccessControl\Domain\AccessControl\Role\RoleRepository;
use Fight\AccessControl\Domain\AccessControl\User\Command\AssignRoleToUser;
use Fight\AccessControl\Domain\AccessControl\User\Event\RoleAssignedToUser;
use Fight\AccessControl\Domain\AccessControl\User\Exception\UserRoleAssignmentException;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserRepository;
use Fight\Common\Application\Messaging\Command\CommandHandler;
use Fight\Common\Application\Messaging\Event\EventDispatcher;
use Fight\Common\Application\Repository\TransactionalUnitOfWork;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Throwable;

/**
 * Class AssignRoleToUserHandler
 *
 * Atomically assigns an authoritative role to a User target.
 */
final readonly class AssignRoleToUserHandler implements CommandHandler
{
    /**
     * Constructs AssignRoleToUserHandler
     *
     * Creates the User role-assignment handler.
     */
    public function __construct(
        private UserRepository $userRepository,
        private RoleRepository $roleRepository,
        private Clock $clock,
        private TransactionalUnitOfWork $unitOfWork,
        private EventDispatcher $eventDispatcher
    ) {
    }

    /** @inheritDoc */
    public static function commandRegistration(): string
    {
        return AssignRoleToUser::class;
    }

    /** @inheritDoc */
    public function handle(CommandMessage $commandMessage): void
    {
        /** @var AssignRoleToUser $command */
        $command = $commandMessage->payload();

        try {
            $event = $this->unitOfWork->commitTransactional(function () use ($command): ?RoleAssignedToUser {
                $user = $this->userRepository->getById($command->getTargetUserId());
                if (!$user instanceof User) {
                    throw new UserRoleAssignmentException('The target user does not exist.');
                }

                $role = $this->roleRepository->getById($command->getRoleId());
                if (!$role instanceof Role || !$role->getId()->equals($command->getRoleId())) {
                    throw new UserRoleAssignmentException('The role does not exist.');
                }

                try {
                    $role->assertConsistentWithSuperAdmin(
                        $this->roleRepository->getByName(RoleName::fromString(Role::SUPER_ADMIN_NAME))
                    );
                } catch (ManagedRoleException) {
                    throw new UserRoleAssignmentException('The authoritative role identity is inconsistent.');
                }

                $assignedAt = $this->clock->now();
                $replacement = clone $user;
                if (!$replacement->assignRole($command->getRoleId(), $assignedAt)) {
                    if (!$this->userRepository->validateRoleAssignmentReference($command->getRoleId())) {
                        throw new UserRoleAssignmentException(
                            'The authoritative role changed concurrently.'
                        );
                    }

                    return null;
                }

                if (!$this->userRepository->replaceRoleAssignments($user, $replacement)) {
                    throw new UserRoleAssignmentException(
                        'The user role assignments or authoritative roles changed concurrently.'
                    );
                }

                return new RoleAssignedToUser(
                    $command->getActorId(),
                    $command->getTargetUserId(),
                    $command->getRoleId(),
                    $assignedAt
                );
            });

            if ($event instanceof RoleAssignedToUser) {
                $this->eventDispatcher->trigger($event);
            }
        } catch (Throwable $throwable) {
            $this->eventDispatcher->trigger(new CommandFailedEvent($command, $throwable->getMessage()));
            throw $throwable;
        }
    }
}
