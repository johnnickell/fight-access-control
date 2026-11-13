<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\PasswordResetGrant\CommandHandler;

use DateInterval;
use Fight\AccessControl\Application\AccessControl\PasswordResetGrant\Service\PasswordResetCredentialGenerator;
use Fight\AccessControl\Application\AccessControl\PasswordResetGrant\Service\PasswordResetDeliveryCipher;
use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Domain\AccessControl\Audit\AnonymousId;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditAction;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditCorrelation;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidenceRepository;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditGrantOutcome;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditGrantState;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditSubject;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\Command\RequestPasswordReset;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\Event\PasswordResetRequested;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrant;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrantRepository;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserRepository;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Application\Error\Exception\StateConflict;
use Fight\Common\Application\Messaging\Command\CommandHandler;
use Fight\Common\Application\Messaging\Event\EventDispatcher;
use Fight\Common\Application\Repository\TransactionalUnitOfWork;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use LogicException;
use Throwable;

/**
 * Class RequestPasswordResetHandler
 *
 * Silently stages password-reset authority and delivery work for an eligible identity.
 */
final readonly class RequestPasswordResetHandler implements CommandHandler
{
    private const string GRANT_LIFETIME = 'PT1H';

    /**
     * Constructs RequestPasswordResetHandler
     *
     * Creates the password-reset request handler.
     */
    public function __construct(
        private UserRepository $userRepository,
        private PasswordResetGrantRepository $passwordResetGrantRepository,
        private TransactionalUnitOfWork $unitOfWork,
        private PasswordResetCredentialGenerator $passwordResetCredentialGenerator,
        private PasswordResetDeliveryCipher $passwordResetDeliveryCipher,
        private Clock $clock,
        private EventDispatcher $eventDispatcher,
        private ?AuditEvidenceRepository $auditEvidenceRepository = null
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
            /** @var ?PasswordResetRequested $successEvent */
            $successEvent = $this->unitOfWork->commitTransactional(function () use (
                $command,
                $commandMessage
            ): ?PasswordResetRequested {
                $user = $this->userRepository->getByEmail($command->getEmail());
                if ($user instanceof User && $user->getEmail()->canonical() !== $command->getEmail()->canonical()) {
                    throw new LogicException('The User repository returned another canonical email.');
                }

                if (!$user instanceof User || $user->getState() !== UserState::ACTIVE) {
                    return null;
                }

                if (!$this->userRepository->validateRecoveryAuthority($user)) {
                    throw new StateConflict('Password-reset authority changed concurrently.');
                }

                $issuedAt = $this->clock->now();
                $predecessorGrant = $this->passwordResetGrantRepository->getLatestByUserId($user->getId());
                if (
                    $predecessorGrant instanceof PasswordResetGrant
                    && !$predecessorGrant->getUserId()->equals($user->getId())
                ) {
                    throw new LogicException("The credential repository returned another User's generation.");
                }

                $credential = $this->passwordResetCredentialGenerator->generate();
                $expiresAt = $issuedAt->add(new DateInterval(self::GRANT_LIFETIME));
                $passwordResetGrant = PasswordResetGrant::issue(
                    $user->getId(),
                    $credential,
                    $issuedAt,
                    $expiresAt,
                    $user->getEmail(),
                    $this->passwordResetDeliveryCipher->encrypt($credential->toString()),
                    $user->getRecoveryAuthority()
                );
                $passwordResetDelivery = $passwordResetGrant->getDelivery();
                $terminalPredecessor = $predecessorGrant;

                if (
                    $predecessorGrant instanceof PasswordResetGrant
                    && ($predecessorGrant->isIssued() || $predecessorGrant->getDelivery()->hasRecoverableMaterial())
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
                        throw new StateConflict('Password-reset authority changed concurrently.');
                    }
                } elseif ($predecessorGrant instanceof PasswordResetGrant) {
                    if (
                        !$this->passwordResetGrantRepository->appendAfterTerminal(
                            $predecessorGrant,
                            $passwordResetGrant
                        )
                    ) {
                        throw new StateConflict('Password-reset authority changed concurrently.');
                    }
                } else {
                    if (!$this->passwordResetGrantRepository->add($passwordResetGrant)) {
                        throw new StateConflict('Password-reset authority changed concurrently.');
                    }
                }

                $this->auditEvidenceRepository?->add(AuditEvidence::record(
                    AnonymousId::generate(),
                    new AuditSubject($user->getId()),
                    AuditAction::PASSWORD_RESET_REQUESTED,
                    $issuedAt,
                    new AuditGrantOutcome(
                        $predecessorGrant === null ? null : AuditGrantState::fromGrant($predecessorGrant),
                        AuditGrantState::fromGrant($passwordResetGrant),
                        replacedPredecessor: $terminalPredecessor === null ? null : AuditGrantState::fromGrant(
                            $terminalPredecessor
                        )
                    ),
                    new AuditCorrelation($commandMessage->id(), null)
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
            try {
                $this->eventDispatcher->trigger(new CommandFailedEvent($command, 'Password reset request failed.'));
            } catch (Throwable) {
                // Notification failure must not replace the original operation failure.
            }

            throw $throwable;
        }
    }
}
