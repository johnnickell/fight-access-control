<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\User\CommandHandler;

use Fight\AccessControl\Application\AccessControl\User\Service\ActivationDeliveryInvoker;
use Fight\AccessControl\Domain\AccessControl\User\ActivationDeliveryWork;
use Fight\AccessControl\Domain\AccessControl\User\ActivationDeliveryWorkRepository;
use Fight\AccessControl\Domain\AccessControl\User\Command\DeliverUserInvitation;
use Fight\AccessControl\Domain\AccessControl\User\Exception\ActivationDeliveryNotRetryableException;
use Fight\Common\Application\Messaging\Command\CommandHandler;
use Fight\Common\Application\Messaging\Event\EventDispatcher;
use Fight\Common\Application\Repository\UnitOfWork;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Throwable;

/**
 * Invokes durable activation delivery through a consumer-owned transport-neutral port.
 */
final readonly class DeliverUserInvitationHandler implements CommandHandler
{
    /**
     * Creates the invitation-delivery handler.
     */
    public function __construct(
        private ActivationDeliveryWorkRepository $activationDeliveryWorkRepository,
        private UnitOfWork $unitOfWork,
        private ActivationDeliveryInvoker $activationDeliveryInvoker,
        private EventDispatcher $eventDispatcher
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function commandRegistration(): string
    {
        return DeliverUserInvitation::class;
    }

    /**
     * @inheritDoc
     */
    public function handle(CommandMessage $commandMessage): void
    {
        /** @var DeliverUserInvitation $command */
        $command = $commandMessage->payload();

        try {
            $work = $this->activationDeliveryWorkRepository->getByUserId($command->getUserId());

            if (!$work instanceof ActivationDeliveryWork || $work->isRetryable() === false) {
                throw new ActivationDeliveryNotRetryableException(
                    'The activation delivery work is no longer retryable.'
                );
            }

            $deliveryFailure = null;
            $this->unitOfWork->commitTransactional(function () use ($work, &$deliveryFailure): void {
                try {
                    $this->activationDeliveryInvoker->invoke($work);
                } catch (Throwable $throwable) {
                    $work->fail();
                    $deliveryFailure = $throwable;

                    return;
                }

                $work->confirm();
            });

            if ($deliveryFailure instanceof Throwable) {
                throw $deliveryFailure;
            }
        } catch (Throwable $throwable) {
            $this->eventDispatcher->trigger(new CommandFailedEvent($command, $throwable->getMessage()));
            throw $throwable;
        }
    }
}
