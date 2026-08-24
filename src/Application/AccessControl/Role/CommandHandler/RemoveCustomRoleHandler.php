<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Role\CommandHandler;

use Fight\AccessControl\Application\AccessControl\Role\Service\RoleAdministrationAuthorization;
use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Domain\AccessControl\Role\Command\RemoveCustomRole;
use Fight\AccessControl\Domain\AccessControl\Role\Event\CustomRoleRemoved;
use Fight\AccessControl\Domain\AccessControl\Role\Exception\CustomRoleException;
use Fight\AccessControl\Domain\AccessControl\Role\Role;
use Fight\AccessControl\Domain\AccessControl\Role\RoleRepository;
use Fight\AccessControl\Domain\AccessControl\User\UserRepository;
use Fight\Common\Application\Messaging\Command\CommandHandler;
use Fight\Common\Application\Messaging\Event\EventDispatcher;
use Fight\Common\Application\Repository\UnitOfWork;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Throwable;

/**
 * Atomically removes an authorized, unreferenced custom role.
 */
final readonly class RemoveCustomRoleHandler implements CommandHandler
{
    /**
     * Creates the custom-role removal handler.
     */
    public function __construct(
        private RoleRepository $roleRepository,
        private UserRepository $userRepository,
        private RoleAdministrationAuthorization $roleAdministrationAuthorization,
        private Clock $roleClock,
        private UnitOfWork $unitOfWork,
        private EventDispatcher $eventDispatcher
    ) {
    }

    /** @inheritDoc */
    public static function commandRegistration(): string
    {
        return RemoveCustomRole::class;
    }

    /** @inheritDoc */
    public function handle(CommandMessage $commandMessage): void
    {
        /** @var RemoveCustomRole $command */
        $command = $commandMessage->payload();

        try {
            $event = $this->unitOfWork->commitTransactional(function () use ($command): CustomRoleRemoved {
                $this->roleAdministrationAuthorization->assertCanManageRoles($command->getActorId());

                $role = $this->roleRepository->getById($command->getRoleId());
                if (!$role instanceof Role) {
                    throw new CustomRoleException('The custom role does not exist.');
                }

                $role->assertCustom();
                if ($this->userRepository->hasRoleAssignment($command->getRoleId())) {
                    throw new CustomRoleException('The custom role remains assigned to a user.');
                }

                $removedAt = $this->roleClock->now();
                if (!$this->roleRepository->remove($role)) {
                    throw new CustomRoleException('The custom role changed or became assigned concurrently.');
                }

                return new CustomRoleRemoved($command->getActorId(), $command->getRoleId(), $removedAt);
            });

            $this->eventDispatcher->trigger($event);
        } catch (Throwable $throwable) {
            $this->eventDispatcher->trigger(new CommandFailedEvent($command, $throwable->getMessage()));
            throw $throwable;
        }
    }
}
