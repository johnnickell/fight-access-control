<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\EmailChangeGrant\CommandHandler;

use DateInterval;
use Fight\AccessControl\Application\AccessControl\EmailChangeGrant\Service\EmailChangeAdministrationAuthorization;
use Fight\AccessControl\Application\AccessControl\EmailChangeGrant\Service\EmailChangeCredentialGenerator;
use Fight\AccessControl\Application\AccessControl\EmailChangeGrant\Service\EmailChangeDeliveryCipher;
use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidenceRepository;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Command\RequestEmailChange;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeGrant;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeGrantRepository;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Event\EmailChangeRequested;
use Fight\AccessControl\Domain\AccessControl\User\Exception\EmailChangeRequestException;
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
 * Atomically reserves a destination and records mailbox-confirmation work.
 */
final readonly class RequestEmailChangeHandler implements CommandHandler
{
    private const string GRANT_LIFETIME = 'PT1H';

    /**
     * Creates the email-change request handler.
     */
    public function __construct(
        private UserRepository $userRepository,
        private EmailChangeGrantRepository $emailChangeGrantRepository,
        private EmailChangeAdministrationAuthorization $emailChangeAdministrationAuthorization,
        private AuditEvidenceRepository $auditEvidenceRepository,
        private UnitOfWork $unitOfWork,
        private EmailChangeCredentialGenerator $emailChangeCredentialGenerator,
        private EmailChangeDeliveryCipher $emailChangeDeliveryCipher,
        private Clock $clock,
        private EventDispatcher $eventDispatcher
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function commandRegistration(): string
    {
        return RequestEmailChange::class;
    }

    /**
     * @inheritDoc
     */
    public function handle(CommandMessage $commandMessage): void
    {
        /** @var RequestEmailChange $command */
        $command = $commandMessage->payload();

        try {
            $event = $this->unitOfWork->commitTransactional(function () use ($command): EmailChangeRequested {
                $administrative = !$command->getActorId()->equals($command->getUserId());
                if ($administrative) {
                    $this->emailChangeAdministrationAuthorization->assertCanManageEmailChange(
                        $command->getActorId(),
                        $command->getUserId()
                    );
                }

                $user = $this->userRepository->getById($command->getUserId());
                if (!$user instanceof User) {
                    throw new EmailChangeRequestException('The active user was not found.');
                }

                $replacement = clone $user;
                $replacement->requestEmailChange($command->getEmail(), $this->clock->now());
                if (!$this->userRepository->replaceEmailChangeReservation($user, $replacement)) {
                    throw new LogicException('The email-change destination is unavailable.');
                }

                $issuedAt = $this->clock->now();
                $predecessor = $this->emailChangeGrantRepository->getLatestByUserId($user->getId());
                $credential = $this->emailChangeCredentialGenerator->generate();
                $grant = EmailChangeGrant::issue(
                    $user->getId(),
                    $credential,
                    $issuedAt,
                    $issuedAt->add(new DateInterval(self::GRANT_LIFETIME)),
                    $command->getEmail(),
                    $this->emailChangeDeliveryCipher->encrypt($credential->toString())
                );
                if ($predecessor instanceof EmailChangeGrant) {
                    if (!$this->emailChangeGrantRepository->appendAfterTerminal($predecessor, $grant)) {
                        throw new LogicException('Email-change authority changed concurrently.');
                    }
                } elseif (!$this->emailChangeGrantRepository->add($grant)) {
                    throw new LogicException('Email-change authority changed concurrently.');
                }

                if ($administrative) {
                    $this->auditEvidenceRepository->add(AuditEvidence::record(
                        $command->getActorId()->toString(),
                        'user.email_change_administratively_requested',
                        $user->getId()
                    ));
                }

                return new EmailChangeRequested(
                    $command->getActorId(),
                    $user->getId(),
                    $grant->getDelivery()->getId(),
                    $command->getEmail(),
                    $issuedAt
                );
            });

            $this->eventDispatcher->trigger($event);
        } catch (Throwable $throwable) {
            $this->eventDispatcher->trigger(new CommandFailedEvent($command, $throwable->getMessage()));
            throw $throwable;
        }
    }
}
