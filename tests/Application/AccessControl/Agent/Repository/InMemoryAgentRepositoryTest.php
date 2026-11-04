<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Repository;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentName;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionTier;
use Fight\Common\Domain\Repository\Pagination;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Credential\RotationEnvironment;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryAuthorizationReferenceState;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class InMemoryAgentRepositoryTest extends TestCase
{
    public function test_it_retrieves_the_current_agent_authority_by_stable_identity(): void
    {
        $rotation = new RotationEnvironment();
        $agentRepository = $rotation->provisioning->agentRepository;
        $agent = $agentRepository->all()[0];
        self::assertSame($agent, $agentRepository->getById($agent->getId()));
        self::assertTrue($rotation->service()->rotate($rotation->key, $rotation->request)->isConfirmed());
        $replacement = $agentRepository->all()[0];
        self::assertSame($replacement, $agentRepository->getById($agent->getId()));
        self::assertFalse($agentRepository->replace($agent, $replacement));
        self::assertNull($agentRepository->getById(AgentId::generate()));
    }

    public function test_it_returns_the_requested_ordered_page_of_agent_authorities(): void
    {
        $agentRepository = new InMemoryAgentRepository();
        $first = $this->agent('First deployment');
        $second = $this->agent('Second deployment');
        $agentRepository->add($first);
        $agentRepository->add($second);

        $result = $agentRepository->getAll(new Pagination(2, 1));

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
            $agentRepository = new InMemoryAgentRepository();
            $agentRepository->add($expected);

            self::assertFalse($agentRepository->replace($expected, $replacement));
            self::assertSame($expected, $agentRepository->getById($expected->getId()));
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
        $authorizationReferences->addPermission(Permission::define(
            $permissionId,
            PermissionName::fromString('READ_CASES'),
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        ));

        $agentRepository = new InMemoryAgentRepository(authorizationReferences: $authorizationReferences);
        $agentRepository->add($expected);

        self::assertFalse($agentRepository->replacePermissionAssignments($expected, $replacement));
        self::assertSame($expected, $agentRepository->getById($expected->getId()));
    }

    public function test_assignment_fence_requires_exact_eligible_definitions_and_rejects_protected_writes(): void
    {
        $permissionId = PermissionId::generate();
        $safe = Permission::defineManaged(
            $permissionId,
            PermissionName::fromString('READ_CASES'),
            PermissionTier::ADMIN_SAFE,
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        );
        $protected = $safe->reconcileManaged(
            $safe->getName(),
            PermissionTier::SUPER_ADMIN_ONLY,
            new DateTimeImmutable('2026-08-26T12:00:00+00:00')
        );
        $references = new InMemoryAuthorizationReferenceState();
        $references->addPermission($safe);

        $agentRepository = new InMemoryAgentRepository(authorizationReferences: $references);
        $agent = $this->agent('Production deployment');
        $agentRepository->add($agent);
        $replacement = $agent->grantPermission($permissionId, new DateTimeImmutable('2026-08-26T12:00:00+00:00'));

        self::assertTrue($agentRepository->validatePermissionAssignments([$safe]));
        self::assertTrue($references->isReferenceFenceHeld());
        $references->addPermission($protected);
        self::assertFalse($agentRepository->validatePermissionAssignments([$safe]));
        self::assertFalse($agentRepository->validatePermissionAssignments([$protected]));
        self::assertFalse($agentRepository->replacePermissionAssignments($agent, $replacement));
        self::assertSame($agent, $agentRepository->getById($agent->getId()));
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
