<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\QueryHandler;

use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentRepository;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentReadException;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\AgentPermissionView;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\AgentView;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\GetAgentById;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionRepository;
use Fight\Common\Application\Messaging\Query\QueryHandler;
use Fight\Common\Domain\Messaging\Query\QueryMessage;

/**
 * Retrieves one exact secret-free Agent administrative view.
 */
final readonly class GetAgentByIdHandler implements QueryHandler
{
    /**
     * Creates the Agent-identity query handler.
     */
    public function __construct(
        private AgentRepository $agentRepository,
        private PermissionRepository $permissionRepository
    ) {
    }

    /** @inheritDoc */
    public static function queryRegistration(): string
    {
        return GetAgentById::class;
    }

    /** @inheritDoc */
    public function handle(QueryMessage $queryMessage): ?AgentView
    {
        /** @var GetAgentById $query */
        $query = $queryMessage->payload();
        $agent = $this->agentRepository->getById($query->getAgentId());
        if (!$agent instanceof Agent) {
            return null;
        }

        $permissions = $this->permissionRepository->getByIds($agent->getPermissionIds());

        return AgentView::fromAgent($agent, $this->snapshots($agent->getPermissionIds(), $permissions));
    }

    /**
     * Returns snapshots in Agent assignment order only when the bulk result matches exactly.
     *
     * @phpstan-param list<PermissionId> $requestedIds
     * @phpstan-param list<Permission> $permissions
     *
     * @return list<AgentPermissionView>
     */
    private function snapshots(array $requestedIds, array $permissions): array
    {
        if (count($requestedIds) !== count($permissions)) {
            throw new AgentReadException('The Agent Permission snapshot is incomplete.');
        }

        $snapshots = [];
        foreach ($requestedIds as $requestedId) {
            $matches = array_values(array_filter(
                $permissions,
                static fn(Permission $permission): bool => $permission->getId()->equals($requestedId)
            ));
            if (count($matches) !== 1) {
                throw new AgentReadException('The Agent Permission snapshot is mismatched.');
            }

            $snapshots[] = AgentPermissionView::fromPermission($matches[0]);
        }

        return $snapshots;
    }
}
