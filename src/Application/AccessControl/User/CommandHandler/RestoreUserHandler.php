<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\User\CommandHandler;

use DateInterval;
use Fight\AccessControl\Application\AccessControl\ActivationGrant\Service\ActivationCredentialGenerator;
use Fight\AccessControl\Application\AccessControl\ActivationGrant\Service\InvitationClock;
use Fight\AccessControl\Application\AccessControl\ActivationGrant\Service\InvitationDeliveryCipher;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrant;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrantRepository;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidenceRepository;
use Fight\AccessControl\Domain\AccessControl\User\Command\RestoreUser;
use Fight\AccessControl\Domain\AccessControl\User\Event\UserRestored;
use Fight\AccessControl\Domain\AccessControl\User\Exception\UserLifecycleException;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserRepository;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Application\Messaging\Command\CommandHandler;
use Fight\Common\Application\Messaging\Event\EventDispatcher;
use Fight\Common\Application\Repository\UnitOfWork;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use LogicException;
use Throwable;

/**
 * Atomically restores a deleted identity, issuing fresh activation authority for pending activation.
 */
final readonly class RestoreUserHandler implements CommandHandler
{
    /**
     * Creates the user-restore handler.
     */
    public function __construct(
        private UserRepository $userRepository,
        private ActivationGrantRepository $activationGrantRepository,
        private ActivationCredentialGenerator $activationCredentialGenerator,
        private InvitationDeliveryCipher $invitationDeliveryCipher,
        private InvitationClock $invitationClock,
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
            $event = $this->unitOfWork->commitTransactional(function () use ($command): UserRestored {
                $user = $this->userRepository->getById($command->getUserId());
                if (!$user instanceof User) {
                    throw new UserLifecycleException('The user cannot be restored.');
                }

                $replacementUser = clone $user;
                $replacementUser->restore($command->getRestorationState());
                if (!$this->userRepository->replaceLifecycleState($user, $replacementUser)) {
                    throw new LogicException('The user lifecycle state changed concurrently.');
                }

                $activationDeliveryId = null;
                if ($command->getRestorationState() === UserState::PENDING_ACTIVATION) {
                    $issuedAt = $this->invitationClock->now();
                    $credential = $this->activationCredentialGenerator->generate();
                    $successor = ActivationGrant::issue(
                        $command->getUserId(),
                        $credential,
                        $issuedAt,
                        $issuedAt->add(new DateInterval('P7D')),
                        $replacementUser->getEmail(),
                        $this->invitationDeliveryCipher->encrypt($credential->toString())
                    );
                    if (!$this->activationGrantRepository->addSuccessor($successor)) {
                        throw new LogicException('The activation grant successor could not be issued.');
                    }

                    $activationDeliveryId = $successor->getDelivery()->getId();
                }

                $this->auditEvidenceRepository->add(AuditEvidence::record(
                    $command->getActorId()->toString(),
                    'user.restored',
                    $command->getUserId()
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
            $this->eventDispatcher->trigger(new CommandFailedEvent($command, $throwable->getMessage()));
            throw $throwable;
        }
    }
}
