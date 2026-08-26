<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\QueryHandler;

use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentRepository;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentReadException;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\AgentPermissionView;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\AgentView;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\ListAgents;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionRepository;
use Fight\Common\Application\Messaging\Query\QueryHandler;
use Fight\Common\Domain\Collection\ArrayList;
use Fight\Common\Domain\Messaging\Query\QueryMessage;
use Fight\Common\Domain\Repository\ResultSet;

/**
 * Retrieves one page of exact secret-free Agent administrative views.
 */
final readonly class ListAgentsHandler implements QueryHandler
{
    /**
     * Creates the Agent-listing query handler.
     */
    public function __construct(
        private AgentRepository $agentRepository,
        private PermissionRepository $permissionRepository
    ) {
    }

    /** @inheritDoc */
    public static function queryRegistration(): string
    {
        return ListAgents::class;
    }

    /** @inheritDoc */
    public function handle(QueryMessage $queryMessage): ResultSet
    {
        /** @var ListAgents $query */
        $query = $queryMessage->payload();
        $agents = $this->agentRepository->getAll($query->getPagination());
        $agentRecords = $agents->records()->toArray();
        $permissionIds = $this->uniquePermissionIds($agentRecords);
        $permissions = $this->permissionRepository->getByIds($permissionIds);
        $this->assertExactPermissions($permissionIds, $permissions);
        $views = ArrayList::of(AgentView::class);
        foreach ($agentRecords as $agent) {
            $views->add(AgentView::fromAgent($agent, $this->snapshots($agent, $permissions)));
        }

        return new ResultSet(
            $agents->page(),
            $agents->perPage(),
            $agents->totalRecords(),
            $views
        );
    }

    /**
     * Returns unique Permission identities in first-assignment order.
     *
     * @phpstan-param list<Agent> $agents
     *
     * @return list<PermissionId>
     */
    private function uniquePermissionIds(array $agents): array
    {
        $ids = [];
        $seen = [];
        foreach ($agents as $agent) {
            foreach ($agent->getPermissionIds() as $id) {
                if (isset($seen[$id->toString()])) {
                    continue;
                }

                $seen[$id->toString()] = true;
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Rejects a bulk result that is not an exact identity match.
     *
     * @phpstan-param list<PermissionId> $ids
     * @phpstan-param list<Permission> $permissions
     */
    private function assertExactPermissions(array $ids, array $permissions): void
    {
        if (count($ids) !== count($permissions)) {
            throw new AgentReadException('The Agent Permission snapshot is incomplete.');
        }

        foreach ($ids as $id) {
            $matches = array_filter(
                $permissions,
                static fn(Permission $permission): bool => $permission->getId()->equals($id)
            );
            if (count($matches) !== 1) {
                throw new AgentReadException('The Agent Permission snapshot is mismatched.');
            }
        }
    }

    /**
     * Returns one Agent's snapshots in assignment order.
     *
     * @phpstan-param list<Permission> $permissions
     *
     * @return list<AgentPermissionView>
     */
    private function snapshots(Agent $agent, array $permissions): array
    {
        $snapshots = [];
        foreach ($agent->getPermissionIds() as $id) {
            foreach ($permissions as $permission) {
                if ($permission->getId()->equals($id)) {
                    $snapshots[] = AgentPermissionView::fromPermission($permission);
                    break;
                }
            }
        }

        return $snapshots;
    }
}
