<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\User\CommandHandler;

use DateInterval;
use Fight\AccessControl\Application\AccessControl\User\Service\ActivationCredentialGenerator;
use Fight\AccessControl\Application\AccessControl\User\Service\ActivationDeliveryCipher;
use Fight\AccessControl\Application\AccessControl\User\Service\InvitationClock;
use Fight\AccessControl\Domain\AccessControl\User\ActivationDeliveryWork;
use Fight\AccessControl\Domain\AccessControl\User\ActivationDeliveryWorkRepository;
use Fight\AccessControl\Domain\AccessControl\User\ActivationGrant;
use Fight\AccessControl\Domain\AccessControl\User\ActivationGrantRepository;
use Fight\AccessControl\Domain\AccessControl\User\Command\ResendActivationDelivery;
use Fight\AccessControl\Domain\AccessControl\User\Event\ActivationDeliveryResent;
use Fight\AccessControl\Domain\AccessControl\User\Exception\ActivationDeliveryNotResendableException;
use Fight\Common\Application\Messaging\Command\CommandHandler;
use Fight\Common\Application\Messaging\Event\EventDispatcher;
use Fight\Common\Application\Repository\UnitOfWork;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Throwable;

/**
 * Atomically replaces an activation grant and stages its recoverable replacement delivery work.
 */
final readonly class ResendActivationDeliveryHandler implements CommandHandler
{
    /**
     * Creates the activation-delivery resend handler.
     */
    public function __construct(
        private ActivationGrantRepository $activationGrantRepository,
        private ActivationDeliveryWorkRepository $activationDeliveryWorkRepository,
        private UnitOfWork $unitOfWork,
        private ActivationCredentialGenerator $credentials,
        private ActivationDeliveryCipher $cipher,
        private InvitationClock $clock,
        private EventDispatcher $eventDispatcher
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function commandRegistration(): string
    {
        return ResendActivationDelivery::class;
    }

    /**
     * @inheritDoc
     */
    public function handle(CommandMessage $commandMessage): void
    {
        /** @var ResendActivationDelivery $command */
        $command = $commandMessage->payload();

        try {
            $issuedAt = $this->clock->now();
            $this->unitOfWork->commitTransactional(function () use ($command, $issuedAt): void {
                $predecessor = $this->activationGrantRepository->getByUserId($command->getUserId());
                $previousDelivery = $this->activationDeliveryWorkRepository->getByUserId($command->getUserId());

                if (
                    !$predecessor instanceof ActivationGrant
                    || $predecessor->isIssued() === false
                    || !$previousDelivery instanceof ActivationDeliveryWork
                ) {
                    throw new ActivationDeliveryNotResendableException(
                        'The activation delivery cannot be resent.'
                    );
                }

                $credential = $this->credentials->generate();
                $revokedPredecessor = $predecessor->revoke($issuedAt);
                $replacement = ActivationGrant::issue(
                    $command->getUserId(),
                    $credential,
                    $issuedAt,
                    $issuedAt->add(new DateInterval('P7D'))
                );
                $delivery = ActivationDeliveryWork::create(
                    $command->getUserId(),
                    $previousDelivery->email(),
                    $this->cipher->encrypt($credential),
                    $replacement->getExpiresAt()
                );
                $this->activationGrantRepository->replace(
                    $predecessor,
                    $revokedPredecessor,
                    $replacement
                );
                $this->activationDeliveryWorkRepository->add($delivery);
            });

            $this->eventDispatcher->trigger(new ActivationDeliveryResent(
                $command->getActorId(),
                $command->getUserId()
            ));
        } catch (Throwable $throwable) {
            $this->eventDispatcher->trigger(new CommandFailedEvent($command, $throwable->getMessage()));
            throw $throwable;
        }
    }
}
