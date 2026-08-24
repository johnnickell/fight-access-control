<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Role\CommandHandler;

use Fight\AccessControl\Application\AccessControl\Role\Service\RoleAdministrationAuthorization;
use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Domain\AccessControl\Role\Command\CreateCustomRole;
use Fight\AccessControl\Domain\AccessControl\Role\Event\CustomRoleCreated;
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
 * Atomically creates an authorized runtime-owned custom role.
 */
final readonly class CreateCustomRoleHandler implements CommandHandler
{
    /**
     * Creates the custom-role creation handler.
     */
    public function __construct(
        private RoleRepository $roleRepository,
        private RoleAdministrationAuthorization $roleAdministrationAuthorization,
        private Clock $roleClock,
        private UnitOfWork $unitOfWork,
        private EventDispatcher $eventDispatcher
    ) {
    }

    /** @inheritDoc */
    public static function commandRegistration(): string
    {
        return CreateCustomRole::class;
    }

    /** @inheritDoc */
    public function handle(CommandMessage $commandMessage): void
    {
        /** @var CreateCustomRole $command */
        $command = $commandMessage->payload();

        try {
            $event = $this->unitOfWork->commitTransactional(function () use ($command): CustomRoleCreated {
                $this->roleAdministrationAuthorization->assertCanManageRoles($command->getActorId());

                if (
                    $this->roleRepository->getById($command->getRoleId()) instanceof Role
                    || $this->roleRepository->getByName($command->getName()) instanceof Role
                ) {
                    throw new CustomRoleException('The custom role identifier or name is already in use.');
                }

                $createdAt = $this->roleClock->now();
                $role = Role::define($command->getRoleId(), $command->getName(), [], $createdAt);
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
            $this->eventDispatcher->trigger(new CommandFailedEvent($command, $throwable->getMessage()));
            throw $throwable;
        }
    }
}
