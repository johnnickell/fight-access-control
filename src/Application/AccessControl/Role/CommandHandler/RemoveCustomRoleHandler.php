<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Role\CommandHandler;

use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditAction;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditCorrelation;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidenceRepository;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditRoleRevisions;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditSubject;
use Fight\AccessControl\Domain\AccessControl\Role\Command\RemoveCustomRole;
use Fight\AccessControl\Domain\AccessControl\Role\Event\CustomRoleRemoved;
use Fight\AccessControl\Domain\AccessControl\Role\Exception\CustomRoleException;
use Fight\AccessControl\Domain\AccessControl\Role\Exception\RoleConflictException;
use Fight\AccessControl\Domain\AccessControl\Role\RoleRepository;
use Fight\AccessControl\Domain\AccessControl\User\UserRepository;
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
 * Class RemoveCustomRoleHandler
 *
 * Atomically removes an unreferenced custom role.
 */
final readonly class RemoveCustomRoleHandler implements CommandHandler
{
    /**
     * Constructs RemoveCustomRoleHandler
     *
     * Creates the custom-role removal handler.
     */
    public function __construct(
        private RoleRepository $roleRepository,
        private UserRepository $userRepository,
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
        return RemoveCustomRole::class;
    }

    /**
     * @inheritDoc
     */
    public function handle(CommandMessage $commandMessage): void
    {
        /** @var RemoveCustomRole $command */
        $command = $commandMessage->payload();
        $messageId = $commandMessage->id();

        try {
            /** @var CustomRoleRemoved $event */
            $event = $this->unitOfWork->commitTransactional(function () use ($command, $messageId): CustomRoleRemoved {
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

                if ($this->userRepository->hasRoleAssignment($command->getRoleId())) {
                    throw new RuleViolation('The custom role remains assigned to a user.');
                }

                $removedAt = $this->clock->now();
                if (!$this->roleRepository->remove($role)) {
                    throw new StateConflict('The custom role changed or acquired a reference concurrently.');
                }

                if ($this->auditEvidenceRepository !== null) {
                    $this->auditEvidenceRepository->add(AuditEvidence::record(
                        $command->getActorId(),
                        new AuditSubject($role->getId()),
                        AuditAction::ROLE_REMOVED,
                        $removedAt,
                        new AuditRoleRevisions($role->getRevision(), null),
                        new AuditCorrelation($messageId, null)
                    ));
                }

                return new CustomRoleRemoved($command->getActorId(), $command->getRoleId(), $removedAt);
            });

            $this->eventDispatcher->trigger($event);
        } catch (Throwable $throwable) {
            try {
                $this->eventDispatcher->trigger(new CommandFailedEvent($command, 'Custom role removal failed.'));
            } catch (Throwable) {
                // Notification failure must not replace the original operation failure.
            }

            throw $throwable;
        }
    }
}
