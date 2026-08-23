<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\User\CommandHandler;

use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidenceRepository;
use Fight\AccessControl\Domain\AccessControl\User\Command\EnableUser;
use Fight\AccessControl\Domain\AccessControl\User\Event\UserEnabled;
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
 * Atomically reactivates a disabled identity without restoring prior sessions.
 */
final readonly class EnableUserHandler implements CommandHandler
{
    /**
     * Creates the user-enable handler.
     */
    public function __construct(
        private UserRepository $userRepository,
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
            $event = $this->unitOfWork->commitTransactional(function () use ($command): UserEnabled {
                $user = $this->userRepository->getById($command->getUserId());
                if (!$user instanceof User) {
                    throw new UserLifecycleException('The user cannot be enabled.');
                }

                $replacementUser = clone $user;
                $replacementUser->enable();
                if (!$this->userRepository->replaceLifecycleState($user, $replacementUser)) {
                    throw new LogicException('The user lifecycle state changed concurrently.');
                }

                $this->auditEvidenceRepository->add(AuditEvidence::record(
                    $command->getActorId()->toString(),
                    'user.enabled',
                    $command->getUserId()
                ));

                return new UserEnabled($command->getActorId(), $command->getUserId());
            });

            $this->eventDispatcher->trigger($event);
        } catch (Throwable $throwable) {
            $this->eventDispatcher->trigger(new CommandFailedEvent($command, $throwable->getMessage()));
            throw $throwable;
        }
    }
}
