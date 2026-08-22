<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\PasswordResetGrant\CommandHandler;

use DateInterval;
use Fight\AccessControl\Application\AccessControl\PasswordResetGrant\Service\PasswordResetClock;
use Fight\AccessControl\Application\AccessControl\PasswordResetGrant\Service\PasswordResetCredentialGenerator;
use Fight\AccessControl\Application\AccessControl\PasswordResetGrant\Service\PasswordResetDeliveryCipher;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidenceRepository;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\Command\RequestPasswordReset;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\Event\PasswordResetRequested;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrant;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrantRepository;
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
                $predecessorGrant = $this->passwordResetGrantRepository->getLatestByUserId($user->getId());
                $credential = $this->passwordResetCredentialGenerator->generate();
                $expiresAt = $issuedAt->add(new DateInterval(self::GRANT_LIFETIME));
                $passwordResetGrant = PasswordResetGrant::issue(
                    $user->getId(),
                    $credential,
                    $issuedAt,
                    $expiresAt,
                    $user->getEmail(),
                    $this->passwordResetDeliveryCipher->encrypt($credential->toString())
                );
                $passwordResetDelivery = $passwordResetGrant->getDelivery();

                if (
                    $predecessorGrant instanceof PasswordResetGrant
                    && ($predecessorGrant->isIssued() || $predecessorGrant->getDelivery()->isRecoverable())
                ) {
                    $terminalPredecessor = $predecessorGrant->invalidateDelivery();
                    if ($predecessorGrant->isIssued()) {
                        $terminalPredecessor = $predecessorGrant->revoke($issuedAt);
                    }

                    if (
                        !$this->passwordResetGrantRepository->replaceWithSuccessor(
                            $predecessorGrant,
                            $terminalPredecessor,
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
