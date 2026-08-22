<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\PasswordResetGrant\CommandHandler;

use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\Command\ExpirePasswordResetDelivery;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\Event\PasswordResetDeliveryExpired;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrant;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrantRepository;
use Fight\Common\Application\Messaging\Command\CommandHandler;
use Fight\Common\Application\Messaging\Event\EventDispatcher;
use Fight\Common\Application\Repository\UnitOfWork;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Throwable;

/**
 * Processes terminal password-reset delivery expiry without owning its scheduler.
 */
final readonly class ExpirePasswordResetDeliveryHandler implements CommandHandler
{
    /**
     * Creates the password-reset delivery-expiry handler.
     */
    public function __construct(
        private PasswordResetGrantRepository $passwordResetGrantRepository,
        private UnitOfWork $unitOfWork,
        private EventDispatcher $eventDispatcher
    ) {
    }

    /** @inheritDoc */
    public static function commandRegistration(): string
    {
        return ExpirePasswordResetDelivery::class;
    }

    /** @inheritDoc */
    public function handle(CommandMessage $commandMessage): void
    {
        /** @var ExpirePasswordResetDelivery $command */
        $command = $commandMessage->payload();

        try {
            $successEvent = $this->unitOfWork->commitTransactional(function () use (
                $command
            ): ?PasswordResetDeliveryExpired {
                $passwordResetGrant = $this->passwordResetGrantRepository->getByDeliveryId(
                    $command->getPasswordResetDeliveryId()
                );
                if (
                    !$passwordResetGrant instanceof PasswordResetGrant
                    || !$passwordResetGrant->getUserId()->equals($command->getUserId())
                ) {
                    return null;
                }

                $expiredGrant = $passwordResetGrant->expireDeliveryAt($command->getOccurredAt());
                if ($expiredGrant === $passwordResetGrant) {
                    return null;
                }

                if (!$this->passwordResetGrantRepository->replace($passwordResetGrant, $expiredGrant)) {
                    return null;
                }

                return new PasswordResetDeliveryExpired(
                    $command->getActorId(),
                    $command->getUserId(),
                    $command->getPasswordResetDeliveryId(),
                    $command->getOccurredAt()
                );
            });

            if ($successEvent instanceof PasswordResetDeliveryExpired) {
                $this->eventDispatcher->trigger($successEvent);
            }
        } catch (Throwable $throwable) {
            $this->eventDispatcher->trigger(new CommandFailedEvent($command, $throwable->getMessage()));
            throw $throwable;
        }
    }
}
