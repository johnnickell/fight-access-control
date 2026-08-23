<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\PasswordResetGrant\CommandHandler;

use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\Command\ConfirmPasswordResetDelivery;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\Event\PasswordResetDeliveryConfirmed;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrant;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrantRepository;
use Fight\Common\Application\Messaging\Command\CommandHandler;
use Fight\Common\Application\Messaging\Event\EventDispatcher;
use Fight\Common\Application\Repository\UnitOfWork;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Throwable;

/**
 * Confirms successful consumer-owned password-reset delivery and destroys its ciphertext.
 */
final readonly class ConfirmPasswordResetDeliveryHandler implements CommandHandler
{
    /**
     * Creates the password-reset delivery-confirmation handler.
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
        return ConfirmPasswordResetDelivery::class;
    }

    /** @inheritDoc */
    public function handle(CommandMessage $commandMessage): void
    {
        /** @var ConfirmPasswordResetDelivery $command */
        $command = $commandMessage->payload();

        try {
            $successEvent = $this->unitOfWork->commitTransactional(function () use (
                $command
            ): ?PasswordResetDeliveryConfirmed {
                $passwordResetGrant = $this->passwordResetGrantRepository->getByDeliveryId(
                    $command->getPasswordResetDeliveryId()
                );
                if (
                    !$passwordResetGrant instanceof PasswordResetGrant
                    || !$passwordResetGrant->getUserId()->equals($command->getUserId())
                ) {
                    return null;
                }

                $confirmedGrant = $passwordResetGrant->confirmDelivery();
                if ($confirmedGrant === $passwordResetGrant) {
                    return null;
                }

                if (!$this->passwordResetGrantRepository->replace($passwordResetGrant, $confirmedGrant)) {
                    return null;
                }

                return new PasswordResetDeliveryConfirmed(
                    $command->getActorId(),
                    $command->getUserId(),
                    $command->getPasswordResetDeliveryId(),
                    $command->getOccurredAt()
                );
            });

            if ($successEvent instanceof PasswordResetDeliveryConfirmed) {
                $this->eventDispatcher->trigger($successEvent);
            }
        } catch (Throwable $throwable) {
            $this->eventDispatcher->trigger(new CommandFailedEvent($command, $throwable->getMessage()));
            throw $throwable;
        }
    }
}
