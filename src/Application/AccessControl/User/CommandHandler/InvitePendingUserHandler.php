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
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidenceRepository;
use Fight\AccessControl\Domain\AccessControl\User\Command\InvitePendingUser;
use Fight\AccessControl\Domain\AccessControl\User\Event\UserInvited;
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
        private AuditEvidenceRepository $auditEvidenceRepository,
        private UnitOfWork $unitOfWork,
        private ActivationCredentialGenerator $credentials,
        private InvitationDeliveryCipher $cipher,
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
        $issuedAt = $this->clock->now();

        try {
            $activationDeliveryId = $this->unitOfWork->commitTransactional(function () use (
                $command,
                $issuedAt
            ): ActivationDeliveryId {
                $user = User::invite($command->getUserId(), $command->getEmail(), $issuedAt);
                $this->userRepository->add($user);

                $credential = $this->credentials->generate();
                $grant = ActivationGrant::issue(
                    $user->getId(),
                    $credential,
                    $issuedAt,
                    $issuedAt->add(new DateInterval('P7D')),
                    $user->getEmail(),
                    $this->cipher->encrypt($credential->toString())
                );
                if (!$this->activationGrantRepository->add($grant)) {
                    throw new LogicException('The activation grant could not be added.');
                }

                $this->auditEvidenceRepository->add(AuditEvidence::record(
                    $command->getActorId(),
                    'user.invited',
                    $user->getId()
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
            $this->eventDispatcher->trigger(new CommandFailedEvent($command, $throwable->getMessage()));
            throw $throwable;
        }
    }
}
