<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\User\CommandHandler;

use Fight\AccessControl\Application\AccessControl\RefreshSession\Service\SessionRevocationService;
use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidenceRepository;
use Fight\AccessControl\Domain\AccessControl\User\Command\DeleteUser;
use Fight\AccessControl\Domain\AccessControl\User\Event\UserDeleted;
use Fight\AccessControl\Domain\AccessControl\User\Exception\UserLifecycleException;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserRepository;
use Fight\Common\Application\Messaging\Command\CommandHandler;
use Fight\Common\Application\Messaging\Event\EventDispatcher;
use Fight\Common\Application\Repository\UnitOfWork;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use LogicException;
use Throwable;

/**
 * Atomically soft-deletes an identity and revokes its active sessions.
 */
final readonly class DeleteUserHandler implements CommandHandler
{
    /**
     * Creates the user-delete handler.
     */
    public function __construct(
        private UserRepository $userRepository,
        private SessionRevocationService $sessionRevocationService,
        private Clock $clock,
        private AuditEvidenceRepository $auditEvidenceRepository,
        private UnitOfWork $unitOfWork,
        private EventDispatcher $eventDispatcher
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
            $event = $this->unitOfWork->commitTransactional(function () use ($command): UserDeleted {
                $user = $this->userRepository->getById($command->getUserId());
                if (!$user instanceof User) {
                    throw new UserLifecycleException('The user cannot be deleted.');
                }

                $now = $this->clock->now();
                $replacementUser = clone $user;
                $replacementUser->delete($now);
                if (!$this->userRepository->replaceLifecycleState($user, $replacementUser)) {
                    throw new LogicException('The user lifecycle state changed concurrently.');
                }

                $this->sessionRevocationService->revokeAllActiveFor(
                    $command->getUserId(),
                    $now
                );

                $this->auditEvidenceRepository->add(AuditEvidence::record(
                    $command->getActorId()->toString(),
                    'user.deleted',
                    $command->getUserId()
                ));

                return new UserDeleted($command->getActorId(), $command->getUserId());
            });

            $this->eventDispatcher->trigger($event);
        } catch (Throwable $throwable) {
            $this->eventDispatcher->trigger(new CommandFailedEvent($command, $throwable->getMessage()));
            throw $throwable;
        }
    }
}
