<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\ActivationGrant\CommandHandler;

use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationDeliveryId;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrant;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrantRepository;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Command\RetryInvitationDelivery;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Event\InvitationDeliveryRetryRequested;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Exception\ActivationDeliveryNotRetryableException;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidenceRepository;
use Fight\Common\Application\Messaging\Command\CommandHandler;
use Fight\Common\Application\Messaging\Event\EventDispatcher;
use Fight\Common\Application\Repository\UnitOfWork;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Throwable;

/**
 * Publishes a durable request to retry existing activation delivery work.
 */
final readonly class RetryInvitationDeliveryHandler implements CommandHandler
{
    /**
     * Creates the activation-delivery retry handler.
     */
    public function __construct(
        private ActivationGrantRepository $activationGrantRepository,
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
        return RetryInvitationDelivery::class;
    }

    /**
     * @inheritDoc
     */
    public function handle(CommandMessage $commandMessage): void
    {
        /** @var RetryInvitationDelivery $command */
        $command = $commandMessage->payload();

        try {
            $activationDeliveryId = $this->unitOfWork->commitTransactional(function () use (
                $command
            ): ActivationDeliveryId {
                $activationGrant = $this->activationGrantRepository->getLatestByUserId($command->getUserId());
                if (!$activationGrant instanceof ActivationGrant) {
                    throw new ActivationDeliveryNotRetryableException(
                        'The activation delivery work is no longer retryable.'
                    );
                }

                $retryRequested = $activationGrant->requestDeliveryRetry();
                if (!$this->activationGrantRepository->replace($activationGrant, $retryRequested)) {
                    throw new ActivationDeliveryNotRetryableException(
                        'The activation delivery generation changed concurrently.'
                    );
                }

                $this->auditEvidenceRepository->add(AuditEvidence::record(
                    $command->getActorId(),
                    'user.invitation_delivery.retry_requested',
                    $command->getUserId()
                ));

                return $retryRequested->getDelivery()->getId();
            });

            $this->eventDispatcher->trigger(new InvitationDeliveryRetryRequested(
                $command->getActorId(),
                $command->getUserId(),
                $activationDeliveryId
            ));
        } catch (Throwable $throwable) {
            $this->eventDispatcher->trigger(new CommandFailedEvent($command, $throwable->getMessage()));
            throw $throwable;
        }
    }
}
