<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\QueryHandler;

use Fight\AccessControl\Application\AccessControl\Authorization\Service\ExactPermissionResolutionException;
use Fight\AccessControl\Application\AccessControl\Authorization\Service\ExactPermissionResolver;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentRepository;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentReadException;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\AgentView;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\ListAgents;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionRepository;
use Fight\Common\Application\Messaging\Query\QueryHandler;
use Fight\Common\Domain\Collection\ArrayList;
use Fight\Common\Domain\Messaging\Query\QueryMessage;
use Fight\Common\Domain\Repository\ResultSet;

/**
 * Retrieves one page of exact secret-free Agent administrative views.
 *
 * @internal
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
        $views = ArrayList::of(AgentView::class);
        $permissionIds = [];
        $permissionIdsByKey = [];
        foreach ($agentRecords as $agent) {
            foreach ($agent->getPermissionIds() as $permissionId) {
                $permissionKey = $permissionId->toString();
                if (isset($permissionIdsByKey[$permissionKey])) {
                    continue;
                }

                $permissionIdsByKey[$permissionKey] = true;
                $permissionIds[] = $permissionId;
            }
        }

        try {
            $permissionResolver = new ExactPermissionResolver($this->permissionRepository);
            $permissionsById = [];
            foreach ($permissionResolver->resolve($permissionIds) as $permission) {
                $permissionsById[$permission->getPermissionId()->toString()] = $permission;
            }

            foreach ($agentRecords as $agent) {
                $permissions = [];
                foreach ($agent->getPermissionIds() as $permissionId) {
                    $permissions[] = $permissionsById[$permissionId->toString()];
                }

                $views->add(AgentView::fromAgent($agent, $permissions));
            }
        } catch (ExactPermissionResolutionException) {
            throw new AgentReadException('The Agent Permission snapshot is invalid.');
        }

        return new ResultSet(
            $agents->page(),
            $agents->perPage(),
            $agents->totalRecords(),
            $views
        );
    }
}
