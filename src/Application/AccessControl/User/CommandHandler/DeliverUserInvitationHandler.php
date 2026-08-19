<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\User\CommandHandler;

use Fight\AccessControl\Application\AccessControl\User\Service\InvitationDeliveryInvoker;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidenceRepository;
use Fight\AccessControl\Domain\AccessControl\User\Command\DeliverUserInvitation;
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
 * Invokes durable activation delivery through a consumer-owned transport-neutral port.
 */
final readonly class DeliverUserInvitationHandler implements CommandHandler
{
    /**
     * Creates the invitation-delivery handler.
     */
    public function __construct(
        private InvitationDeliveryRepository $invitationDeliveryRepository,
        private AuditEvidenceRepository $auditEvidenceRepository,
        private UnitOfWork $unitOfWork,
        private InvitationDeliveryInvoker $invitationDeliveryInvoker,
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
            $work = $this->invitationDeliveryRepository->getByUserId($command->getUserId());

            if (!$work instanceof InvitationDelivery || $work->isRetryable() === false) {
                throw new InvitationDeliveryNotRetryableException(
                    'The activation delivery work is no longer retryable.'
                );
            }

            $deliveryFailure = null;
            $this->unitOfWork->commitTransactional(function () use ($command, $work, &$deliveryFailure): void {
                try {
                    $this->invitationDeliveryInvoker->invoke($work);
                } catch (Throwable $throwable) {
                    $this->auditEvidenceRepository->add(AuditEvidence::record(
                        $command->getActorId(),
                        'user.invitation_delivery.failed',
                        $command->getUserId()
                    ));
                    $work->fail();
                    $deliveryFailure = $throwable;

                    return;
                }

                $this->auditEvidenceRepository->add(AuditEvidence::record(
                    $command->getActorId(),
                    'user.invitation_delivery.confirmed',
                    $command->getUserId()
                ));
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
