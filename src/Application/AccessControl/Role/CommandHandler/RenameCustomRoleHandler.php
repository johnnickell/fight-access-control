<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Role\CommandHandler;

use Fight\AccessControl\Application\AccessControl\Role\Service\RoleAdministrationAuthorization;
use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Domain\AccessControl\Role\Command\RenameCustomRole;
use Fight\AccessControl\Domain\AccessControl\Role\Event\CustomRoleRenamed;
use Fight\AccessControl\Domain\AccessControl\Role\Exception\CustomRoleException;
use Fight\AccessControl\Domain\AccessControl\Role\Role;
use Fight\AccessControl\Domain\AccessControl\Role\RoleRepository;
use Fight\Common\Application\Messaging\Command\CommandHandler;
use Fight\Common\Application\Messaging\Event\EventDispatcher;
use Fight\Common\Application\Repository\UnitOfWork;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Throwable;

/**
 * Atomically renames an authorized runtime-owned custom role.
 */
final readonly class RenameCustomRoleHandler implements CommandHandler
{
    /**
     * Creates the custom-role rename handler.
     */
    public function __construct(
        private RoleRepository $roleRepository,
        private RoleAdministrationAuthorization $roleAdministrationAuthorization,
        private Clock $clock,
        private UnitOfWork $unitOfWork,
        private EventDispatcher $eventDispatcher
    ) {
    }

    /** @inheritDoc */
    public static function commandRegistration(): string
    {
        return RenameCustomRole::class;
    }

    /** @inheritDoc */
    public function handle(CommandMessage $commandMessage): void
    {
        /** @var RenameCustomRole $command */
        $command = $commandMessage->payload();

        try {
            $event = $this->unitOfWork->commitTransactional(function () use ($command): CustomRoleRenamed {
                $this->roleAdministrationAuthorization->assertCanManageRoles($command->getActorId());

                $role = $this->roleRepository->getById($command->getRoleId());
                if (!$role instanceof Role) {
                    throw new CustomRoleException('The custom role does not exist.');
                }

                $nameOwner = $this->roleRepository->getByName($command->getName());
                if ($nameOwner instanceof Role && !$nameOwner->getId()->equals($role->getId())) {
                    throw new CustomRoleException('The custom role name is already in use.');
                }

                $renamedAt = $this->clock->now();
                $replacement = $role->renameCustom($command->getName(), $renamedAt);
                if (!$this->roleRepository->replace($role, $replacement)) {
                    throw new CustomRoleException('The custom role changed concurrently.');
                }

                return new CustomRoleRenamed(
                    $command->getActorId(),
                    $command->getRoleId(),
                    $command->getName(),
                    $renamedAt
                );
            });

            $this->eventDispatcher->trigger($event);
        } catch (Throwable $throwable) {
            $this->eventDispatcher->trigger(new CommandFailedEvent($command, $throwable->getMessage()));
            throw $throwable;
        }
    }
}
