<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\CommandHandler;

use Fight\AccessControl\Application\AccessControl\Agent\Service\AgentPermissionAdministrationAuthorization;
use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentRepository;
use Fight\AccessControl\Domain\AccessControl\Agent\Command\GrantPermissionToAgent;
use Fight\AccessControl\Domain\AccessControl\Agent\Event\PermissionGrantedToAgent;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentPermissionAssignmentException;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionRepository;
use Fight\Common\Application\Messaging\Command\CommandHandler;
use Fight\Common\Application\Messaging\Event\EventDispatcher;
use Fight\Common\Application\Repository\UnitOfWork;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Throwable;

/**
 * Atomically grants an authoritative Permission directly to an authorized Agent.
 */
final readonly class GrantPermissionToAgentHandler implements CommandHandler
{
    /**
     * Creates the direct Agent Permission-grant handler.
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
        return GrantPermissionToAgent::class;
    }

    /** @inheritDoc */
    public function handle(CommandMessage $commandMessage): void
    {
        /** @var GrantPermissionToAgent $command */
        $command = $commandMessage->payload();

        try {
            $event = $this->unitOfWork->commitTransactional(
                function () use ($command): PermissionGrantedToAgent {
                    $this->agentPermissionAdministrationAuthorization->assertCanManageAgentPermissions(
                        $command->getActorId()
                    );

                    $agent = $this->agentRepository->getById($command->getAgentId());
                    if (!$agent instanceof Agent) {
                        throw new AgentPermissionAssignmentException('The Agent does not exist.');
                    }

                    if (!$this->permissionRepository->getById($command->getPermissionId()) instanceof Permission) {
                        throw new AgentPermissionAssignmentException('The Permission does not exist.');
                    }

                    $grantedAt = $this->clock->now();
                    $replacement = $agent->grantPermission($command->getPermissionId(), $grantedAt);
                    if (!$this->agentRepository->replacePermissionAssignments($agent, $replacement)) {
                        throw new AgentPermissionAssignmentException(
                            'The Agent Permission assignments or authoritative Permissions changed concurrently.'
                        );
                    }

                    return new PermissionGrantedToAgent(
                        $command->getActorId(),
                        $command->getAgentId(),
                        $command->getPermissionId(),
                        $grantedAt
                    );
                }
            );

            $this->eventDispatcher->trigger($event);
        } catch (Throwable $throwable) {
            $this->eventDispatcher->trigger(new CommandFailedEvent($command, $throwable->getMessage()));
            throw $throwable;
        }
    }
}
