<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\CommandHandler;

use Fight\AccessControl\Application\AccessControl\Agent\Service\AgentPermissionAdministrationAuthorization;
use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentRepository;
use Fight\AccessControl\Domain\AccessControl\Agent\Command\ReplaceAgentPermissions;
use Fight\AccessControl\Domain\AccessControl\Agent\Event\AgentPermissionsReplaced;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentPermissionAssignmentException;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionRepository;
use Fight\Common\Application\Messaging\Command\CommandHandler;
use Fight\Common\Application\Messaging\Event\EventDispatcher;
use Fight\Common\Application\Repository\UnitOfWork;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Throwable;

/**
 * Atomically replaces an authorized Agent's complete authoritative direct-Permission set.
 */
final readonly class ReplaceAgentPermissionsHandler implements CommandHandler
{
    /**
     * Creates the complete Agent Permission replacement handler.
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
        return ReplaceAgentPermissions::class;
    }

    /** @inheritDoc */
    public function handle(CommandMessage $commandMessage): void
    {
        /** @var ReplaceAgentPermissions $command */
        $command = $commandMessage->payload();

        try {
            $event = $this->unitOfWork->commitTransactional(
                function () use ($command): AgentPermissionsReplaced {
                    $this->agentPermissionAdministrationAuthorization->assertCanManageAgentPermissions(
                        $command->getActorId()
                    );

                    $agent = $this->agentRepository->getById($command->getAgentId());
                    if (!$agent instanceof Agent) {
                        throw new AgentPermissionAssignmentException('The Agent does not exist.');
                    }

                    $replacedAt = $this->clock->now();
                    $replacement = $agent->replacePermissions(
                        $command->getPermissionIds(),
                        $command->getExpectedPermissionAssignmentRevision(),
                        $replacedAt
                    );
                    $permissions = $this->permissionRepository->getByIds($command->getPermissionIds());
                    if (!$this->permissionsMatch($command->getPermissionIds(), $permissions)) {
                        throw new AgentPermissionAssignmentException(
                            'The complete Agent Permission assignment set is not authoritative.'
                        );
                    }

                    if (
                        $replacement !== $agent
                        && !$this->agentRepository->replacePermissionAssignments($agent, $replacement)
                    ) {
                        throw new AgentPermissionAssignmentException(
                            'The Agent Permission assignments or authoritative Permissions changed concurrently.'
                        );
                    }

                    return new AgentPermissionsReplaced(
                        $command->getActorId(),
                        $command->getAgentId(),
                        $replacement->getPermissionIds(),
                        $replacement->getPermissionAssignmentRevision(),
                        $replacedAt
                    );
                }
            );

            $this->eventDispatcher->trigger($event);
        } catch (Throwable $throwable) {
            $this->eventDispatcher->trigger(new CommandFailedEvent($command, $throwable->getMessage()));
            throw $throwable;
        }
    }

    /**
     * Returns whether the bulk result resolves exactly the requested identities.
     *
     * @phpstan-param list<PermissionId> $requestedIds
     * @phpstan-param list<Permission> $permissions
     */
    private function permissionsMatch(array $requestedIds, array $permissions): bool
    {
        if (count($requestedIds) !== count($permissions)) {
            return false;
        }

        return array_all(
            $requestedIds,
            static fn(PermissionId $requested): bool => array_any(
                $permissions,
                static fn(Permission $permission): bool => $permission->getId()->equals($requested)
            )
        );
    }
}
