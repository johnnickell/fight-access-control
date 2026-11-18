<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\User\CommandHandler;

use Fight\AccessControl\Application\AccessControl\PasswordResetGrant\Service\PasswordResetRevocationService;
use Fight\AccessControl\Application\AccessControl\RefreshSession\Service\SessionRevocationService;
use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditAction;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditCorrelation;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidenceRepository;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditSubject;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditUserOutcome;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditUserRevisions;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrantRepository;
use Fight\AccessControl\Domain\AccessControl\User\Command\DeleteUser;
use Fight\AccessControl\Domain\AccessControl\User\Event\UserDeleted;
use Fight\AccessControl\Domain\AccessControl\User\Exception\UserLifecycleException;
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
 * Class DeleteUserHandler
 *
 * Atomically soft-deletes an identity and revokes its active sessions.
 */
final readonly class DeleteUserHandler implements CommandHandler
{
    /**
     * Constructs DeleteUserHandler
     *
     * Creates the user-delete handler.
     */
    public function __construct(
        private UserRepository $userRepository,
        private SessionRevocationService $sessionRevocationService,
        private Clock $clock,
        private TransactionalUnitOfWork $unitOfWork,
        private EventDispatcher $eventDispatcher,
        private PasswordResetGrantRepository $passwordResetGrantRepository,
        private ?AuditEvidenceRepository $auditEvidenceRepository = null
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function commandRegistration(): string
    {
        return DeleteUser::class;
    }

    /**
     * @inheritDoc
     */
    public function handle(CommandMessage $commandMessage): void
    {
        /** @var DeleteUser $command */
        $command = $commandMessage->payload();

        try {
            /** @var UserDeleted $event */
            $event = $this->unitOfWork->commitTransactional(function () use ($command, $commandMessage): UserDeleted {
                $user = $this->userRepository->getById($command->getUserId());
                if ($user === null) {
                    throw new ResourceNotFound('The user cannot be deleted.');
                }

                if (!$user->getId()->equals($command->getUserId())) {
                    throw new LogicException('The User repository returned an unrelated identity.');
                }

                $now = $this->clock->now();
                $replacementUser = clone $user;
                try {
                    $replacementUser->delete($now);
                } catch (UserLifecycleException $userLifecycleException) {
                    throw new StateConflict('The user cannot be deleted.', previous: $userLifecycleException);
                }

                if (!$this->userRepository->replaceLifecycleState($user, $replacementUser)) {
                    throw new StateConflict('The user lifecycle state changed concurrently.');
                }

                new PasswordResetRevocationService($this->passwordResetGrantRepository)->revokeFor(
                    $command->getUserId(),
                    $now
                );
                $this->sessionRevocationService->revokeAllActiveFor(
                    $command->getUserId(),
                    $now
                );

                $this->auditEvidenceRepository?->add(AuditEvidence::record(
                    $command->getActorId(),
                    new AuditSubject($command->getUserId()),
                    AuditAction::USER_DELETED,
                    $now,
                    new AuditUserOutcome(
                        AuditUserRevisions::fromUser($user),
                        AuditUserRevisions::fromUser($replacementUser)
                    ),
                    new AuditCorrelation($commandMessage->id(), null)
                ));

                return new UserDeleted($command->getActorId(), $command->getUserId());
            });

            $this->eventDispatcher->trigger($event);
        } catch (Throwable $throwable) {
            try {
                $this->eventDispatcher->trigger(new CommandFailedEvent($command, 'User deletion failed.'));
            } catch (Throwable) {
                // Notification failure must not replace the original operation failure.
            }

            throw $throwable;
        }
    }
}
