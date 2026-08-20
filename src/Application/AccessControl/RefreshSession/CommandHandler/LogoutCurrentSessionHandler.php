<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\RefreshSession\CommandHandler;

use Fight\AccessControl\Application\AccessControl\RefreshSession\Service\CurrentRefreshSessionProvider;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\Command\LogoutCurrentSession;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\Event\CurrentSessionLoggedOut;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\Exception\RefreshSessionNotFoundException;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSession;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionId;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionRepository;
use Fight\Common\Application\Messaging\Command\CommandHandler;
use Fight\Common\Application\Messaging\Event\EventDispatcher;
use Fight\Common\Application\Repository\UnitOfWork;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Throwable;

/**
 * Revokes precisely the consumer-authenticated current refresh session.
 */
final readonly class LogoutCurrentSessionHandler implements CommandHandler
{
    /**
     * Creates the current-session logout handler.
     */
    public function __construct(
        private CurrentRefreshSessionProvider $currentRefreshSessionProvider,
        private RefreshSessionRepository $refreshSessionRepository,
        private UnitOfWork $unitOfWork,
        private EventDispatcher $eventDispatcher
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function commandRegistration(): string
    {
        return LogoutCurrentSession::class;
    }

    /**
     * @inheritDoc
     */
    public function handle(CommandMessage $commandMessage): void
    {
        /** @var LogoutCurrentSession $command */
        $command = $commandMessage->payload();

        try {
            $refreshSessionId = $this->unitOfWork->commitTransactional(function (): RefreshSessionId {
                $refreshSessionId = $this->currentRefreshSessionProvider->getCurrentRefreshSessionId();
                $refreshSession = $this->refreshSessionRepository->getById($refreshSessionId);
                if (!$refreshSession instanceof RefreshSession) {
                    throw new RefreshSessionNotFoundException('The refresh session does not exist.');
                }

                $refreshSession->revoke();

                return $refreshSessionId;
            });

            $this->eventDispatcher->trigger(new CurrentSessionLoggedOut($refreshSessionId));
        } catch (Throwable $throwable) {
            $this->eventDispatcher->trigger(new CommandFailedEvent($command, $throwable->getMessage()));
            throw $throwable;
        }
    }
}
