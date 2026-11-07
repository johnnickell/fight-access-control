<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Role\CommandHandler;

use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Domain\AccessControl\Role\Command\CreateCustomRole;
use Fight\AccessControl\Domain\AccessControl\Role\Event\CustomRoleCreated;
use Fight\AccessControl\Domain\AccessControl\Role\Exception\CustomRoleException;
use Fight\AccessControl\Domain\AccessControl\Role\Role;
use Fight\AccessControl\Domain\AccessControl\Role\RoleRepository;
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
 * Class CreateCustomRoleHandler
 *
 * Atomically creates a runtime-owned custom role.
 */
final readonly class CreateCustomRoleHandler implements CommandHandler
{
    /**
     * Constructs CreateCustomRoleHandler
     *
     * Creates the custom-role creation handler.
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
        return CreateCustomRole::class;
    }

    /**
     * @inheritDoc
     */
    public function handle(CommandMessage $commandMessage): void
    {
        /** @var CreateCustomRole $command */
        $command = $commandMessage->payload();

        try {
            /** @var CustomRoleCreated $event */
            $event = $this->unitOfWork->commitTransactional(function () use ($command): CustomRoleCreated {
                $existing = $this->roleRepository->getById($command->getRoleId());
                if ($existing !== null) {
                    if (!$existing->getId()->equals($command->getRoleId())) {
                        throw new LogicException('The Role repository returned an unrelated identity.');
                    }

                    throw new StateConflict('The custom role identifier is already in use.');
                }

                $nameOwner = $this->roleRepository->getByName($command->getName());
                if ($nameOwner !== null) {
                    if (!$nameOwner->getName()->equals($command->getName())) {
                        throw new LogicException('The Role repository returned an unrelated name.');
                    }

                    throw new StateConflict('The custom role name is already in use.');
                }

                $createdAt = $this->clock->now();
                try {
                    $role = Role::define($command->getRoleId(), $command->getName(), [], $createdAt);
                } catch (CustomRoleException $customRoleException) {
                    throw new RuleViolation(
                        'The custom role definition is not permitted.',
                        previous: $customRoleException
                    );
                }

                $this->roleRepository->add($role);

                return new CustomRoleCreated(
                    $command->getActorId(),
                    $command->getRoleId(),
                    $command->getName(),
                    $createdAt
                );
            });

            $this->eventDispatcher->trigger($event);
        } catch (Throwable $throwable) {
            try {
                $this->eventDispatcher->trigger(new CommandFailedEvent($command, 'Custom role creation failed.'));
            } catch (Throwable) {
                // Notification failure must not replace the original operation failure.
            }

            throw $throwable;
        }
    }
}
