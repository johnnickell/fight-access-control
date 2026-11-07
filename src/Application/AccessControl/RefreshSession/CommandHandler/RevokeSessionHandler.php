<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\RefreshSession\CommandHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\RefreshSession\Service\SessionAdministrationAuthorization;
use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidenceRepository;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\Command\RevokeSession;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\Event\RefreshSessionRevoked;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\Exception\SessionAdministrationAuthorizationException;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSession;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionId;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionRepository;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Application\Error\Exception\InvalidInput;
use Fight\Common\Application\Error\Exception\PermissionDenied;
use Fight\Common\Application\Error\Exception\ResourceNotFound;
use Fight\Common\Application\Error\Exception\RuleViolation;
use Fight\Common\Application\Error\Exception\StateConflict;
use Fight\Common\Application\Messaging\Command\CommandHandler;
use Fight\Common\Application\Messaging\Event\EventDispatcher;
use Fight\Common\Application\Repository\TransactionalUnitOfWork;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use LogicException;
use Throwable;

/**
 * Class RevokeSessionHandler
 *
 * Atomically revokes a usable refresh session through self-service or authorized administration.
 */
final readonly class RevokeSessionHandler implements CommandHandler
{
    /**
     * Constructs RevokeSessionHandler
     *
     * Creates the session-revocation handler.
     */
    public function __construct(
        private RefreshSessionRepository $refreshSessionRepository,
        private Clock $clock,
        private SessionAdministrationAuthorization $sessionAdministrationAuthorization,
        private AuditEvidenceRepository $auditEvidenceRepository,
        private TransactionalUnitOfWork $unitOfWork,
        private EventDispatcher $eventDispatcher
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function commandRegistration(): string
    {
        return RevokeSession::class;
    }

    /**
     * @inheritDoc
     */
    public function handle(CommandMessage $commandMessage): void
    {
        /** @var RevokeSession $command */
        $command = $commandMessage->payload();

        try {
            /** @var array{UserId, UserId, RefreshSessionId, DateTimeImmutable} $outcome */
            $outcome = $this->unitOfWork->commitTransactional(function () use ($command): array {
                $revokedAt = $this->clock->now();
                $refreshSession = $this->refreshSessionRepository->getById($command->getTargetSessionId());
                if ($refreshSession !== null && !$refreshSession->getId()->equals($command->getTargetSessionId())) {
                    throw new LogicException('The session lookup returned a different target identity.');
                }

                if (!$refreshSession instanceof RefreshSession || !$refreshSession->isUsableAt($revokedAt)) {
                    throw new ResourceNotFound('The refresh session is not authoritative.');
                }

                if ($refreshSession->getId()->equals($command->getCurrentSessionId())) {
                    throw new RuleViolation(
                        'The current refresh session cannot be revoked through self-service session management.'
                    );
                }

                $administrative = !$refreshSession->getUserId()->equals($command->getActorId());
                $reason = null;
                if ($administrative) {
                    try {
                        $this->sessionAdministrationAuthorization->assertCanManageSessions(
                            $command->getActorId(),
                            $refreshSession->getUserId()
                        );
                    } catch (SessionAdministrationAuthorizationException) {
                        throw new PermissionDenied('Session administration rejected.');
                    }

                    $reason = $command->getReason();
                    if ($reason === null) {
                        throw new InvalidInput(
                            'Administrative session revocation requires a reason.'
                        );
                    }
                }

                $revokedSession = $refreshSession->revoke();
                if (!$this->refreshSessionRepository->replace($refreshSession, $revokedSession)) {
                    throw new StateConflict('The refresh session changed concurrently.');
                }

                if ($reason !== null) {
                    $this->auditEvidenceRepository->add(AuditEvidence::administrativeSessionRevocation(
                        $command->getActorId(),
                        $revokedSession->getUserId(),
                        $revokedSession->getId(),
                        $reason
                    ));
                }

                return [
                    $command->getActorId(),
                    $revokedSession->getUserId(),
                    $revokedSession->getId(),
                    $revokedAt
                ];
            });

            $this->eventDispatcher->trigger(new RefreshSessionRevoked(...$outcome));
        } catch (Throwable $throwable) {
            try {
                $this->eventDispatcher->trigger(new CommandFailedEvent($command, 'Session revocation failed.'));
            } catch (Throwable) {
                // Notification failure must not replace the original operation failure.
            }

            throw $throwable;
        }
    }
}
