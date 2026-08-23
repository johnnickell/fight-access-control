<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\User\CommandHandler;

use DateInterval;
use Fight\AccessControl\Application\AccessControl\ActivationGrant\Service\ActivationCredentialGenerator;
use Fight\AccessControl\Application\AccessControl\ActivationGrant\Service\InvitationAdministrationAuthorization;
use Fight\AccessControl\Application\AccessControl\ActivationGrant\Service\InvitationClock;
use Fight\AccessControl\Application\AccessControl\ActivationGrant\Service\InvitationDeliveryCipher;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrant;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrantRepository;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidenceRepository;
use Fight\AccessControl\Domain\AccessControl\User\Command\CorrectPendingInvitation;
use Fight\AccessControl\Domain\AccessControl\User\Event\PendingInvitationCorrected;
use Fight\AccessControl\Domain\AccessControl\User\Exception\PendingInvitationCorrectionException;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserRepository;
use Fight\Common\Application\Messaging\Command\CommandHandler;
use Fight\Common\Application\Messaging\Event\EventDispatcher;
use Fight\Common\Application\Repository\UnitOfWork;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use LogicException;
use Throwable;

/**
 * Atomically corrects a pending invitation and replaces its activation authority.
 */
final readonly class CorrectPendingInvitationHandler implements CommandHandler
{
    /**
     * Creates the pending-invitation correction handler.
     */
    public function __construct(
        private UserRepository $userRepository,
        private ActivationGrantRepository $activationGrantRepository,
        private InvitationAdministrationAuthorization $invitationAdministrationAuthorization,
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
        return CorrectPendingInvitation::class;
    }

    /**
     * @inheritDoc
     */
    public function handle(CommandMessage $commandMessage): void
    {
        /** @var CorrectPendingInvitation $command */
        $command = $commandMessage->payload();

        try {
            $event = $this->unitOfWork->commitTransactional(function () use ($command): PendingInvitationCorrected {
                $this->invitationAdministrationAuthorization->assertCanCorrectInvitation(
                    $command->getActorId(),
                    $command->getUserId()
                );

                $user = $this->userRepository->getById($command->getUserId());
                $predecessor = $this->activationGrantRepository->getLatestByUserId($command->getUserId());
                if (
                    !$user instanceof User
                    || !$predecessor instanceof ActivationGrant
                    || !$predecessor->isIssued()
                    || $predecessor->getDelivery()->getEmail()->canonical() !== $user->getEmail()->canonical()
                ) {
                    throw new PendingInvitationCorrectionException(
                        'The pending invitation cannot be corrected.'
                    );
                }

                $replacementUser = clone $user;
                $replacementUser->correctPendingInvitationEmail($command->getEmail());
                if (!$this->userRepository->replacePendingInvitationEmail($user, $replacementUser)) {
                    throw new LogicException('The pending invitation email changed concurrently or is reserved.');
                }

                $issuedAt = $this->invitationClock->now();
                $credential = $this->activationCredentialGenerator->generate();
                $revokedPredecessor = $predecessor->revoke($issuedAt);
                $successor = ActivationGrant::issue(
                    $command->getUserId(),
                    $credential,
                    $issuedAt,
                    $issuedAt->add(new DateInterval('P7D')),
                    $command->getEmail(),
                    $this->invitationDeliveryCipher->encrypt($credential->toString())
                );
                if (
                    !$this->activationGrantRepository->replaceWithSuccessor(
                        $predecessor,
                        $revokedPredecessor,
                        $successor
                    )
                ) {
                    throw new LogicException('The activation grant changed concurrently.');
                }

                $this->auditEvidenceRepository->add(AuditEvidence::record(
                    $command->getActorId()->toString(),
                    'user.pending_invitation_corrected',
                    $command->getUserId()
                ));

                return new PendingInvitationCorrected(
                    $command->getActorId(),
                    $command->getUserId(),
                    $command->getEmail(),
                    $successor->getDelivery()->getId()
                );
            });

            $this->eventDispatcher->trigger($event);
        } catch (Throwable $throwable) {
            $this->eventDispatcher->trigger(new CommandFailedEvent($command, $throwable->getMessage()));
            throw $throwable;
        }
    }
}
