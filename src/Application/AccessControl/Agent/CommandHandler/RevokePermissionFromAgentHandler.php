<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\CommandHandler;

use Fight\AccessControl\Application\AccessControl\Agent\Service\AgentPermissionAdministrationAuthorization;
use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentRepository;
use Fight\AccessControl\Domain\AccessControl\Agent\Command\RevokePermissionFromAgent;
use Fight\AccessControl\Domain\AccessControl\Agent\Event\PermissionRevokedFromAgent;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionRepository;
use Fight\Common\Application\Messaging\Command\CommandHandler;
use Fight\Common\Application\Messaging\Event\EventDispatcher;
use Fight\Common\Application\Repository\UnitOfWork;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Throwable;

/**
 * Atomically revokes an authoritative Permission directly from an authorized Agent.
 */
final readonly class RevokePermissionFromAgentHandler implements CommandHandler
{
    /**
     * Creates the direct Agent Permission-revoke handler.
     */
    public function __construct(
        private AgentRepository $agentRepository,
        private PermissionRepository $permissionRepository,
        private AgentPermissionAdministrationAuthorization $agentPermissionAdministrationAuthorization,
        private Clock $clock,
        private UnitOfWork $unitOfWork,
        private EventDispatcher $eventDispatcher
    ) {
    }

    /** @inheritDoc */
    public static function commandRegistration(): string
    {
        return RevokePermissionFromAgent::class;
    }

    /** @inheritDoc */
    public function handle(CommandMessage $commandMessage): void
    {
        /** @var RevokePermissionFromAgent $command */
        $command = $commandMessage->payload();

        try {
            $event = new AgentPermissionAssignmentCoordinator(
                $this->agentRepository,
                $this->permissionRepository,
                $this->agentPermissionAdministrationAuthorization,
                $this->clock,
                $this->unitOfWork
            )->revoke($command->getActorId(), $command->getAgentId(), $command->getPermissionId());
            if ($event instanceof PermissionRevokedFromAgent) {
                $this->eventDispatcher->trigger($event);
            }
        } catch (Throwable $throwable) {
            $this->eventDispatcher->trigger(new CommandFailedEvent($command, $throwable->getMessage()));
            throw $throwable;
        }
    }
}
