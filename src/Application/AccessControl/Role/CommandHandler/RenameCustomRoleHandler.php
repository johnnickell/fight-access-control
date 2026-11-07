<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Role\CommandHandler;

use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Domain\AccessControl\Role\Command\RenameCustomRole;
use Fight\AccessControl\Domain\AccessControl\Role\Event\CustomRoleRenamed;
use Fight\AccessControl\Domain\AccessControl\Role\Exception\CustomRoleException;
use Fight\AccessControl\Domain\AccessControl\Role\Exception\RoleConflictException;
use Fight\AccessControl\Domain\AccessControl\Role\RoleRepository;
use Fight\Common\Application\Error\Exception\PreconditionFailed;
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
 * Class RenameCustomRoleHandler
 *
 * Atomically renames a runtime-owned custom role.
 */
final readonly class RenameCustomRoleHandler implements CommandHandler
{
    /**
     * Constructs RenameCustomRoleHandler
     *
     * Creates the custom-role rename handler.
     */
    public function __construct(
        private RoleRepository $roleRepository,
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
        return RenameCustomRole::class;
    }

    /**
     * @inheritDoc
     */
    public function handle(CommandMessage $commandMessage): void
    {
        /** @var RenameCustomRole $command */
        $command = $commandMessage->payload();

        try {
            /** @var CustomRoleRenamed|null $event */
            $event = $this->unitOfWork->commitTransactional(function () use ($command): ?CustomRoleRenamed {
                $role = $this->roleRepository->getById($command->getRoleId());
                if ($role === null) {
                    throw new ResourceNotFound('The custom role does not exist.');
                }

                if (!$role->getId()->equals($command->getRoleId())) {
                    throw new LogicException('The Role repository returned an unrelated identity.');
                }

                try {
                    $role->assertRevision($command->getExpectedRevision());
                } catch (RoleConflictException $roleConflictException) {
                    throw new PreconditionFailed(
                        'The expected Role revision is no longer current.',
                        previous: $roleConflictException
                    );
                }

                try {
                    $role->assertCustom();
                } catch (CustomRoleException $customRoleException) {
                    throw new RuleViolation('The role is not runtime-owned.', previous: $customRoleException);
                }

                if ($role->getName()->equals($command->getName())) {
                    if (!$this->roleRepository->validateCurrent($role)) {
                        throw new StateConflict('The custom role changed concurrently.');
                    }

                    return null;
                }

                $nameOwner = $this->roleRepository->getByName($command->getName());
                if ($nameOwner !== null) {
                    if (!$nameOwner->getName()->equals($command->getName())) {
                        throw new LogicException('The Role repository returned an unrelated name.');
                    }

                    if ($nameOwner->getId()->equals($role->getId())) {
                        if (!$nameOwner->getRevision()->equals($role->getRevision())) {
                            throw new StateConflict('The custom role changed concurrently.');
                        }

                        throw new LogicException('The Role repository returned contradictory snapshots.');
                    }

                    throw new StateConflict('The custom role name is already in use.');
                }

                $renamedAt = $this->clock->now();
                try {
                    $replacement = $role->renameCustom($command->getName(), $renamedAt);
                } catch (CustomRoleException $customRoleException) {
                    throw new RuleViolation(
                        'The custom role definition is not permitted.',
                        previous: $customRoleException
                    );
                }

                if (!$this->roleRepository->replace($role, $replacement)) {
                    throw new StateConflict('The custom role changed concurrently.');
                }

                return new CustomRoleRenamed(
                    $command->getActorId(),
                    $command->getRoleId(),
                    $command->getName(),
                    $renamedAt
                );
            });

            if ($event !== null) {
                $this->eventDispatcher->trigger($event);
            }
        } catch (Throwable $throwable) {
            try {
                $this->eventDispatcher->trigger(new CommandFailedEvent($command, 'Custom role rename failed.'));
            } catch (Throwable) {
                // Notification failure must not replace the original operation failure.
            }

            throw $throwable;
        }
    }
}
