<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\User\CommandHandler;

use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidenceRepository;
use Fight\AccessControl\Domain\AccessControl\User\Command\RetryInvitationDelivery;
use Fight\AccessControl\Domain\AccessControl\User\Event\InvitationDeliveryRetryRequested;
use Fight\AccessControl\Domain\AccessControl\User\Exception\InvitationDeliveryNotRetryableException;
use Fight\AccessControl\Domain\AccessControl\User\InvitationDelivery;
use Fight\AccessControl\Domain\AccessControl\User\InvitationDeliveryRepository;
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
        private InvitationDeliveryRepository $invitationDeliveryRepository,
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
            $work = $this->invitationDeliveryRepository->getByUserId($command->getUserId());

            if (!$work instanceof InvitationDelivery || $work->isRetryable() === false) {
                throw new InvitationDeliveryNotRetryableException(
                    'The activation delivery work is no longer retryable.'
                );
            }

            $this->unitOfWork->commitTransactional(function () use ($command): void {
                $this->auditEvidenceRepository->add(AuditEvidence::record(
                    $command->getActorId(),
                    'user.invitation_delivery.retry_requested',
                    $command->getUserId()
                ));
            });

            $this->eventDispatcher->trigger(new InvitationDeliveryRetryRequested(
                $command->getActorId(),
                $command->getUserId()
            ));
        } catch (Throwable $throwable) {
            $this->eventDispatcher->trigger(new CommandFailedEvent($command, $throwable->getMessage()));
            throw $throwable;
        }
    }
}
