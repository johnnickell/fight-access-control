<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\EmailChangeGrant\CommandHandler;

use Fight\AccessControl\Application\AccessControl\EmailChangeGrant\Service\EmailChangeAdministrationAuthorization;
use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidenceRepository;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Command\CancelEmailChange;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeGrant;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeGrantRepository;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Event\EmailChangeCancelled;
use Fight\AccessControl\Domain\AccessControl\User\Exception\EmailChangeCancellationException;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserRepository;
use Fight\Common\Application\Messaging\Command\CommandHandler;
use Fight\Common\Application\Messaging\Event\EventDispatcher;
use Fight\Common\Application\Repository\UnitOfWork;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use LogicException;
use Throwable;

/**
 * Atomically cancels email-change authority and its reservation.
 */
final readonly class CancelEmailChangeHandler implements CommandHandler
{
    /**
     * Creates the email-change cancellation handler.
     */
    public function __construct(
        private UserRepository $userRepository,
        private EmailChangeGrantRepository $emailChangeGrantRepository,
        private EmailChangeAdministrationAuthorization $emailChangeAdministrationAuthorization,
        private AuditEvidenceRepository $auditEvidenceRepository,
        private UnitOfWork $unitOfWork,
        private Clock $emailChangeClock,
        private EventDispatcher $eventDispatcher
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function commandRegistration(): string
    {
        return CancelEmailChange::class;
    }

    /**
     * @inheritDoc
     */
    public function handle(CommandMessage $commandMessage): void
    {
        /** @var CancelEmailChange $command */
        $command = $commandMessage->payload();

        try {
            $event = $this->unitOfWork->commitTransactional(function () use ($command): EmailChangeCancelled {
                $administrative = !$command->getActorId()->equals($command->getUserId());
                if ($administrative) {
                    $this->emailChangeAdministrationAuthorization->assertCanManageEmailChange(
                        $command->getActorId(),
                        $command->getUserId()
                    );
                }

                $user = $this->userRepository->getById($command->getUserId());
                if (!$user instanceof User) {
                    throw new EmailChangeCancellationException('The active user was not found.');
                }

                $pendingEmailChange = $user->getPendingEmailChange();
                $emailChangeGrant = $this->emailChangeGrantRepository->getLatestByUserId($user->getId());
                if (
                    !$pendingEmailChange instanceof EmailAddress
                    || !$emailChangeGrant instanceof EmailChangeGrant
                    || !$emailChangeGrant->isIssued()
                    || $emailChangeGrant->getDelivery()->getEmail()->canonical() !== $pendingEmailChange->canonical()
                ) {
                    throw new EmailChangeCancellationException('The user has no cancellable email change.');
                }

                $cancelledAt = $this->emailChangeClock->now();
                $replacementUser = clone $user;
                $replacementUser->cancelEmailChange($cancelledAt);
                if (!$this->userRepository->replaceEmailChangeReservation($user, $replacementUser)) {
                    throw new LogicException('The email-change reservation changed concurrently.');
                }

                $revokedGrant = $emailChangeGrant->revoke($cancelledAt);
                if (!$this->emailChangeGrantRepository->replace($emailChangeGrant, $revokedGrant)) {
                    throw new LogicException('Email-change authority changed concurrently.');
                }

                if ($administrative) {
                    $this->auditEvidenceRepository->add(AuditEvidence::record(
                        $command->getActorId()->toString(),
                        'user.email_change_administratively_cancelled',
                        $user->getId()
                    ));
                }

                return new EmailChangeCancelled(
                    $command->getActorId(),
                    $user->getId(),
                    $emailChangeGrant->getId(),
                    $cancelledAt
                );
            });

            $this->eventDispatcher->trigger($event);
        } catch (Throwable $throwable) {
            $this->eventDispatcher->trigger(new CommandFailedEvent($command, $throwable->getMessage()));
            throw $throwable;
        }
    }
}
