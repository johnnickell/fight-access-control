<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Role\CommandHandler;

use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionRepository;
use Fight\AccessControl\Domain\AccessControl\Role\Command\RevokePermissionFromCustomRole;
use Fight\AccessControl\Domain\AccessControl\Role\Event\CustomRolePermissionRevoked;
use Fight\AccessControl\Domain\AccessControl\Role\Exception\CustomRoleException;
use Fight\AccessControl\Domain\AccessControl\Role\RoleRepository;
use Fight\Common\Application\Error\Exception\ResourceNotFound;
use Fight\Common\Application\Error\Exception\RuleViolation;
use Fight\Common\Application\Error\Exception\StateConflict;
use Fight\Common\Application\Messaging\Command\CommandHandler;
use Fight\Common\Application\Messaging\Event\EventDispatcher;
use Fight\Common\Application\Repository\TransactionalUnitOfWork;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use LogicException;
use Throwable;

/**
 * Class RevokePermissionFromCustomRoleHandler
 *
 * Atomically revokes an existing permission from a custom role.
 */
final readonly class RevokePermissionFromCustomRoleHandler implements CommandHandler
{
    /**
     * Constructs RevokePermissionFromCustomRoleHandler
     *
     * Creates the custom-role permission-revocation handler.
     */
    public function __construct(
        private RoleRepository $roleRepository,
        private PermissionRepository $permissionRepository,
        private Clock $clock,
        private TransactionalUnitOfWork $unitOfWork,
        private EventDispatcher $eventDispatcher
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function commandRegistration(): string
    {
        return RevokePermissionFromCustomRole::class;
    }

    /**
     * @inheritDoc
     */
    public function handle(CommandMessage $commandMessage): void
    {
        /** @var RevokePermissionFromCustomRole $command */
        $command = $commandMessage->payload();

        try {
            /** @var CustomRolePermissionRevoked|null $event */
            $event = $this->unitOfWork->commitTransactional(
                function () use ($command): ?CustomRolePermissionRevoked {
                    $role = $this->roleRepository->getById($command->getRoleId());
                    if ($role === null) {
                        throw new ResourceNotFound('The custom role does not exist.');
                    }

                    if (!$role->getId()->equals($command->getRoleId())) {
                        throw new LogicException('The Role repository returned an unrelated identity.');
                    }

                    $permission = $this->permissionRepository->getById($command->getPermissionId());
                    if ($permission === null) {
                        throw new ResourceNotFound('The permission does not exist.');
                    }

                    if (!$permission->getId()->equals($command->getPermissionId())) {
                        throw new LogicException('The Permission repository returned an unrelated identity.');
                    }

                    try {
                        $role->assertCustom();
                    } catch (CustomRoleException $customRoleException) {
                        throw new RuleViolation('The role is not runtime-owned.', previous: $customRoleException);
                    }

                    if (!$role->hasPermission($command->getPermissionId())) {
                        if (!$this->roleRepository->validatePermissionReference($command->getPermissionId())) {
                            throw new StateConflict('The authoritative permission changed concurrently.');
                        }

                        if (!$this->roleRepository->validateCurrent($role)) {
                            throw new StateConflict('The custom role changed concurrently.');
                        }

                        return null;
                    }

                    $revokedAt = $this->clock->now();
                    $replacement = $role->revokePermissionFromCustom($command->getPermissionId(), $revokedAt);
                    if (!$this->roleRepository->replace($role, $replacement)) {
                        throw new StateConflict('The custom role changed concurrently.');
                    }

                    return new CustomRolePermissionRevoked(
                        $command->getActorId(),
                        $command->getRoleId(),
                        $command->getPermissionId(),
                        $revokedAt
                    );
                }
            );

            if ($event !== null) {
                $this->eventDispatcher->trigger($event);
            }
        } catch (Throwable $throwable) {
            try {
                $this->eventDispatcher->trigger(new CommandFailedEvent(
                    $command,
                    'Custom role permission revocation failed.'
                ));
            } catch (Throwable) {
                // Notification failure must not replace the original operation failure.
            }

            throw $throwable;
        }
    }
}
