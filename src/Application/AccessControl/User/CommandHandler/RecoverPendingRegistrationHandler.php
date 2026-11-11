<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\User\CommandHandler;

use DateInterval;
use Fight\AccessControl\Application\AccessControl\ActivationGrant\Service\ActivationCredentialGenerator;
use Fight\AccessControl\Application\AccessControl\ActivationGrant\Service\InvitationDeliveryCipher;
use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Application\AccessControl\User\Service\RegistrationRecoveryAuthorization;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrant;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrantRepository;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationOrigin;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditAction;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditCorrelation;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidenceRepository;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditGrantState;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditSubject;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditUserOutcome;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditUserRevisions;
use Fight\AccessControl\Domain\AccessControl\User\Command\RecoverPendingRegistration;
use Fight\AccessControl\Domain\AccessControl\User\Event\PendingRegistrationRecovered;
use Fight\AccessControl\Domain\AccessControl\User\Exception\RegistrationRecoveryAuthorizationException;
use Fight\AccessControl\Domain\AccessControl\User\Exception\RegistrationRecoveryException;
use Fight\AccessControl\Domain\AccessControl\User\UserRepository;
use Fight\Common\Application\Error\Exception\PermissionDenied;
use Fight\Common\Application\Error\Exception\PreconditionFailed;
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
 * Class RecoverPendingRegistrationHandler
 */
final readonly class RecoverPendingRegistrationHandler implements CommandHandler
{
    /**
     * Constructs RecoverPendingRegistrationHandler
     */
    public function __construct(
        private UserRepository $userRepository,
        private ActivationGrantRepository $activationGrantRepository,
        private RegistrationRecoveryAuthorization $registrationRecoveryAuthorization,
        private TransactionalUnitOfWork $unitOfWork,
        private ActivationCredentialGenerator $activationCredentialGenerator,
        private InvitationDeliveryCipher $invitationDeliveryCipher,
        private Clock $clock,
        private EventDispatcher $eventDispatcher,
        private ?AuditEvidenceRepository $auditEvidenceRepository = null
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function commandRegistration(): string
    {
        return RecoverPendingRegistration::class;
    }

    /**
     * @inheritDoc
     */
    public function handle(CommandMessage $commandMessage): void
    {
        /** @var RecoverPendingRegistration $command */
        $command = $commandMessage->payload();

        try {
            /** @var PendingRegistrationRecovered $event */
            $event = $this->unitOfWork->commitTransactional(function () use (
                $command,
                $commandMessage
            ): PendingRegistrationRecovered {
                try {
                    $this->registrationRecoveryAuthorization->assertCanRecoverRegistration(
                        $command->getActorId(),
                        $command->getUserId()
                    );
                } catch (RegistrationRecoveryAuthorizationException $registrationRecoveryAuthorizationException) {
                    throw new PermissionDenied(
                        'Registration recovery is not authorized.',
                        previous: $registrationRecoveryAuthorizationException
                    );
                }

                $user = $this->userRepository->getById($command->getUserId());
                if ($user === null) {
                    throw new ResourceNotFound('The pending registration cannot be recovered.');
                }

                if (!$user->getId()->equals($command->getUserId())) {
                    throw new LogicException('The User repository returned an unrelated identity.');
                }

                $predecessor = $this->activationGrantRepository->getLatestByUserId($command->getUserId());
                if ($predecessor === null) {
                    throw new StateConflict('The pending registration has no activation authority.');
                }

                if (!$predecessor->ownsDelivery($predecessor->getDelivery()->getId(), $command->getUserId())) {
                    throw new LogicException('The activation repository returned inconsistent ownership.');
                }

                if (!$predecessor->getDelivery()->getId()->equals($command->getExpectedActivationDeliveryId())) {
                    throw new PreconditionFailed('The expected activation generation is no longer current.');
                }

                // A fresh admission sample after authoritative reads, not a completion deadline.
                $issuedAt = $this->clock->now();
                $replacement = clone $user;
                try {
                    $predecessor->assertRegistrationRecovery(
                        $command->getUserId(),
                        $command->getExpectedActivationDeliveryId(),
                        $user->getEmail(),
                        $command->getEmail(),
                        $issuedAt
                    );
                    $replacement->recoverPendingRegistrationEmail($command->getEmail(), $issuedAt);
                } catch (RegistrationRecoveryException $registrationRecoveryException) {
                    throw new StateConflict(
                        'The pending registration cannot be recovered.',
                        previous: $registrationRecoveryException
                    );
                }

                if (!$this->userRepository->replacePendingRegistration($user, $replacement)) {
                    throw new StateConflict('The pending registration changed or is reserved.');
                }

                $credential = $this->activationCredentialGenerator->generate();
                $successor = ActivationGrant::issue(
                    ActivationOrigin::REGISTRATION,
                    $user->getId(),
                    $credential,
                    $issuedAt,
                    $issuedAt->add(new DateInterval('P7D')),
                    $replacement->getEmail(),
                    $this->invitationDeliveryCipher->encrypt($credential->toString())
                );
                $revokedPredecessor = $predecessor->revoke($issuedAt);
                if (
                    !$this->activationGrantRepository->replaceWithSuccessor(
                        $predecessor,
                        $revokedPredecessor,
                        $successor
                    )
                ) {
                    throw new StateConflict('The pending registration changed concurrently.');
                }

                $this->auditEvidenceRepository?->add(AuditEvidence::record(
                    $command->getActorId(),
                    new AuditSubject($user->getId()),
                    AuditAction::USER_PENDING_REGISTRATION_RECOVERED,
                    $issuedAt,
                    new AuditUserOutcome(
                        AuditUserRevisions::fromUser($user),
                        AuditUserRevisions::fromUser($replacement),
                        AuditGrantState::fromGrant($predecessor),
                        AuditGrantState::fromGrant($successor),
                        AuditGrantState::fromGrant($revokedPredecessor)
                    ),
                    new AuditCorrelation($commandMessage->id(), null)
                ));

                return new PendingRegistrationRecovered(
                    $command->getActorId(),
                    $user->getId(),
                    $predecessor->getDelivery()->getId(),
                    $successor->getDelivery()->getId(),
                    $replacement->getEmail()
                );
            });
            $this->eventDispatcher->trigger($event);
        } catch (Throwable $throwable) {
            try {
                $this->eventDispatcher->trigger(new CommandFailedEvent(
                    $command,
                    'Pending registration recovery failed.'
                ));
            } catch (Throwable) {
                // Preserve the original failure even when notification also fails.
            }

            throw $throwable;
        }
    }
}
