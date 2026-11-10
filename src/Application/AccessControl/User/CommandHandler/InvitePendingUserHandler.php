<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\User\CommandHandler;

use DateInterval;
use Fight\AccessControl\Application\AccessControl\ActivationGrant\Service\ActivationCredentialGenerator;
use Fight\AccessControl\Application\AccessControl\ActivationGrant\Service\InvitationDeliveryCipher;
use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationDeliveryId;
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
use Fight\AccessControl\Domain\AccessControl\User\Command\InvitePendingUser;
use Fight\AccessControl\Domain\AccessControl\User\Event\UserInvited;
use Fight\AccessControl\Domain\AccessControl\User\Exception\DuplicateEmailException;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserRepository;
use Fight\Common\Application\Error\Exception\StateConflict;
use Fight\Common\Application\Messaging\Command\CommandHandler;
use Fight\Common\Application\Messaging\Event\EventDispatcher;
use Fight\Common\Application\Repository\TransactionalUnitOfWork;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use LogicException;
use Throwable;

/**
 * Class InvitePendingUserHandler
 *
 * Atomically records a pending invitation and its required durable work.
 */
final readonly class InvitePendingUserHandler implements CommandHandler
{
    /**
     * Constructs InvitePendingUserHandler
     *
     * Creates the invitation handler.
     */
    public function __construct(
        private UserRepository $userRepository,
        private ActivationGrantRepository $activationGrantRepository,
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
        return InvitePendingUser::class;
    }

    /**
     * @inheritDoc
     */
    public function handle(CommandMessage $commandMessage): void
    {
        /** @var InvitePendingUser $command */
        $command = $commandMessage->payload();

        try {
            $issuedAt = $this->clock->now();
            /** @var ActivationDeliveryId $activationDeliveryId */
            $activationDeliveryId = $this->unitOfWork->commitTransactional(function () use (
                $command,
                $commandMessage,
                $issuedAt
            ): ActivationDeliveryId {
                $user = User::invite($command->getUserId(), $command->getEmail(), $issuedAt);
                try {
                    $this->userRepository->add($user);
                } catch (DuplicateEmailException $duplicateEmailException) {
                    throw new StateConflict(
                        'The invitation email is already claimed.',
                        previous: $duplicateEmailException
                    );
                }

                $credential = $this->activationCredentialGenerator->generate();
                $grant = ActivationGrant::issue(
                    ActivationOrigin::PRIVATE_INVITATION,
                    $user->getId(),
                    $credential,
                    $issuedAt,
                    $issuedAt->add(new DateInterval('P7D')),
                    $user->getEmail(),
                    $this->invitationDeliveryCipher->encrypt($credential->toString())
                );
                if (!$this->activationGrantRepository->add($grant)) {
                    throw new LogicException('The activation grant could not be added.');
                }

                $this->auditEvidenceRepository->add(AuditEvidence::record(
                    $command->getActorId(),
                    new AuditSubject($user->getId()),
                    AuditAction::USER_INVITED,
                    $issuedAt,
                    new AuditUserOutcome(
                        null,
                        AuditUserRevisions::fromUser($user),
                        null,
                        AuditGrantState::fromGrant($grant)
                    ),
                    new AuditCorrelation($commandMessage->id(), null)
                ));

                return $grant->getDelivery()->getId();
            });

            $this->eventDispatcher->trigger(new UserInvited(
                $command->getActorId(),
                $command->getUserId(),
                $activationDeliveryId,
                $command->getEmail(),
                $issuedAt
            ));
        } catch (Throwable $throwable) {
            try {
                $this->eventDispatcher->trigger(new CommandFailedEvent($command, 'User invitation failed.'));
            } catch (Throwable) {
                // Notification failure must not replace the original operation failure.
            }

            throw $throwable;
        }
    }
}
