<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\ActivationGrant\CommandHandler;

use Fight\AccessControl\Application\AccessControl\ActivationGrant\Service\InvitationDeliveryInvoker;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrant;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrantRepository;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Command\DeliverUserInvitation;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Event\UserInvitationDelivered;
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
 * Invokes durable activation delivery through a consumer-owned transport-neutral port.
 */
final readonly class DeliverUserInvitationHandler implements CommandHandler
{
    /**
     * Creates the invitation-delivery handler.
     */
    public function __construct(
        private ActivationGrantRepository $activationGrantRepository,
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
            $deliveryFailure = null;
            $successEvent = $this->unitOfWork->commitTransactional(function () use (
                $command,
                &$deliveryFailure
            ): ?UserInvitationDelivered {
                $activationGrant = $this->activationGrantRepository->getByDeliveryId(
                    $command->getActivationDeliveryId()
                );
                $latestActivationGrant = $this->activationGrantRepository->getLatestByUserId($command->getUserId());

                if (
                    !$activationGrant instanceof ActivationGrant
                    || !$latestActivationGrant instanceof ActivationGrant
                    || !$latestActivationGrant->getId()->equals($activationGrant->getId())
                    || $latestActivationGrant->getRevision() !== $activationGrant->getRevision()
                    || !$activationGrant->getUserId()->equals($command->getUserId())
                ) {
                    throw new ActivationDeliveryNotRetryableException(
                        'The activation delivery work is no longer retryable.'
                    );
                }

                $claimedActivationGrant = $activationGrant->claimDelivery();
                if (!$this->activationGrantRepository->replace($activationGrant, $claimedActivationGrant)) {
                    throw new ActivationDeliveryNotRetryableException(
                        'The activation delivery generation changed concurrently.'
                    );
                }

                try {
                    $this->invitationDeliveryInvoker->invoke($claimedActivationGrant->getDelivery());
                } catch (Throwable $throwable) {
                    $this->auditEvidenceRepository->add(AuditEvidence::record(
                        $command->getActorId(),
                        'user.invitation_delivery.failed',
                        $command->getUserId()
                    ));
                    $replacement = $claimedActivationGrant->failDelivery();
                    if (!$this->activationGrantRepository->replace($claimedActivationGrant, $replacement)) {
                        throw new ActivationDeliveryNotRetryableException(
                            'The activation delivery generation changed concurrently.'
                        );
                    }

                    $deliveryFailure = $throwable;

                    return null;
                }

                $this->auditEvidenceRepository->add(AuditEvidence::record(
                    $command->getActorId(),
                    'user.invitation_delivery.confirmed',
                    $command->getUserId()
                ));
                $replacement = $claimedActivationGrant->confirmDelivery();
                if (!$this->activationGrantRepository->replace($claimedActivationGrant, $replacement)) {
                    throw new ActivationDeliveryNotRetryableException(
                        'The activation delivery generation changed concurrently.'
                    );
                }

                return new UserInvitationDelivered(
                    $command->getActorId(),
                    $command->getUserId(),
                    $command->getActivationDeliveryId()
                );
            });

            if ($deliveryFailure instanceof Throwable) {
                throw $deliveryFailure;
            }

            if ($successEvent instanceof UserInvitationDelivered) {
                $this->eventDispatcher->trigger($successEvent);
            }
        } catch (Throwable $throwable) {
            $this->eventDispatcher->trigger(new CommandFailedEvent($command, $throwable->getMessage()));
            throw $throwable;
        }
    }
}
