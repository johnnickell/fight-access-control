<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\User\CommandHandler;

use DateInterval;
use Fight\AccessControl\Application\AccessControl\ActivationGrant\Service\ActivationCredentialGenerator;
use Fight\AccessControl\Application\AccessControl\ActivationGrant\Service\InvitationAdministrationAuthorization;
use Fight\AccessControl\Application\AccessControl\ActivationGrant\Service\InvitationDeliveryCipher;
use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrant;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrantRepository;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationOrigin;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Exception\InvitationAdministrationAuthorizationException;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidenceRepository;
use Fight\AccessControl\Domain\AccessControl\User\Command\CorrectPendingInvitation;
use Fight\AccessControl\Domain\AccessControl\User\Event\PendingInvitationCorrected;
use Fight\AccessControl\Domain\AccessControl\User\Exception\PendingInvitationCorrectionException;
use Fight\AccessControl\Domain\AccessControl\User\UserRepository;
use Fight\Common\Application\Error\Exception\PermissionDenied;
use Fight\Common\Application\Error\Exception\ResourceNotFound;
use Fight\Common\Application\Error\Exception\StateConflict;
use Fight\Common\Application\Messaging\Command\CommandHandler;
use Fight\Common\Application\Messaging\Event\EventDispatcher;
use Fight\Common\Application\Repository\TransactionalUnitOfWork;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use LogicException;
use Throwable;

/**
 * Class CorrectPendingInvitationHandler
 *
 * Atomically corrects a pending invitation and replaces its activation authority.
 */
final readonly class CorrectPendingInvitationHandler implements CommandHandler
{
    /**
     * Constructs CorrectPendingInvitationHandler
     *
     * Creates the pending-invitation correction handler.
     */
    public function __construct(
        private UserRepository $userRepository,
        private ActivationGrantRepository $activationGrantRepository,
        private InvitationAdministrationAuthorization $invitationAdministrationAuthorization,
        private AuditEvidenceRepository $auditEvidenceRepository,
        private TransactionalUnitOfWork $unitOfWork,
        private ActivationCredentialGenerator $activationCredentialGenerator,
        private InvitationDeliveryCipher $invitationDeliveryCipher,
        private Clock $clock,
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
            /** @var PendingInvitationCorrected $event */
            $event = $this->unitOfWork->commitTransactional(function () use ($command): PendingInvitationCorrected {
                try {
                    $this->invitationAdministrationAuthorization->assertCanCorrectInvitation(
                        $command->getActorId(),
                        $command->getUserId()
                    );
                } catch (
                    InvitationAdministrationAuthorizationException $invitationAdministrationAuthorizationException
                ) {
                    throw new PermissionDenied(
                        'Invitation correction is not authorized.',
                        previous: $invitationAdministrationAuthorizationException
                    );
                }

                $user = $this->userRepository->getById($command->getUserId());
                if ($user === null) {
                    throw new ResourceNotFound('The pending invitation cannot be corrected.');
                }

                if (!$user->getId()->equals($command->getUserId())) {
                    throw new LogicException('The User repository returned an unrelated identity.');
                }

                $predecessor = $this->activationGrantRepository->getLatestByUserId($command->getUserId());
                if ($predecessor === null) {
                    throw new StateConflict('The pending invitation has no activation authority.');
                }

                if (!$predecessor->ownsDelivery($predecessor->getDelivery()->getId(), $command->getUserId())) {
                    throw new LogicException('The activation repository returned inconsistent ownership.');
                }

                if (
                    !$predecessor->isIssued()
                    || $predecessor->getOrigin() !== ActivationOrigin::PRIVATE_INVITATION
                    || $predecessor->getDelivery()->getEmail()->canonical() !== $user->getEmail()->canonical()
                ) {
                    throw new StateConflict('The pending invitation cannot be corrected.');
                }

                $replacementUser = clone $user;
                $now = $this->clock->now();
                try {
                    $replacementUser->correctPendingInvitationEmail($command->getEmail(), $now);
                } catch (PendingInvitationCorrectionException $pendingInvitationCorrectionException) {
                    throw new StateConflict(
                        'The pending invitation cannot be corrected.',
                        previous: $pendingInvitationCorrectionException
                    );
                }

                if (!$this->userRepository->replacePendingInvitationEmail($user, $replacementUser)) {
                    throw new StateConflict('The pending invitation email changed concurrently or is reserved.');
                }

                $issuedAt = $this->clock->now();
                $credential = $this->activationCredentialGenerator->generate();
                $revokedPredecessor = $predecessor->revoke($issuedAt);
                $successor = ActivationGrant::issue(
                    ActivationOrigin::PRIVATE_INVITATION,
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
                    throw new StateConflict('The activation grant changed concurrently.');
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
            try {
                $this->eventDispatcher->trigger(new CommandFailedEvent(
                    $command,
                    'Pending invitation correction failed.'
                ));
            } catch (Throwable) {
                // Notification failure must not replace the original operation failure.
            }

            throw $throwable;
        }
    }
}
