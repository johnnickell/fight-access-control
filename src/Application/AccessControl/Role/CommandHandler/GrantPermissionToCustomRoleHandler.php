<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Role\CommandHandler;

use Fight\AccessControl\Application\AccessControl\Role\Service\RoleAdministrationAuthorization;
use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionRepository;
use Fight\AccessControl\Domain\AccessControl\Role\Command\GrantPermissionToCustomRole;
use Fight\AccessControl\Domain\AccessControl\Role\Event\CustomRolePermissionGranted;
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
 * Atomically grants an existing permission to an authorized custom role.
 */
final readonly class GrantPermissionToCustomRoleHandler implements CommandHandler
{
    /**
     * Creates the custom-role permission-grant handler.
     */
    public function __construct(
        private RoleRepository $roleRepository,
        private PermissionRepository $permissionRepository,
        private RoleAdministrationAuthorization $roleAdministrationAuthorization,
        private Clock $clock,
        private UnitOfWork $unitOfWork,
        private EventDispatcher $eventDispatcher
    ) {
    }

    /** @inheritDoc */
    public static function commandRegistration(): string
    {
        return GrantPermissionToCustomRole::class;
    }

    /** @inheritDoc */
    public function handle(CommandMessage $commandMessage): void
    {
        /** @var GrantPermissionToCustomRole $command */
        $command = $commandMessage->payload();

        try {
            $event = $this->unitOfWork->commitTransactional(
                function () use ($command): CustomRolePermissionGranted {
                    $this->roleAdministrationAuthorization->assertCanManageRoles($command->getActorId());

                    $role = $this->roleRepository->getById($command->getRoleId());
                    if (!$role instanceof Role) {
                        throw new CustomRoleException('The custom role does not exist.');
                    }

                    if (!$this->permissionRepository->getById($command->getPermissionId()) instanceof Permission) {
                        throw new CustomRoleException('The permission does not exist.');
                    }

                    $grantedAt = $this->clock->now();
                    $replacement = $role->grantPermissionToCustom($command->getPermissionId(), $grantedAt);
                    if (!$this->roleRepository->replace($role, $replacement)) {
                        throw new CustomRoleException('The custom role changed concurrently.');
                    }

                    return new CustomRolePermissionGranted(
                        $command->getActorId(),
                        $command->getRoleId(),
                        $command->getPermissionId(),
                        $grantedAt
                    );
                }
            );

            $this->eventDispatcher->trigger($event);
        } catch (Throwable $throwable) {
            $this->eventDispatcher->trigger(new CommandFailedEvent($command, $throwable->getMessage()));
            throw $throwable;
        }
    }
}
