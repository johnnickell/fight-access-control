<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Repository;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentName;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\Common\Domain\Repository\Pagination;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryAuthorizationReferenceState;
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

    public function test_it_returns_the_requested_ordered_page_of_agent_authorities(): void
    {
        $repository = new InMemoryAgentRepository();
        $first = $this->agent('First deployment');
        $second = $this->agent('Second deployment');
        $repository->add($first);
        $repository->add($second);

        $result = $repository->getAll(new Pagination(2, 1));

        self::assertSame(2, $result->page());
        self::assertSame(1, $result->perPage());
        self::assertSame(2, $result->totalRecords());
        self::assertSame([$second], $result->records()->toArray());
    }

    public function test_generic_replacement_rejects_permission_membership_or_assignment_revision_changes(): void
    {
        foreach (['membership', 'revision'] as $case) {
            $permissionId = PermissionId::generate();
            $expected = $this->agent('Production deployment')->grantPermission(
                $permissionId,
                new DateTimeImmutable('2026-08-25T13:00:00+00:00')
            );
            $replacement = RehydratedAgentFixture::fromAgent(
                $expected,
                $case === 'membership' ? [] : [$permissionId],
                $case === 'revision' ? 3 : 2
            );
            $repository = new InMemoryAgentRepository();
            $repository->add($expected);

            self::assertFalse($repository->replace($expected, $replacement));
            self::assertSame($expected, $repository->getById($expected->getId()));
        }
    }

    public function test_permission_assignment_replacement_rejects_revision_only_change(): void
    {
        $permissionId = PermissionId::generate();
        $expected = $this->agent('Production deployment')->grantPermission(
            $permissionId,
            new DateTimeImmutable('2026-08-25T13:00:00+00:00')
        );
        $replacement = RehydratedAgentFixture::fromAgent($expected, [$permissionId], 3);
        $authorizationReferences = new InMemoryAuthorizationReferenceState();
        $authorizationReferences->addPermission($permissionId);

        $repository = new InMemoryAgentRepository(authorizationReferences: $authorizationReferences);
        $repository->add($expected);

        self::assertFalse($repository->replacePermissionAssignments($expected, $replacement));
        self::assertSame($expected, $repository->getById($expected->getId()));
    }

    private function agent(string $name): Agent
    {
        return Agent::provision(
            AgentId::generate(),
            AgentName::fromString($name),
            AgentCredentialId::generate(),
            'consumer-encrypted-hmac-shared-secret-envelope',
            new DateTimeImmutable('2026-08-25T12:00:00+00:00')
        );
    }
}
