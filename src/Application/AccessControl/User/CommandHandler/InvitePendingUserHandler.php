<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\User\CommandHandler;

use DateInterval;
use Fight\AccessControl\Application\AccessControl\User\Service\ActivationCredentialGenerator;
use Fight\AccessControl\Application\AccessControl\User\Service\ActivationDeliveryCipher;
use Fight\AccessControl\Application\AccessControl\User\Service\InvitationClock;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidenceRepository;
use Fight\AccessControl\Domain\AccessControl\User\ActivationDeliveryWork;
use Fight\AccessControl\Domain\AccessControl\User\ActivationDeliveryWorkRepository;
use Fight\AccessControl\Domain\AccessControl\User\ActivationGrant;
use Fight\AccessControl\Domain\AccessControl\User\ActivationGrantRepository;
use Fight\AccessControl\Domain\AccessControl\User\Command\InvitePendingUser;
use Fight\AccessControl\Domain\AccessControl\User\Event\UserInvited;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserRepository;
use Fight\Common\Application\Messaging\Command\CommandHandler;
use Fight\Common\Application\Messaging\Event\EventDispatcher;
use Fight\Common\Application\Repository\UnitOfWork;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Throwable;

/**
 * Atomically records a pending invitation and its required durable work.
 */
final readonly class InvitePendingUserHandler implements CommandHandler
{
    /**
     * Creates the invitation handler.
     */
    public function __construct(
        private UserRepository $userRepository,
        private ActivationGrantRepository $activationGrantRepository,
        private ActivationDeliveryWorkRepository $activationDeliveryWorkRepository,
        private AuditEvidenceRepository $auditEvidenceRepository,
        private UnitOfWork $unitOfWork,
        private ActivationCredentialGenerator $credentials,
        private ActivationDeliveryCipher $cipher,
        private InvitationClock $clock,
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
        $issuedAt = $this->clock->now();

        try {
            $this->unitOfWork->commitTransactional(function () use ($command, $issuedAt): void {
                $user = User::invite($command->getUserId(), $command->getEmail());
                $this->userRepository->add($user);

                $credential = $this->credentials->generate();
                $grant = ActivationGrant::issue(
                    $user->getId(),
                    $credential,
                    $issuedAt,
                    $issuedAt->add(new DateInterval('P7D'))
                );
                $delivery = ActivationDeliveryWork::create(
                    $grant->getUserId(),
                    $user->getEmail()->toString(),
                    $this->cipher->encrypt($credential),
                    $grant->getExpiresAt()
                );
                $this->activationGrantRepository->add($grant);
                $this->activationDeliveryWorkRepository->add($delivery);
                $this->auditEvidenceRepository->add(AuditEvidence::record(
                    $command->getActorId(),
                    'user.invited',
                    $user->getId()
                ));
            });

            $this->eventDispatcher->trigger(new UserInvited(
                $command->getActorId(),
                $command->getUserId(),
                $command->getEmail(),
                $issuedAt
            ));
        } catch (Throwable $throwable) {
            $this->eventDispatcher->trigger(new CommandFailedEvent($command, $throwable->getMessage()));
            throw $throwable;
        }
    }
}
