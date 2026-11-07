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
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Exception\EmailChangeAdministrationAuthorizationException;
use Fight\AccessControl\Domain\AccessControl\User\Exception\EmailChangeCancellationException;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserRepository;
use Fight\Common\Application\Error\Exception\PermissionDenied;
use Fight\Common\Application\Error\Exception\ResourceNotFound;
use Fight\Common\Application\Error\Exception\StateConflict;
use Fight\Common\Application\Messaging\Command\CommandHandler;
use Fight\Common\Application\Messaging\Event\EventDispatcher;
use Fight\Common\Application\Repository\TransactionalUnitOfWork;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use LogicException;
use Throwable;

/**
 * Class CancelEmailChangeHandler
 *
 * Atomically cancels email-change authority and its reservation.
 */
final readonly class CancelEmailChangeHandler implements CommandHandler
{
    /**
     * Constructs CancelEmailChangeHandler
     *
     * Creates the email-change cancellation handler.
     */
    public function __construct(
        private UserRepository $userRepository,
        private EmailChangeGrantRepository $emailChangeGrantRepository,
        private EmailChangeAdministrationAuthorization $emailChangeAdministrationAuthorization,
        private AuditEvidenceRepository $auditEvidenceRepository,
        private TransactionalUnitOfWork $unitOfWork,
        private Clock $clock,
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
            /** @var EmailChangeCancelled $event */
            $event = $this->unitOfWork->commitTransactional(function () use ($command): EmailChangeCancelled {
                $administrative = !$command->getActorId()->equals($command->getUserId());
                if ($administrative) {
                    try {
                        $this->emailChangeAdministrationAuthorization->assertCanManageEmailChange(
                            $command->getActorId(),
                            $command->getUserId()
                        );
                    } catch (EmailChangeAdministrationAuthorizationException $exception) {
                        throw new PermissionDenied('Email change administration denied.', previous: $exception);
                    }
                }

                $user = $this->userRepository->getById($command->getUserId());
                if ($user instanceof User && !$user->getId()->equals($command->getUserId())) {
                    throw new LogicException('The User repository returned another identity.');
                }

                if (!$user instanceof User) {
                    throw new ResourceNotFound('The active user was not found.');
                }

                $pendingEmailChange = $user->getPendingEmailChange();
                $emailChangeGrant = $this->emailChangeGrantRepository->getLatestByUserId($user->getId());
                if (
                    $emailChangeGrant instanceof EmailChangeGrant
                    && !$emailChangeGrant->getUserId()->equals($user->getId())
                ) {
                    throw new LogicException("The credential repository returned another User's generation.");
                }

                if (
                    !$pendingEmailChange instanceof EmailAddress
                    || !$emailChangeGrant instanceof EmailChangeGrant
                    || !$emailChangeGrant->isIssued()
                ) {
                    throw new StateConflict('The user has no cancellable email change.');
                }

                if (
                    !$emailChangeGrant->matchesReservation(
                        $user->getId(),
                        $pendingEmailChange,
                        $user->getEmailChangeReservationRevision()
                    )
                ) {
                    throw new LogicException('The email-change authority has no matching reservation.');
                }

                $cancelledAt = $this->clock->now();
                $replacementUser = clone $user;
                try {
                    $replacementUser->cancelEmailChange($cancelledAt);
                } catch (EmailChangeCancellationException $emailChangeCancellationException) {
                    throw new StateConflict(
                        'The email change cannot be cancelled.',
                        previous: $emailChangeCancellationException
                    );
                }

                if (!$this->userRepository->replaceEmailChangeReservation($user, $replacementUser)) {
                    throw new StateConflict('The email-change reservation changed concurrently.');
                }

                $revokedGrant = $emailChangeGrant->revoke($cancelledAt);
                if (!$this->emailChangeGrantRepository->replace($emailChangeGrant, $revokedGrant)) {
                    throw new StateConflict('Email-change authority changed concurrently.');
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
            try {
                $this->eventDispatcher->trigger(new CommandFailedEvent($command, 'Email change cancellation failed.'));
            } catch (Throwable) {
                // Notification failure must not replace the original operation failure.
            }

            throw $throwable;
        }
    }
}
