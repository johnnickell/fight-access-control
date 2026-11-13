<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\CommandHandler;

use Fight\AccessControl\Application\AccessControl\Agent\PermissionAssignment\AgentPermissionAssignmentCoordinator;
use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentRepository;
use Fight\AccessControl\Domain\AccessControl\Agent\Command\ReplaceAgentPermissions;
use Fight\AccessControl\Domain\AccessControl\Agent\Event\AgentPermissionsReplaced;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditAction;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditCorrelation;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidenceRepository;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditSubject;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionRepository;
use Fight\Common\Application\Messaging\Command\CommandHandler;
use Fight\Common\Application\Messaging\Event\EventDispatcher;
use Fight\Common\Application\Repository\TransactionalUnitOfWork;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Throwable;

/**
 * Class ReplaceAgentPermissionsHandler
 *
 * Atomically replaces an Agent's complete eligible direct-Permission set.
 */
final readonly class ReplaceAgentPermissionsHandler implements CommandHandler
{
    /**
     * Constructs ReplaceAgentPermissionsHandler
     *
     * Creates the complete Agent Permission replacement handler.
     */
    public function __construct(
        private AgentRepository $agentRepository,
        private PermissionRepository $permissionRepository,
        private Clock $clock,
        private TransactionalUnitOfWork $unitOfWork,
        private EventDispatcher $eventDispatcher,
        private ?AuditEvidenceRepository $auditEvidenceRepository = null
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function commandRegistration(): string
    {
        return ReplaceAgentPermissions::class;
    }

    /**
     * @inheritDoc
     */
    public function handle(CommandMessage $commandMessage): void
    {
        /** @var ReplaceAgentPermissions $command */
        $command = $commandMessage->payload();

        $messageId = $commandMessage->id();

        try {
            /** @var AgentPermissionsReplaced|null $event */
            $event = $this->unitOfWork->commitTransactional(
                function () use ($command, $messageId): ?AgentPermissionsReplaced {
                    $outcome = new AgentPermissionAssignmentCoordinator(
                        $this->agentRepository,
                        $this->permissionRepository,
                        $this->clock
                    )->replace(
                        $command->getActorId(),
                        $command->getAgentId(),
                        $command->getExpectedPermissionAssignmentRevision(),
                        $command->getPermissionIds()
                    );
                    if ($outcome === null) {
                        return null;
                    }

                    [$event, $facts, $occurredAt] = $outcome;
                    if ($this->auditEvidenceRepository !== null) {
                        $this->auditEvidenceRepository->add(AuditEvidence::record(
                            $command->getActorId(),
                            new AuditSubject($command->getAgentId()),
                            AuditAction::AGENT_PERMISSIONS_REPLACED,
                            $occurredAt,
                            $facts,
                            new AuditCorrelation($messageId, null)
                        ));
                    }

                    return $event;
                }
            );

            if ($event !== null) {
                $this->eventDispatcher->trigger($event);
            }
        } catch (Throwable $throwable) {
            try {
                $this->eventDispatcher->trigger(new CommandFailedEvent(
                    $command,
                    'Agent permission replacement failed.'
                ));
            } catch (Throwable) {
                // Notification failure must not replace the original operation failure.
            }

            throw $throwable;
        }
    }
}
