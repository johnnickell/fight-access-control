<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\QueryHandler;

use Fight\AccessControl\Domain\AccessControl\Agent\AgentRepository;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentState;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\AgentProfileView;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\GetAgentProfile;
use Fight\Common\Application\Error\Exception\ResourceNotFound;
use Fight\Common\Application\Messaging\Query\QueryHandler;
use Fight\Common\Domain\Messaging\Query\QueryMessage;
use UnexpectedValueException;

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
     * Returns the current profile or rejects an absent or revoked Agent identically
     */
    public function handle(QueryMessage $queryMessage): AgentProfileView
    {
        /** @var GetAgentProfile $query */
        $query = $queryMessage->payload();
        $agent = $this->agentRepository->getById($query->getAgentId());
        if ($agent === null) {
            throw new ResourceNotFound('The Agent profile is unavailable.');
        }

        if (!$agent->getId()->equals($query->getAgentId())) {
            throw new UnexpectedValueException('The Agent lookup returned a different identity.');
        }

        if ($agent->getState() !== AgentState::ACTIVE) {
            throw new ResourceNotFound('The Agent profile is unavailable.');
        }

        return new AgentProfileView($agent->getId(), $agent->getName());
    }
}
