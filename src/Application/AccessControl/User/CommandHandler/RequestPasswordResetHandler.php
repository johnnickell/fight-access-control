<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\User\CommandHandler;

use DateInterval;
use Fight\AccessControl\Application\AccessControl\User\Service\PasswordResetClock;
use Fight\AccessControl\Application\AccessControl\User\Service\PasswordResetCredentialGenerator;
use Fight\AccessControl\Application\AccessControl\User\Service\PasswordResetDeliveryCipher;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidenceRepository;
use Fight\AccessControl\Domain\AccessControl\User\Command\RequestPasswordReset;
use Fight\AccessControl\Domain\AccessControl\User\Event\PasswordResetRequested;
use Fight\AccessControl\Domain\AccessControl\User\PasswordResetDelivery;
use Fight\AccessControl\Domain\AccessControl\User\PasswordResetDeliveryId;
use Fight\AccessControl\Domain\AccessControl\User\PasswordResetDeliveryRepository;
use Fight\AccessControl\Domain\AccessControl\User\PasswordResetGrant;
use Fight\AccessControl\Domain\AccessControl\User\PasswordResetGrantRepository;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserRepository;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Application\Messaging\Command\CommandHandler;
use Fight\Common\Application\Messaging\Event\EventDispatcher;
use Fight\Common\Application\Repository\UnitOfWork;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use LogicException;
use Throwable;

/**
 * Silently stages password-reset authority and delivery work for an eligible identity.
 */
final readonly class RequestPasswordResetHandler implements CommandHandler
{
    private const string GRANT_LIFETIME = 'PT1H';

    /**
     * Creates the password-reset request handler.
     */
    public function __construct(
        private UserRepository $userRepository,
        private PasswordResetGrantRepository $passwordResetGrantRepository,
        private PasswordResetDeliveryRepository $passwordResetDeliveryRepository,
        private AuditEvidenceRepository $auditEvidenceRepository,
        private UnitOfWork $unitOfWork,
        private PasswordResetCredentialGenerator $passwordResetCredentialGenerator,
        private PasswordResetDeliveryCipher $passwordResetDeliveryCipher,
        private PasswordResetClock $passwordResetClock,
        private EventDispatcher $eventDispatcher
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function commandRegistration(): string
    {
        return RequestPasswordReset::class;
    }

    /**
     * @inheritDoc
     */
    public function handle(CommandMessage $commandMessage): void
    {
        /** @var RequestPasswordReset $command */
        $command = $commandMessage->payload();

        try {
            $successEvent = $this->unitOfWork->commitTransactional(function () use (
                $command
            ): ?PasswordResetRequested {
                $user = $this->userRepository->getByEmail($command->getEmail());
                if (!$user instanceof User || $user->getState() !== UserState::ACTIVE) {
                    return null;
                }

                $issuedAt = $this->passwordResetClock->now();
                $predecessorGrant = $this->passwordResetGrantRepository->getByUserId($user->getId());
                $predecessorDelivery = $this->passwordResetDeliveryRepository->getByUserId($user->getId());
                $credential = $this->passwordResetCredentialGenerator->generate();
                $passwordResetGrant = PasswordResetGrant::issue(
                    $user->getId(),
                    $credential,
                    $issuedAt,
                    $issuedAt->add(new DateInterval(self::GRANT_LIFETIME))
                );
                $passwordResetDelivery = PasswordResetDelivery::create(
                    PasswordResetDeliveryId::generate(),
                    $user->getId(),
                    $user->getEmail()->canonical(),
                    $this->passwordResetDeliveryCipher->encrypt($credential->toString()),
                    $passwordResetGrant->getExpiresAt()
                );

                if ($predecessorGrant instanceof PasswordResetGrant && $predecessorGrant->isIssued()) {
                    if (
                        !$this->passwordResetGrantRepository->replace(
                            $predecessorGrant,
                            $predecessorGrant->revoke($issuedAt),
                            $passwordResetGrant
                        )
                    ) {
                        throw new LogicException('Password-reset authority changed concurrently.');
                    }
                } elseif ($predecessorGrant instanceof PasswordResetGrant) {
                    if (
                        !$this->passwordResetGrantRepository->appendAfterTerminal(
                            $predecessorGrant,
                            $passwordResetGrant
                        )
                    ) {
                        throw new LogicException('Password-reset authority changed concurrently.');
                    }
                } else {
                    if (!$this->passwordResetGrantRepository->add($passwordResetGrant)) {
                        throw new LogicException('Password-reset authority changed concurrently.');
                    }
                }

                if ($predecessorDelivery instanceof PasswordResetDelivery && $predecessorDelivery->isRecoverable()) {
                    if (
                        !$this->passwordResetDeliveryRepository->replace(
                            $predecessorDelivery,
                            $predecessorDelivery->invalidate(),
                            $passwordResetDelivery
                        )
                    ) {
                        throw new LogicException('Password-reset delivery changed concurrently.');
                    }
                } elseif ($predecessorDelivery instanceof PasswordResetDelivery) {
                    if (
                        !$this->passwordResetDeliveryRepository->appendAfterTerminal(
                            $predecessorDelivery,
                            $passwordResetDelivery
                        )
                    ) {
                        throw new LogicException('Password-reset delivery changed concurrently.');
                    }
                } else {
                    if (!$this->passwordResetDeliveryRepository->add($passwordResetDelivery)) {
                        throw new LogicException('Password-reset delivery changed concurrently.');
                    }
                }

                $this->auditEvidenceRepository->add(AuditEvidence::record(
                    'anonymous',
                    'user.password_reset_requested',
                    $user->getId()
                ));

                return new PasswordResetRequested(
                    $user->getId(),
                    $passwordResetDelivery->getId(),
                    $issuedAt
                );
            });

            if ($successEvent instanceof PasswordResetRequested) {
                $this->eventDispatcher->trigger($successEvent);
            }
        } catch (Throwable $throwable) {
            $this->eventDispatcher->trigger(new CommandFailedEvent($command, $throwable->getMessage()));
            throw $throwable;
        }
    }
}
