<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Role\CommandHandler;

use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionRepository;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionTier;
use Fight\AccessControl\Domain\AccessControl\Role\Command\GrantPermissionToCustomRole;
use Fight\AccessControl\Domain\AccessControl\Role\Event\CustomRolePermissionGranted;
use Fight\AccessControl\Domain\AccessControl\Role\Exception\CustomRoleException;
use Fight\AccessControl\Domain\AccessControl\Role\Role;
use Fight\AccessControl\Domain\AccessControl\Role\RoleRepository;
use Fight\Common\Application\Messaging\Command\CommandHandler;
use Fight\Common\Application\Messaging\Event\EventDispatcher;
use Fight\Common\Application\Repository\TransactionalUnitOfWork;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Throwable;

/**
 * Class GrantPermissionToCustomRoleHandler
 *
 * Atomically grants an eligible permission to a custom role.
 */
final readonly class GrantPermissionToCustomRoleHandler implements CommandHandler
{
    /**
     * Constructs GrantPermissionToCustomRoleHandler
     *
     * Creates the custom-role permission-grant handler.
     */
    public function __construct(
        private RoleRepository $roleRepository,
        private PermissionRepository $permissionRepository,
        private Clock $clock,
        private TransactionalUnitOfWork $unitOfWork,
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
                function () use ($command): ?CustomRolePermissionGranted {
                    $role = $this->roleRepository->getById($command->getRoleId());
                    if (!$role instanceof Role || !$role->getId()->equals($command->getRoleId())) {
                        throw new CustomRoleException('The custom role does not exist.');
                    }

                    $role->assertCustom();
                    $permission = $this->permissionRepository->getById($command->getPermissionId());
                    if (
                        !$permission instanceof Permission
                        || !$permission->getId()->equals($command->getPermissionId())
                    ) {
                        throw new CustomRoleException('The permission does not exist.');
                    }

                    if ($permission->getTier() !== PermissionTier::ADMIN_SAFE) {
                        throw new CustomRoleException('The permission is not eligible for a custom role.');
                    }

                    if (!$this->roleRepository->validateCustomPermissionGrant($permission)) {
                        throw new CustomRoleException('The authoritative permission changed concurrently.');
                    }

                    if ($role->hasPermission($command->getPermissionId())) {
                        return null;
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

            if ($event instanceof CustomRolePermissionGranted) {
                $this->eventDispatcher->trigger($event);
            }
        } catch (Throwable $throwable) {
            $this->eventDispatcher->trigger(new CommandFailedEvent($command, $throwable->getMessage()));
            throw $throwable;
        }
    }
}
