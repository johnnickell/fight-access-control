<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\QueryHandler;

use Fight\AccessControl\Application\AccessControl\Authorization\Service\ExactPermissionResolutionException;
use Fight\AccessControl\Application\AccessControl\Authorization\Service\ExactPermissionResolver;
use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentRepository;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentReadException;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\AgentView;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\GetAgentById;
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

        try {
            return AgentView::fromAgent(
                $agent,
                new ExactPermissionResolver($this->permissionRepository)->resolve($agent->getPermissionIds())
            );
        } catch (ExactPermissionResolutionException) {
            throw new AgentReadException('The Agent Permission snapshot is invalid.');
        }
    }
}
