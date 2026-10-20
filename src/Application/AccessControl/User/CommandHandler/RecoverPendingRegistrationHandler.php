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
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidenceRepository;
use Fight\AccessControl\Domain\AccessControl\User\Command\RecoverPendingRegistration;
use Fight\AccessControl\Domain\AccessControl\User\Event\PendingRegistrationRecovered;
use Fight\AccessControl\Domain\AccessControl\User\Exception\RegistrationRecoveryException;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserRepository;
use Fight\Common\Application\Messaging\Command\CommandHandler;
use Fight\Common\Application\Messaging\Event\EventDispatcher;
use Fight\Common\Application\Repository\TransactionalUnitOfWork;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
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
        private RegistrationRecoveryAuthorization $authorization,
        private AuditEvidenceRepository $auditEvidenceRepository,
        private TransactionalUnitOfWork $unitOfWork,
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
            $event = $this->unitOfWork->commitTransactional(function () use ($command): PendingRegistrationRecovered {
                $this->authorization->assertCanRecoverRegistration($command->getActorId(), $command->getUserId());
                $user = $this->userRepository->getById($command->getUserId());
                $predecessor = $this->activationGrantRepository->getLatestByUserId($command->getUserId());
                if (
                    !$user instanceof User
                    || !$user->getId()->equals($command->getUserId())
                    || !$predecessor instanceof ActivationGrant
                ) {
                    throw new RegistrationRecoveryException('The pending registration cannot be recovered.');
                }

                // A fresh admission sample after authoritative reads, not a completion deadline.
                $issuedAt = $this->clock->now();
                $predecessor->assertRegistrationRecovery(
                    $command->getUserId(),
                    $command->getExpectedActivationDeliveryId(),
                    $user->getEmail(),
                    $command->getEmail(),
                    $issuedAt
                );
                $replacement = clone $user;
                $replacement->recoverPendingRegistrationEmail($command->getEmail(), $issuedAt);
                if (!$this->userRepository->replacePendingRegistration($user, $replacement)) {
                    throw new RegistrationRecoveryException('The pending registration changed or is reserved.');
                }

                $credential = $this->credentials->generate();
                $successor = ActivationGrant::issue(
                    ActivationOrigin::REGISTRATION,
                    $user->getId(),
                    $credential,
                    $issuedAt,
                    $issuedAt->add(new DateInterval('P7D')),
                    $replacement->getEmail(),
                    $this->cipher->encrypt($credential->toString())
                );
                if (
                    !$this->activationGrantRepository->replaceWithSuccessor(
                        $predecessor,
                        $predecessor->revoke($issuedAt),
                        $successor
                    )
                ) {
                    throw new RegistrationRecoveryException('The pending registration changed concurrently.');
                }

                $this->auditEvidenceRepository->add(AuditEvidence::record(
                    $command->getActorId()->toString(),
                    'user.pending_registration_recovered',
                    $user->getId()
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
