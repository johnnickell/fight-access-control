<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\User\CommandHandler;

use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditAction;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditCorrelation;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidenceRepository;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditSubject;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditUserRevisions;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditUserRoleOutcome;
use Fight\AccessControl\Domain\AccessControl\Role\Exception\ManagedRoleException;
use Fight\AccessControl\Domain\AccessControl\Role\Role;
use Fight\AccessControl\Domain\AccessControl\Role\RoleName;
use Fight\AccessControl\Domain\AccessControl\Role\RoleRepository;
use Fight\AccessControl\Domain\AccessControl\User\Command\RemoveRoleFromUser;
use Fight\AccessControl\Domain\AccessControl\User\Event\RoleRemovedFromUser;
use Fight\AccessControl\Domain\AccessControl\User\UserRepository;
use Fight\Common\Application\Error\Exception\ResourceNotFound;
use Fight\Common\Application\Error\Exception\StateConflict;
use Fight\Common\Application\Messaging\Command\CommandHandler;
use Fight\Common\Application\Messaging\Event\EventDispatcher;
use Fight\Common\Application\Repository\TransactionalUnitOfWork;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use LogicException;
use Throwable;

/**
 * Class RemoveRoleFromUserHandler
 *
 * Atomically removes an authoritative role from a User target.
 */
final readonly class RemoveRoleFromUserHandler implements CommandHandler
{
    /**
     * Constructs RemoveRoleFromUserHandler
     *
     * Creates the User role-removal handler.
     */
    public function __construct(
        private UserRepository $userRepository,
        private RoleRepository $roleRepository,
        private Clock $clock,
        private TransactionalUnitOfWork $unitOfWork,
        private EventDispatcher $eventDispatcher,
        private ?AuditEvidenceRepository $auditEvidenceRepository = null
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function commandRegistration(): string
    {
        return RemoveRoleFromUser::class;
    }

    /**
     * @inheritDoc
     */
    public function handle(CommandMessage $commandMessage): void
    {
        /** @var RemoveRoleFromUser $command */
        $command = $commandMessage->payload();
        $messageId = $commandMessage->id();

        try {
            /** @var RoleRemovedFromUser|null $event */
            $event = $this->unitOfWork->commitTransactional(function () use (
                $command,
                $messageId
            ): ?RoleRemovedFromUser {
                $user = $this->userRepository->getById($command->getTargetUserId());
                if ($user === null) {
                    throw new ResourceNotFound('The target user does not exist.');
                }

                if (!$user->getId()->equals($command->getTargetUserId())) {
                    throw new LogicException('The User repository returned an unrelated identity.');
                }

                $role = $this->roleRepository->getById($command->getRoleId());
                if ($role === null) {
                    throw new ResourceNotFound('The role does not exist.');
                }

                if (!$role->getId()->equals($command->getRoleId())) {
                    throw new LogicException('The Role repository returned an unrelated identity.');
                }

                try {
                    $role->assertConsistentWithSuperAdmin(
                        $this->roleRepository->getByName(RoleName::fromString(Role::SUPER_ADMIN_NAME))
                    );
                } catch (ManagedRoleException $managedRoleException) {
                    throw new LogicException(
                        'The authoritative role identity is inconsistent.',
                        previous: $managedRoleException
                    );
                }

                $removedAt = $this->clock->now();
                $replacement = clone $user;
                if (!$replacement->removeRole($command->getRoleId(), $removedAt)) {
                    if (!$this->userRepository->validateRoleAssignmentReference($command->getRoleId())) {
                        throw new StateConflict(
                            'The authoritative role changed concurrently.'
                        );
                    }

                    return null;
                }

                if (!$this->userRepository->replaceRoleAssignments($user, $replacement)) {
                    throw new StateConflict(
                        'The user role assignments or authoritative roles changed concurrently.'
                    );
                }

                if ($this->auditEvidenceRepository !== null) {
                    $this->auditEvidenceRepository->add(AuditEvidence::record(
                        $command->getActorId(),
                        new AuditSubject($user->getId()),
                        AuditAction::USER_ROLE_REMOVED,
                        $removedAt,
                        new AuditUserRoleOutcome(
                            AuditUserRevisions::fromUser($user),
                            AuditUserRevisions::fromUser($replacement),
                            $role->getId(),
                            $role->getRevision()
                        ),
                        new AuditCorrelation($messageId, null)
                    ));
                }

                return new RoleRemovedFromUser(
                    $command->getActorId(),
                    $command->getTargetUserId(),
                    $command->getRoleId(),
                    $removedAt
                );
            });

            if ($event !== null) {
                $this->eventDispatcher->trigger($event);
            }
        } catch (Throwable $throwable) {
            try {
                $this->eventDispatcher->trigger(new CommandFailedEvent($command, 'User role removal failed.'));
            } catch (Throwable) {
                // Notification failure must not replace the original operation failure.
            }

            throw $throwable;
        }
    }
}
