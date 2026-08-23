<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\ActivationGrant\CommandHandler;

use DateInterval;
use Fight\AccessControl\Application\AccessControl\ActivationGrant\Service\ActivationCredentialGenerator;
use Fight\AccessControl\Application\AccessControl\ActivationGrant\Service\InvitationClock;
use Fight\AccessControl\Application\AccessControl\ActivationGrant\Service\InvitationDeliveryCipher;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationDeliveryId;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrant;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrantRepository;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Command\ResendInvitationDelivery;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Event\InvitationDeliveryResent;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Exception\ActivationDeliveryNotResendableException;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidenceRepository;
use Fight\Common\Application\Messaging\Command\CommandHandler;
use Fight\Common\Application\Messaging\Event\EventDispatcher;
use Fight\Common\Application\Repository\UnitOfWork;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use LogicException;
use Throwable;

/**
 * Atomically replaces an activation grant and stages its recoverable replacement delivery work.
 */
final readonly class ResendInvitationDeliveryHandler implements CommandHandler
{
    /**
     * Creates the activation-delivery resend handler.
     */
    public function __construct(
        private ActivationGrantRepository $activationGrantRepository,
        private AuditEvidenceRepository $auditEvidenceRepository,
        private UnitOfWork $unitOfWork,
        private ActivationCredentialGenerator $activationCredentialGenerator,
        private InvitationDeliveryCipher $invitationDeliveryCipher,
        private InvitationClock $invitationClock,
        private EventDispatcher $eventDispatcher
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function commandRegistration(): string
    {
        return ResendInvitationDelivery::class;
    }

    /**
     * @inheritDoc
     */
    public function handle(CommandMessage $commandMessage): void
    {
        /** @var ResendInvitationDelivery $command */
        $command = $commandMessage->payload();

        try {
            $issuedAt = $this->invitationClock->now();
            $activationDeliveryId = $this->unitOfWork->commitTransactional(function () use (
                $command,
                $issuedAt
            ): ActivationDeliveryId {
                $predecessor = $this->activationGrantRepository->getLatestByUserId($command->getUserId());

                if (
                    !$predecessor instanceof ActivationGrant
                    || $predecessor->isIssued() === false
                ) {
                    throw new ActivationDeliveryNotResendableException(
                        'The activation delivery cannot be resent.'
                    );
                }

                $credential = $this->activationCredentialGenerator->generate();
                $revokedPredecessor = $predecessor->revoke($issuedAt);
                $replacement = ActivationGrant::issue(
                    $command->getUserId(),
                    $credential,
                    $issuedAt,
                    $issuedAt->add(new DateInterval('P7D')),
                    $predecessor->getDelivery()->getEmail(),
                    $this->invitationDeliveryCipher->encrypt($credential->toString())
                );
                if (
                    !$this->activationGrantRepository->replaceWithSuccessor(
                        $predecessor,
                        $revokedPredecessor,
                        $replacement
                    )
                ) {
                    throw new LogicException('The activation grant changed concurrently.');
                }

                $this->auditEvidenceRepository->add(AuditEvidence::record(
                    $command->getActorId(),
                    'user.invitation_delivery.resent',
                    $command->getUserId()
                ));

                return $replacement->getDelivery()->getId();
            });

            $this->eventDispatcher->trigger(new InvitationDeliveryResent(
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
