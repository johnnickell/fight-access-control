<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\QueryHandler;

use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentRepository;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentState;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\AgentProfileView;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\GetAgentProfile;
use Fight\Common\Application\Messaging\Query\QueryHandler;
use Fight\Common\Domain\Messaging\Query\QueryMessage;

/**
 * Class GetAgentProfileHandler
 *
 * Reads a fresh minimal profile without resolving Permissions or authorizing the caller.
 */
final readonly class GetAgentProfileHandler implements QueryHandler
{
    /**
     * Constructs GetAgentProfileHandler
     */
    public function __construct(private AgentRepository $agentRepository)
    {
    }

    /**
     * @inheritDoc
     */
    public static function queryRegistration(): string
    {
        return GetAgentProfile::class;
    }

    /**
     * Returns the current profile or null for either an absent or revoked Agent
     */
    public function handle(QueryMessage $queryMessage): ?AgentProfileView
    {
        /** @var GetAgentProfile $query */
        $query = $queryMessage->payload();
        $agent = $this->agentRepository->getById($query->getAgentId());
        if (!$agent instanceof Agent || $agent->getState() !== AgentState::ACTIVE) {
            return null;
        }

        return new AgentProfileView($agent->getId(), $agent->getName());
    }
}
