<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Repository;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentName;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class InMemoryAgentRepositoryTest extends TestCase
{
    public function test_it_retrieves_the_current_agent_authority_by_stable_identity(): void
    {
        $repository = new InMemoryAgentRepository();
        $agentId = AgentId::generate();
        $agent = Agent::provision(
            $agentId,
            AgentName::fromString('Production deployment'),
            AgentCredentialId::generate(),
            'consumer-encrypted-hmac-shared-secret-envelope',
            new DateTimeImmutable('2026-08-25T12:00:00+00:00')
        );
        $replacement = Agent::provision(
            $agentId,
            AgentName::fromString('Production deployment'),
            AgentCredentialId::generate(),
            'replacement-consumer-encrypted-hmac-shared-secret-envelope',
            new DateTimeImmutable('2026-08-25T13:00:00+00:00')
        );
        $repository->add($agent);

        self::assertSame($agent, $repository->getById($agent->getId()));
        self::assertTrue($repository->replace($agent, $replacement));
        self::assertSame($replacement, $repository->getById($agent->getId()));
        self::assertFalse($repository->replace($agent, $replacement));
        self::assertNull($repository->getById(AgentId::generate()));
    }
}
