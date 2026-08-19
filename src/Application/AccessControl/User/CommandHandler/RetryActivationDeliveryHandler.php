<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\User\CommandHandler;

use Fight\AccessControl\Application\AccessControl\User\Service\ActivationDeliveryInvoker;
use Fight\AccessControl\Domain\AccessControl\User\ActivationDeliveryWork;
use Fight\AccessControl\Domain\AccessControl\User\ActivationDeliveryWorkRepository;
use Fight\AccessControl\Domain\AccessControl\User\Command\RetryActivationDelivery;
use Fight\AccessControl\Domain\AccessControl\User\Event\ActivationDeliveryRetried;
use Fight\AccessControl\Domain\AccessControl\User\Exception\ActivationDeliveryNotRetryableException;
use Fight\Common\Application\Messaging\Command\CommandHandler;
use Fight\Common\Application\Messaging\Event\EventDispatcher;
use Fight\Common\Application\Repository\UnitOfWork;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Throwable;

/**
 * Invokes existing activation-delivery work without choosing a consumer transport or execution mode.
 */
final readonly class RetryActivationDeliveryHandler implements CommandHandler
{
    /**
     * Creates the activation-delivery retry handler.
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
        return RetryActivationDelivery::class;
    }

    /**
     * @inheritDoc
     */
    public function handle(CommandMessage $commandMessage): void
    {
        /** @var RetryActivationDelivery $command */
        $command = $commandMessage->payload();
        $work = $this->activationDeliveryWorkRepository->getByUserId($command->getUserId());

        try {
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

            $this->eventDispatcher->trigger(new ActivationDeliveryRetried(
                $command->getActorId(),
                $command->getUserId()
            ));
        } catch (Throwable $throwable) {
            $this->eventDispatcher->trigger(new CommandFailedEvent($command, $throwable->getMessage()));
            throw $throwable;
        }
    }
}
