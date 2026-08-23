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
use Fight\Common\Application\Repository\UnitOfWork;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use LogicException;
use Throwable;

/**
 * Processes terminal email-change expiry without owning its invocation mechanism.
 */
final readonly class ExpireEmailChangeHandler implements CommandHandler
{
    /**
     * Creates the email-change expiry handler.
     */
    public function __construct(
        private UserRepository $userRepository,
        private EmailChangeGrantRepository $emailChangeGrantRepository,
        private UnitOfWork $unitOfWork,
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
                $user = $this->userRepository->getById($command->getUserId());
                $emailChangeGrant = $this->emailChangeGrantRepository->getLatestByUserId($command->getUserId());
                $pendingEmailChange = $user?->getPendingEmailChange()?->canonical();
                if (
                    !$user instanceof User
                    || !$emailChangeGrant instanceof EmailChangeGrant
                    || !$emailChangeGrant->getId()->equals($command->getEmailChangeGrantId())
                    || !$emailChangeGrant->getUserId()->equals($command->getUserId())
                    || $pendingEmailChange !== $emailChangeGrant->getDelivery()->getEmail()->canonical()
                ) {
                    return null;
                }

                $expiredGrant = $emailChangeGrant->expireAt($command->getOccurredAt());
                if ($expiredGrant === $emailChangeGrant) {
                    return null;
                }

                $expiredUser = clone $user;
                $expiredUser->expireEmailChange();
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
            $this->eventDispatcher->trigger(new CommandFailedEvent($command, $throwable->getMessage()));
            throw $throwable;
        }
    }
}
