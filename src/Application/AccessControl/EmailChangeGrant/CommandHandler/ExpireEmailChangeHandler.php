<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\EmailChangeGrant\CommandHandler;

use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Command\ExpireEmailChange;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeGrant;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeGrantRepository;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Event\EmailChangeExpired;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserRepository;
use Fight\Common\Application\Messaging\Command\CommandHandler;
use Fight\Common\Application\Messaging\Event\EventDispatcher;
use Fight\Common\Application\Repository\TransactionalUnitOfWork;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use LogicException;
use Throwable;

/**
 * Class ExpireEmailChangeHandler
 *
 * Processes terminal email-change expiry without owning its invocation mechanism.
 */
final readonly class ExpireEmailChangeHandler implements CommandHandler
{
    /**
     * Constructs ExpireEmailChangeHandler
     *
     * Creates the email-change expiry handler.
     */
    public function __construct(
        private UserRepository $userRepository,
        private EmailChangeGrantRepository $emailChangeGrantRepository,
        private TransactionalUnitOfWork $unitOfWork,
        private EventDispatcher $eventDispatcher
    ) {
    }

    /** @inheritDoc */
    public static function commandRegistration(): string
    {
        return ExpireEmailChange::class;
    }

    /** @inheritDoc */
    public function handle(CommandMessage $commandMessage): void
    {
        /** @var ExpireEmailChange $command */
        $command = $commandMessage->payload();

        try {
            $event = $this->unitOfWork->commitTransactional(function () use ($command): ?EmailChangeExpired {
                $emailChangeGrant = $this->emailChangeGrantRepository->getLatestByUserId($command->getUserId());
                if (
                    !$emailChangeGrant instanceof EmailChangeGrant
                    || !$emailChangeGrant->getId()->equals($command->getEmailChangeGrantId())
                    || !$emailChangeGrant->isIssued()
                    || $command->getOccurredAt() < $emailChangeGrant->getExpiresAt()
                ) {
                    return null;
                }

                $user = $this->userRepository->getById($command->getUserId());
                if (
                    !$user instanceof User
                    || !$user->getId()->equals($command->getUserId())
                    || !$emailChangeGrant->matchesReservation(
                        $user->getId(),
                        $user->getPendingEmailChange(),
                        $user->getEmailChangeReservationRevision()
                    )
                ) {
                    throw new LogicException('The expired email-change authority has no matching reservation.');
                }

                $expiredGrant = $emailChangeGrant->expireAt($command->getOccurredAt());

                $expiredUser = clone $user;
                $expiredUser->expireEmailChange($command->getOccurredAt());
                if (!$this->userRepository->replaceEmailChangeReservation($user, $expiredUser)) {
                    throw new LogicException('The email-change reservation changed concurrently.');
                }

                if (!$this->emailChangeGrantRepository->replace($emailChangeGrant, $expiredGrant)) {
                    throw new LogicException('Email-change authority changed concurrently.');
                }

                return new EmailChangeExpired(
                    $command->getActorId(),
                    $command->getUserId(),
                    $command->getEmailChangeGrantId(),
                    $command->getOccurredAt()
                );
            });

            if ($event instanceof EmailChangeExpired) {
                $this->eventDispatcher->trigger($event);
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
