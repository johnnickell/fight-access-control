<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Role\CommandHandler;

use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionRepository;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionTier;
use Fight\AccessControl\Domain\AccessControl\Role\Command\GrantPermissionToCustomRole;
use Fight\AccessControl\Domain\AccessControl\Role\Event\CustomRolePermissionGranted;
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

    /**
     * @inheritDoc
     */
    public static function commandRegistration(): string
    {
        return GrantPermissionToCustomRole::class;
    }

    /**
     * @inheritDoc
     */
    public function handle(CommandMessage $commandMessage): void
    {
        /** @var GrantPermissionToCustomRole $command */
        $command = $commandMessage->payload();

        try {
            /** @var CustomRolePermissionGranted|null $event */
            $event = $this->unitOfWork->commitTransactional(
                function () use ($command): ?CustomRolePermissionGranted {
                    $role = $this->roleRepository->getById($command->getRoleId());
                    if ($role === null) {
                        throw new ResourceNotFound('The custom role does not exist.');
                    }

                    if (!$role->getId()->equals($command->getRoleId())) {
                        throw new LogicException('The Role repository returned an unrelated identity.');
                    }

                    try {
                        $role->assertCustom();
                    } catch (CustomRoleException $customRoleException) {
                        throw new RuleViolation('The role is not runtime-owned.', previous: $customRoleException);
                    }

                    $permission = $this->permissionRepository->getById($command->getPermissionId());
                    if ($permission === null) {
                        throw new ResourceNotFound('The permission does not exist.');
                    }

                    if (!$permission->getId()->equals($command->getPermissionId())) {
                        throw new LogicException('The Permission repository returned an unrelated identity.');
                    }

                    if ($permission->getTier() !== PermissionTier::ADMIN_SAFE) {
                        throw new RuleViolation('The permission is not eligible for a custom role.');
                    }

                    if (!$this->roleRepository->validateCustomPermissionGrant($permission)) {
                        throw new StateConflict('The authoritative permission changed concurrently.');
                    }

                    if ($role->hasPermission($command->getPermissionId())) {
                        if (!$this->roleRepository->validateCurrent($role)) {
                            throw new StateConflict('The custom role changed concurrently.');
                        }

                        return null;
                    }

                    $grantedAt = $this->clock->now();
                    $replacement = $role->grantPermissionToCustom($command->getPermissionId(), $grantedAt);
                    if (!$this->roleRepository->replace($role, $replacement)) {
                        throw new StateConflict('The custom role changed concurrently.');
                    }

                    return new CustomRolePermissionGranted(
                        $command->getActorId(),
                        $command->getRoleId(),
                        $command->getPermissionId(),
                        $grantedAt
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
                    'Custom role permission grant failed.'
                ));
            } catch (Throwable) {
                // Notification failure must not replace the original operation failure.
            }

            throw $throwable;
        }
    }
}
