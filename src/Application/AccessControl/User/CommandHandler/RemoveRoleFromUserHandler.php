<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\User\CommandHandler;

use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Application\AccessControl\User\Service\UserRoleAssignmentAdministrationAuthorization;
use Fight\AccessControl\Domain\AccessControl\Role\Role;
use Fight\AccessControl\Domain\AccessControl\Role\RoleRepository;
use Fight\AccessControl\Domain\AccessControl\User\Command\RemoveRoleFromUser;
use Fight\AccessControl\Domain\AccessControl\User\Event\RoleRemovedFromUser;
use Fight\AccessControl\Domain\AccessControl\User\Exception\UserRoleAssignmentException;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserRepository;
use Fight\Common\Application\Messaging\Command\CommandHandler;
use Fight\Common\Application\Messaging\Event\EventDispatcher;
use Fight\Common\Application\Repository\UnitOfWork;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Throwable;

/**
 * Atomically removes an authoritative role from an authorized User target.
 */
final readonly class RemoveRoleFromUserHandler implements CommandHandler
{
    /**
     * Creates the User role-removal handler.
     */
    public function __construct(
        private UserRepository $userRepository,
        private RoleRepository $roleRepository,
        private UserRoleAssignmentAdministrationAuthorization $userRoleAssignmentAdministrationAuthorization,
        private Clock $clock,
        private UnitOfWork $unitOfWork,
        private EventDispatcher $eventDispatcher
    ) {
    }

    /** @inheritDoc */
    public static function commandRegistration(): string
    {
        return RemoveRoleFromUser::class;
    }

    /** @inheritDoc */
    public function handle(CommandMessage $commandMessage): void
    {
        /** @var RemoveRoleFromUser $command */
        $command = $commandMessage->payload();

        try {
            $event = $this->unitOfWork->commitTransactional(function () use ($command): ?RoleRemovedFromUser {
                $this->userRoleAssignmentAdministrationAuthorization->assertCanManageUserRoleAssignments(
                    $command->getActorId()
                );

                $user = $this->userRepository->getById($command->getTargetUserId());
                if (!$user instanceof User) {
                    throw new UserRoleAssignmentException('The target user does not exist.');
                }

                if (!$this->roleRepository->getById($command->getRoleId()) instanceof Role) {
                    throw new UserRoleAssignmentException('The role does not exist.');
                }

                $removedAt = $this->clock->now();
                $replacement = clone $user;
                if (!$replacement->removeRole($command->getRoleId(), $removedAt)) {
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

                return new RoleRemovedFromUser(
                    $command->getActorId(),
                    $command->getTargetUserId(),
                    $command->getRoleId(),
                    $removedAt
                );
            });

            if ($event instanceof RoleRemovedFromUser) {
                $this->eventDispatcher->trigger($event);
            }
        } catch (Throwable $throwable) {
            $this->eventDispatcher->trigger(new CommandFailedEvent($command, $throwable->getMessage()));
            throw $throwable;
        }
    }
}
