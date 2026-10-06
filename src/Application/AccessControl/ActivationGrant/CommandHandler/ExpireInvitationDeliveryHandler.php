<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\ActivationGrant\CommandHandler;

use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrant;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrantRepository;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Command\ExpireInvitationDelivery;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Event\InvitationDeliveryExpired;
use Fight\Common\Application\Messaging\Command\CommandHandler;
use Fight\Common\Application\Messaging\Event\EventDispatcher;
use Fight\Common\Application\Repository\TransactionalUnitOfWork;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use LogicException;
use Throwable;

/**
 * Class ExpireInvitationDeliveryHandler
 *
 * Processes terminal invitation delivery expiry without owning its scheduler.
 */
final readonly class ExpireInvitationDeliveryHandler implements CommandHandler
{
    /**
     * Constructs ExpireInvitationDeliveryHandler
     *
     * Creates the invitation delivery-expiry handler.
     */
    public function __construct(
        private ActivationGrantRepository $activationGrantRepository,
        private TransactionalUnitOfWork $unitOfWork,
        private EventDispatcher $eventDispatcher
    ) {
    }

    /** @inheritDoc */
    public static function commandRegistration(): string
    {
        return ExpireInvitationDelivery::class;
    }

    /** @inheritDoc */
    public function handle(CommandMessage $commandMessage): void
    {
        /** @var ExpireInvitationDelivery $command */
        $command = $commandMessage->payload();

        try {
            $successEvent = $this->unitOfWork->commitTransactional(function () use (
                $command
            ): ?InvitationDeliveryExpired {
                $activationGrant = $this->activationGrantRepository->getByDeliveryId(
                    $command->getActivationDeliveryId()
                );
                if (
                    !$activationGrant instanceof ActivationGrant
                    || !$activationGrant->getUserId()->equals($command->getUserId())
                ) {
                    return null;
                }

                $latest = $this->activationGrantRepository->getLatestByUserId($command->getUserId());
                if (
                    !$latest instanceof ActivationGrant
                    || !$latest->getId()->equals($activationGrant->getId())
                    || !$activationGrant->isIssued()
                ) {
                    return null;
                }

                if (!$activationGrant->ownsDelivery($command->getActivationDeliveryId(), $command->getUserId())) {
                    throw new LogicException('The expired invitation delivery ownership is inconsistent.');
                }

                $expiredGrant = $activationGrant->expireDeliveryAt($command->getOccurredAt());
                if ($expiredGrant === $activationGrant) {
                    return null;
                }

                if (!$this->activationGrantRepository->replace($activationGrant, $expiredGrant)) {
                    return null;
                }

                return new InvitationDeliveryExpired(
                    $command->getActorId(),
                    $command->getUserId(),
                    $command->getActivationDeliveryId(),
                    $command->getOccurredAt()
                );
            });

            if ($successEvent instanceof InvitationDeliveryExpired) {
                $this->eventDispatcher->trigger($successEvent);
            }
        } catch (Throwable $throwable) {
            try {
                $this->eventDispatcher->trigger(new CommandFailedEvent($command, 'Credential expiry failed.'));
            } catch (Throwable) {
                // Failure notification must not conceal the original persistence or publication failure.
            }

            throw $throwable;
        }
    }
}
