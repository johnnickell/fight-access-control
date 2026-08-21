<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\User\CommandHandler;

use Fight\AccessControl\Domain\AccessControl\User\Command\ConfirmPasswordResetDelivery;
use Fight\AccessControl\Domain\AccessControl\User\Event\PasswordResetDeliveryConfirmed;
use Fight\AccessControl\Domain\AccessControl\User\PasswordResetDelivery;
use Fight\AccessControl\Domain\AccessControl\User\PasswordResetDeliveryRepository;
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
        private PasswordResetDeliveryRepository $passwordResetDeliveryRepository,
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
                $delivery = $this->passwordResetDeliveryRepository->getById(
                    $command->getPasswordResetDeliveryId()
                );
                if (
                    !$delivery instanceof PasswordResetDelivery
                    || !$delivery->getUserId()->equals($command->getUserId())
                ) {
                    return null;
                }

                $confirmedDelivery = $delivery->confirm();
                if ($confirmedDelivery === $delivery) {
                    return null;
                }

                if (!$this->passwordResetDeliveryRepository->replaceInvalidated($delivery, $confirmedDelivery)) {
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
