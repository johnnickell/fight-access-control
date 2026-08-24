<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\EmailChangeGrant\CommandHandler;

use Fight\AccessControl\Application\AccessControl\EmailChangeGrant\Service\EmailChangeDeliveryInvoker;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidenceRepository;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Command\DeliverEmailChange;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeGrant;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeGrantRepository;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Event\EmailChangeDelivered;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Exception\EmailChangeDeliveryNotRetryableException;
use Fight\Common\Application\Messaging\Command\CommandHandler;
use Fight\Common\Application\Messaging\Event\EventDispatcher;
use Fight\Common\Application\Repository\UnitOfWork;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Throwable;

/**
 * Invokes durable email-change delivery through a consumer-owned transport-neutral port.
 */
final readonly class DeliverEmailChangeHandler implements CommandHandler
{
    /**
     * Creates the email-change delivery handler.
     */
    public function __construct(
        private EmailChangeGrantRepository $emailChangeGrantRepository,
        private AuditEvidenceRepository $auditEvidenceRepository,
        private UnitOfWork $unitOfWork,
        private EmailChangeDeliveryInvoker $emailChangeDeliveryInvoker,
        private EventDispatcher $eventDispatcher
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function commandRegistration(): string
    {
        return DeliverEmailChange::class;
    }

    /**
     * @inheritDoc
     */
    public function handle(CommandMessage $commandMessage): void
    {
        /** @var DeliverEmailChange $command */
        $command = $commandMessage->payload();

        try {
            $deliveryFailure = null;
            $successEvent = $this->unitOfWork->commitTransactional(function () use (
                $command,
                &$deliveryFailure
            ): ?EmailChangeDelivered {
                $grant = $this->emailChangeGrantRepository->getByDeliveryId(
                    $command->getEmailChangeDeliveryId()
                );
                $latest = $this->emailChangeGrantRepository->getLatestByUserId($command->getUserId());
                if (
                    !$grant instanceof EmailChangeGrant
                    || !$latest instanceof EmailChangeGrant
                    || !$latest->getId()->equals($grant->getId())
                    || $latest->getRevision() !== $grant->getRevision()
                    || !$grant->getUserId()->equals($command->getUserId())
                ) {
                    throw new EmailChangeDeliveryNotRetryableException(
                        'The email-change delivery work is no longer retryable.'
                    );
                }

                $claimed = $grant->claimDelivery();
                if (!$this->emailChangeGrantRepository->replace($grant, $claimed)) {
                    throw new EmailChangeDeliveryNotRetryableException(
                        'The email-change delivery generation changed concurrently.'
                    );
                }

                try {
                    $this->emailChangeDeliveryInvoker->invoke($claimed->getDelivery());
                } catch (Throwable $throwable) {
                    $this->auditEvidenceRepository->add(AuditEvidence::record(
                        $command->getActorId()->toString(),
                        'user.email_change_delivery.failed',
                        $command->getUserId()
                    ));
                    if (!$this->emailChangeGrantRepository->replace($claimed, $claimed->failDelivery())) {
                        throw new EmailChangeDeliveryNotRetryableException(
                            'The email-change delivery generation changed concurrently.'
                        );
                    }

                    $deliveryFailure = $throwable;

                    return null;
                }

                $this->auditEvidenceRepository->add(AuditEvidence::record(
                    $command->getActorId()->toString(),
                    'user.email_change_delivery.confirmed',
                    $command->getUserId()
                ));
                if (!$this->emailChangeGrantRepository->replace($claimed, $claimed->confirmDelivery())) {
                    throw new EmailChangeDeliveryNotRetryableException(
                        'The email-change delivery generation changed concurrently.'
                    );
                }

                return new EmailChangeDelivered(
                    $command->getActorId(),
                    $command->getUserId(),
                    $command->getEmailChangeDeliveryId()
                );
            });

            if ($deliveryFailure instanceof Throwable) {
                throw $deliveryFailure;
            }

            if ($successEvent instanceof EmailChangeDelivered) {
                $this->eventDispatcher->trigger($successEvent);
            }
        } catch (Throwable $throwable) {
            $this->eventDispatcher->trigger(new CommandFailedEvent($command, $throwable->getMessage()));
            throw $throwable;
        }
    }
}
