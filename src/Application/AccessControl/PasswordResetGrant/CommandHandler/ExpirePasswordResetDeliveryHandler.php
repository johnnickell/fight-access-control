<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\PasswordResetGrant\CommandHandler;

use Fight\AccessControl\Domain\AccessControl\Audit\AuditAction;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditCorrelation;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidenceRepository;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditGrantOutcome;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditGrantState;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditSubject;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\Command\ExpirePasswordResetDelivery;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\Event\PasswordResetDeliveryExpired;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrant;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrantRepository;
use Fight\Common\Application\Messaging\Command\CommandHandler;
use Fight\Common\Application\Messaging\Event\EventDispatcher;
use Fight\Common\Application\Repository\TransactionalUnitOfWork;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use LogicException;
use Throwable;

/**
 * Class ExpirePasswordResetDeliveryHandler
 *
 * Processes terminal password-reset delivery expiry without owning its scheduler.
 */
final readonly class ExpirePasswordResetDeliveryHandler implements CommandHandler
{
    /**
     * Constructs ExpirePasswordResetDeliveryHandler
     *
     * Creates the password-reset delivery-expiry handler.
     */
    public function __construct(
        private PasswordResetGrantRepository $passwordResetGrantRepository,
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
        return ExpirePasswordResetDelivery::class;
    }

    /**
     * @inheritDoc
     */
    public function handle(CommandMessage $commandMessage): void
    {
        /** @var ExpirePasswordResetDelivery $command */
        $command = $commandMessage->payload();

        try {
            /** @var ?PasswordResetDeliveryExpired $successEvent */
            $successEvent = $this->unitOfWork->commitTransactional(function () use (
                $command,
                $commandMessage
            ): ?PasswordResetDeliveryExpired {
                $passwordResetGrant = $this->passwordResetGrantRepository->getByDeliveryId(
                    $command->getPasswordResetDeliveryId()
                );
                if (
                    $passwordResetGrant instanceof PasswordResetGrant
                    && (!$passwordResetGrant->getDelivery()->getId()->equals($command->getPasswordResetDeliveryId())
                        || !$passwordResetGrant->ownsDelivery(
                            $command->getPasswordResetDeliveryId(),
                            $passwordResetGrant->getUserId()
                        ))
                ) {
                    throw new LogicException('The credential repository returned another delivery.');
                }

                if (
                    !$passwordResetGrant instanceof PasswordResetGrant
                    || !$passwordResetGrant->getUserId()->equals($command->getUserId())
                ) {
                    return null;
                }

                $latest = $this->passwordResetGrantRepository->getLatestByUserId($command->getUserId());
                if ($latest instanceof PasswordResetGrant && !$latest->getUserId()->equals($command->getUserId())) {
                    throw new LogicException("The credential repository returned another User's generation.");
                }

                if (
                    !$latest instanceof PasswordResetGrant
                    || !$latest->getId()->equals($passwordResetGrant->getId())
                    || !$passwordResetGrant->isIssued()
                ) {
                    return null;
                }

                $expiredGrant = $passwordResetGrant->expireDeliveryAt($command->getOccurredAt());
                if ($expiredGrant === $passwordResetGrant) {
                    return null;
                }

                if (!$this->passwordResetGrantRepository->replace($passwordResetGrant, $expiredGrant)) {
                    return null;
                }

                $this->auditEvidenceRepository?->add(AuditEvidence::record(
                    $command->getActorId(),
                    new AuditSubject($command->getUserId()),
                    AuditAction::PASSWORD_RESET_DELIVERY_EXPIRED,
                    $command->getOccurredAt(),
                    new AuditGrantOutcome(
                        AuditGrantState::fromGrant($passwordResetGrant),
                        AuditGrantState::fromGrant($expiredGrant),
                        $expiredGrant->getDelivery()->getStatus()
                    ),
                    new AuditCorrelation($commandMessage->id(), null)
                ));

                return new PasswordResetDeliveryExpired(
                    $command->getActorId(),
                    $command->getUserId(),
                    $command->getPasswordResetDeliveryId(),
                    $command->getOccurredAt()
                );
            });

            if ($successEvent instanceof PasswordResetDeliveryExpired) {
                $this->eventDispatcher->trigger($successEvent);
            }
        } catch (Throwable $throwable) {
            try {
                $this->eventDispatcher->trigger(new CommandFailedEvent($command, 'Credential expiry failed.'));
            } catch (Throwable) {
                // Failure notification must not conceal the original persistence or publication failure.
            }

            throw $throwable;
        }
    }
}
