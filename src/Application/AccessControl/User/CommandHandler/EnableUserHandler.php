<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\User\CommandHandler;

use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditAction;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditCorrelation;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidenceRepository;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditSubject;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditUserOutcome;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditUserRevisions;
use Fight\AccessControl\Domain\AccessControl\User\Command\EnableUser;
use Fight\AccessControl\Domain\AccessControl\User\Event\UserEnabled;
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
 * Class EnableUserHandler
 *
 * Atomically reactivates a disabled identity without restoring prior sessions.
 */
final readonly class EnableUserHandler implements CommandHandler
{
    /**
     * Constructs EnableUserHandler
     *
     * Creates the user-enable handler.
     */
    public function __construct(
        private UserRepository $userRepository,
        private TransactionalUnitOfWork $unitOfWork,
        private EventDispatcher $eventDispatcher,
        private Clock $clock,
        private ?AuditEvidenceRepository $auditEvidenceRepository = null
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function commandRegistration(): string
    {
        return EnableUser::class;
    }

    /**
     * @inheritDoc
     */
    public function handle(CommandMessage $commandMessage): void
    {
        /** @var EnableUser $command */
        $command = $commandMessage->payload();

        try {
            /** @var UserEnabled $event */
            $event = $this->unitOfWork->commitTransactional(function () use ($command, $commandMessage): UserEnabled {
                $user = $this->userRepository->getById($command->getUserId());
                if ($user === null) {
                    throw new ResourceNotFound('The user cannot be enabled.');
                }

                if (!$user->getId()->equals($command->getUserId())) {
                    throw new LogicException('The User repository returned an unrelated identity.');
                }

                $replacementUser = clone $user;
                $now = $this->clock->now();
                try {
                    $replacementUser->enable($now);
                } catch (UserLifecycleException $userLifecycleException) {
                    throw new StateConflict('The user cannot be enabled.', previous: $userLifecycleException);
                }

                if (!$this->userRepository->replaceLifecycleState($user, $replacementUser)) {
                    throw new StateConflict('The user lifecycle state changed concurrently.');
                }

                $this->auditEvidenceRepository?->add(AuditEvidence::record(
                    $command->getActorId(),
                    new AuditSubject($command->getUserId()),
                    AuditAction::USER_ENABLED,
                    $now,
                    new AuditUserOutcome(
                        AuditUserRevisions::fromUser($user),
                        AuditUserRevisions::fromUser($replacementUser)
                    ),
                    new AuditCorrelation($commandMessage->id(), null)
                ));

                return new UserEnabled($command->getActorId(), $command->getUserId());
            });

            $this->eventDispatcher->trigger($event);
        } catch (Throwable $throwable) {
            try {
                $this->eventDispatcher->trigger(new CommandFailedEvent($command, 'User enablement failed.'));
            } catch (Throwable) {
                // Notification failure must not replace the original operation failure.
            }

            throw $throwable;
        }
    }
}
