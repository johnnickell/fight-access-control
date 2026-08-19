<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\User\CommandHandler;

use DateInterval;
use Fight\AccessControl\Application\AccessControl\User\Service\ActivationCredentialGenerator;
use Fight\AccessControl\Application\AccessControl\User\Service\InvitationClock;
use Fight\AccessControl\Application\AccessControl\User\Service\InvitationDeliveryCipher;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidenceRepository;
use Fight\AccessControl\Domain\AccessControl\User\ActivationGrant;
use Fight\AccessControl\Domain\AccessControl\User\ActivationGrantRepository;
use Fight\AccessControl\Domain\AccessControl\User\Command\ResendInvitationDelivery;
use Fight\AccessControl\Domain\AccessControl\User\Event\InvitationDeliveryResent;
use Fight\AccessControl\Domain\AccessControl\User\Exception\InvitationDeliveryNotResendableException;
use Fight\AccessControl\Domain\AccessControl\User\InvitationDelivery;
use Fight\AccessControl\Domain\AccessControl\User\InvitationDeliveryRepository;
use Fight\Common\Application\Messaging\Command\CommandHandler;
use Fight\Common\Application\Messaging\Event\EventDispatcher;
use Fight\Common\Application\Repository\UnitOfWork;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
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
        private InvitationDeliveryRepository $invitationDeliveryRepository,
        private AuditEvidenceRepository $auditEvidenceRepository,
        private UnitOfWork $unitOfWork,
        private ActivationCredentialGenerator $credentials,
        private InvitationDeliveryCipher $cipher,
        private InvitationClock $clock,
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
            $issuedAt = $this->clock->now();
            $this->unitOfWork->commitTransactional(function () use ($command, $issuedAt): void {
                $predecessor = $this->activationGrantRepository->getByUserId($command->getUserId());
                $previousDelivery = $this->invitationDeliveryRepository->getByUserId($command->getUserId());

                if (
                    !$predecessor instanceof ActivationGrant
                    || $predecessor->isIssued() === false
                    || !$previousDelivery instanceof InvitationDelivery
                ) {
                    throw new InvitationDeliveryNotResendableException(
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
                $delivery = InvitationDelivery::create(
                    $command->getUserId(),
                    $previousDelivery->email(),
                    $this->cipher->encrypt($credential->toString()),
                    $replacement->getExpiresAt()
                );
                $this->activationGrantRepository->replace(
                    $predecessor,
                    $revokedPredecessor,
                    $replacement
                );
                $this->invitationDeliveryRepository->add($delivery);
                $this->auditEvidenceRepository->add(AuditEvidence::record(
                    $command->getActorId(),
                    'user.invitation_delivery.resent',
                    $command->getUserId()
                ));
            });

            $this->eventDispatcher->trigger(new InvitationDeliveryResent(
                $command->getActorId(),
                $command->getUserId()
            ));
        } catch (Throwable $throwable) {
            $this->eventDispatcher->trigger(new CommandFailedEvent($command, $throwable->getMessage()));
            throw $throwable;
        }
    }
}
