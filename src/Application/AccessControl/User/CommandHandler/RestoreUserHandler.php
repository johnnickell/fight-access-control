<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\User\CommandHandler;

use DateInterval;
use Fight\AccessControl\Application\AccessControl\ActivationGrant\Service\ActivationCredentialGenerator;
use Fight\AccessControl\Application\AccessControl\ActivationGrant\Service\InvitationDeliveryCipher;
use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
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
use Fight\AccessControl\Domain\AccessControl\User\Command\RestoreUser;
use Fight\AccessControl\Domain\AccessControl\User\Event\UserRestored;
use Fight\AccessControl\Domain\AccessControl\User\Exception\UserLifecycleException;
use Fight\AccessControl\Domain\AccessControl\User\UserRepository;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
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
 * Class RestoreUserHandler
 *
 * Atomically restores a deleted identity, issuing fresh activation authority for pending activation.
 */
final readonly class RestoreUserHandler implements CommandHandler
{
    /**
     * Constructs RestoreUserHandler
     *
     * Creates the user-restore handler.
     */
    public function __construct(
        private UserRepository $userRepository,
        private ActivationGrantRepository $activationGrantRepository,
        private ActivationCredentialGenerator $activationCredentialGenerator,
        private InvitationDeliveryCipher $invitationDeliveryCipher,
        private Clock $clock,
        private TransactionalUnitOfWork $unitOfWork,
        private EventDispatcher $eventDispatcher,
        private ?AuditEvidenceRepository $auditEvidenceRepository = null
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function commandRegistration(): string
    {
        return RestoreUser::class;
    }

    /**
     * @inheritDoc
     */
    public function handle(CommandMessage $commandMessage): void
    {
        /** @var RestoreUser $command */
        $command = $commandMessage->payload();

        try {
            /** @var UserRestored $event */
            $event = $this->unitOfWork->commitTransactional(function () use ($command, $commandMessage): UserRestored {
                $user = $this->userRepository->getById($command->getUserId());
                if ($user === null) {
                    throw new ResourceNotFound('The user cannot be restored.');
                }

                if (!$user->getId()->equals($command->getUserId())) {
                    throw new LogicException('The User repository returned an unrelated identity.');
                }

                $now = $this->clock->now();
                $replacementUser = clone $user;
                try {
                    $replacementUser->restore($command->getRestorationState(), $now);
                } catch (UserLifecycleException $userLifecycleException) {
                    throw new StateConflict('The user cannot be restored.', previous: $userLifecycleException);
                }

                if (!$this->userRepository->replaceLifecycleState($user, $replacementUser)) {
                    throw new StateConflict('The user lifecycle state changed concurrently.');
                }

                $activationDeliveryId = null;
                $successor = null;
                if ($command->getRestorationState() === UserState::PENDING_ACTIVATION) {
                    $issuedAt = $this->clock->now();
                    $credential = $this->activationCredentialGenerator->generate();
                    $successor = ActivationGrant::issue(
                        ActivationOrigin::PRIVATE_INVITATION,
                        $command->getUserId(),
                        $credential,
                        $issuedAt,
                        $issuedAt->add(new DateInterval('P7D')),
                        $replacementUser->getEmail(),
                        $this->invitationDeliveryCipher->encrypt($credential->toString())
                    );
                    if (!$this->activationGrantRepository->addSuccessor($successor)) {
                        throw new StateConflict('The activation grant successor could not be issued.');
                    }

                    $activationDeliveryId = $successor->getDelivery()->getId();
                }

                $this->auditEvidenceRepository?->add(AuditEvidence::record(
                    $command->getActorId(),
                    new AuditSubject($command->getUserId()),
                    AuditAction::USER_RESTORED,
                    $now,
                    new AuditUserOutcome(
                        AuditUserRevisions::fromUser($user),
                        AuditUserRevisions::fromUser($replacementUser),
                        null,
                        $successor === null ? null : AuditGrantState::fromGrant($successor)
                    ),
                    new AuditCorrelation($commandMessage->id(), null)
                ));

                return new UserRestored(
                    $command->getActorId(),
                    $command->getUserId(),
                    $command->getRestorationState(),
                    $activationDeliveryId
                );
            });

            $this->eventDispatcher->trigger($event);
        } catch (Throwable $throwable) {
            try {
                $this->eventDispatcher->trigger(new CommandFailedEvent($command, 'User restoration failed.'));
            } catch (Throwable) {
                // Notification failure must not replace the original operation failure.
            }

            throw $throwable;
        }
    }
}
