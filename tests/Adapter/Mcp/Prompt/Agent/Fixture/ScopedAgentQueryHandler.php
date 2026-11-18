<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Mcp\Prompt\Agent\Fixture;

use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\GetAgentById;
use Fight\Common\Application\Error\Exception\ResourceNotFound;
use Fight\Common\Application\Messaging\Query\QueryHandler;
use Fight\Common\Domain\Messaging\Query\QueryMessage;

final class ScopedAgentQueryHandler implements QueryHandler
{
    public bool $allowed = true;

    public int $calls = 0;

    public int $protectedReads = 0;

    public function __construct(private readonly AgentId $target)
    {
    }

    public static function queryRegistration(): string
    {
        return GetAgentById::class;
    }

    public function handle(QueryMessage $queryMessage): string
    {
        /** @var GetAgentById $query */
        $query = $queryMessage->payload();
        ++$this->calls;
        if (!$this->allowed || !$query->getAgentId()->equals($this->target)) {
            throw new ResourceNotFound();
        }

        ++$this->protectedReads;

        return 'Current permitted business content';
    }
}
