<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\User\CommandHandler;

use DateInterval;
use Fight\AccessControl\Application\AccessControl\User\ActivationCredentialGenerator;
use Fight\AccessControl\Application\AccessControl\User\ActivationDeliveryCipher;
use Fight\AccessControl\Application\AccessControl\User\ActivationDeliveryWork;
use Fight\AccessControl\Application\AccessControl\User\ActivationDeliveryWorkStore;
use Fight\AccessControl\Application\AccessControl\User\ActivationGrantStore;
use Fight\AccessControl\Application\AccessControl\User\AuditEvidence;
use Fight\AccessControl\Application\AccessControl\User\AuditEvidenceStore;
use Fight\AccessControl\Application\AccessControl\User\DuplicateEmail;
use Fight\AccessControl\Application\AccessControl\User\InvitationClock;
use Fight\AccessControl\Application\AccessControl\User\UserStore;
use Fight\AccessControl\Domain\AccessControl\User\ActivationGrant;
use Fight\AccessControl\Domain\AccessControl\User\Command\InvitePendingUser;
use Fight\AccessControl\Domain\AccessControl\User\Event\UserInvited;
use Fight\AccessControl\Domain\AccessControl\User\User;
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
        private UserStore $users,
        private ActivationGrantStore $grants,
        private ActivationDeliveryWorkStore $deliveries,
        private AuditEvidenceStore $audits,
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
                if ($this->users->reserve($user) === false) {
                    throw new DuplicateEmail('The email address is already reserved.');
                }

                $credential = $this->credentials->generate();
                $grant = ActivationGrant::issue(
                    $user->getId(),
                    $credential,
                    $issuedAt,
                    $issuedAt->add(new DateInterval('P7D'))
                );
                $delivery = new ActivationDeliveryWork(
                    $grant->getUserId(),
                    $user->getEmail()->toString(),
                    $this->cipher->encrypt($credential),
                    $grant->getExpiresAt()
                );
                $this->grants->save($grant);
                $this->deliveries->save($delivery);
                $this->audits->save(new AuditEvidence($command->getActorId(), 'user.invited', $user->getId()));
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
