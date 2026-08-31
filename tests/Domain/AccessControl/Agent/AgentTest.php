<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\Agent;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentName;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentState;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentCredentialException;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentNameException;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentPermissionAssignmentException;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Agent::class)]
#[CoversClass(AgentCredentialId::class)]
#[CoversClass(AgentId::class)]
#[CoversClass(AgentName::class)]
#[CoversClass(AgentCredentialException::class)]
#[CoversClass(AgentNameException::class)]
#[CoversClass(AgentPermissionAssignmentException::class)]
#[CoversClass(AgentState::class)]
final class AgentTest extends TestCase
{
    public function test_it_normalizes_a_bounded_operator_facing_name(): void
    {
        $name = AgentName::fromString('  Production deployment  ');

        self::assertSame('Production deployment', $name->toString());
        self::assertSame('Production deployment', (string) $name);
        self::assertTrue($name->equals(AgentName::fromString('Production deployment')));
    }

    public function test_it_rejects_empty_and_over_bound_operator_facing_names(): void
    {
        foreach (['', '   ', str_repeat('a', 121)] as $invalidName) {
            try {
                AgentName::fromString($invalidName);
                self::fail(sprintf('Expected "%s" to be rejected.', $invalidName));
            } catch (AgentNameException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_that_provisioning_creates_an_active_agent_with_one_initial_encrypted_credential(): void
    {
        $agentId = AgentId::generate();
        $credentialId = AgentCredentialId::generate();
        $provisionedAt = new DateTimeImmutable('2026-08-25T12:00:00+00:00');

        $agent = Agent::provision(
            $agentId,
            AgentName::fromString('Production deployment'),
            $credentialId,
            'consumer-encrypted-hmac-shared-secret-envelope',
            $provisionedAt
        );

        self::assertSame($agentId, $agent->getId());
        self::assertSame('Production deployment', $agent->getName()->toString());
        self::assertSame(AgentState::ACTIVE, $agent->getState());
        self::assertSame($credentialId, $agent->getCredentialId());
        self::assertSame(0, $agent->getCredentialRevision());
        self::assertSame([], $agent->getPermissionIds());
        self::assertSame(1, $agent->getPermissionAssignmentRevision());
        self::assertSame(
            'consumer-encrypted-hmac-shared-secret-envelope',
            $agent->getEncryptedHmacSharedSecretEnvelope()
        );
        self::assertSame($provisionedAt, $agent->getCreatedAt());
        self::assertSame($provisionedAt, $agent->getUpdatedAt());
    }

    public function test_it_grants_one_unique_permission_and_advances_only_assignment_authority(): void
    {
        $credentialId = AgentCredentialId::generate();
        $permissionId = PermissionId::generate();
        $provisionedAt = new DateTimeImmutable('2026-08-25T12:00:00+00:00');
        $grantedAt = new DateTimeImmutable('2026-08-26T12:00:00+00:00');
        $agent = Agent::provision(
            AgentId::generate(),
            AgentName::fromString('Production deployment'),
            $credentialId,
            'encrypted:current-secret',
            $provisionedAt
        );

        $successor = $agent->grantPermission($permissionId, $grantedAt);

        self::assertFalse($agent->hasPermission($permissionId));
        self::assertTrue($successor->hasPermission($permissionId));
        self::assertSame([$permissionId], $successor->getPermissionIds());
        self::assertSame(1, $agent->getPermissionAssignmentRevision());
        self::assertSame(2, $successor->getPermissionAssignmentRevision());
        self::assertSame($agent->getState(), $successor->getState());
        self::assertSame($credentialId, $successor->getCredentialId());
        self::assertSame(0, $successor->getCredentialRevision());
        self::assertSame('encrypted:current-secret', $successor->getEncryptedHmacSharedSecretEnvelope());
        self::assertSame($provisionedAt, $successor->getCreatedAt());
        self::assertSame($grantedAt, $successor->getUpdatedAt());

        self::assertSame(
            $successor,
            $successor->grantPermission($permissionId, new DateTimeImmutable('2026-08-26T12:05:00+00:00'))
        );
    }

    public function test_it_revokes_one_assigned_permission_and_advances_only_assignment_authority(): void
    {
        $credentialId = AgentCredentialId::generate();
        $revokedPermissionId = PermissionId::generate();
        $remainingPermissionId = PermissionId::generate();
        $provisionedAt = new DateTimeImmutable('2026-08-25T12:00:00+00:00');
        $revokedAt = new DateTimeImmutable('2026-08-26T12:00:00+00:00');
        $agent = Agent::provision(
            AgentId::generate(),
            AgentName::fromString('Production deployment'),
            $credentialId,
            'encrypted:current-secret',
            $provisionedAt
        )->grantPermission(
            $revokedPermissionId,
            new DateTimeImmutable('2026-08-25T13:00:00+00:00')
        )->grantPermission(
            $remainingPermissionId,
            new DateTimeImmutable('2026-08-25T14:00:00+00:00')
        );

        $successor = $agent->revokePermission($revokedPermissionId, $revokedAt);

        self::assertTrue($agent->hasPermission($revokedPermissionId));
        self::assertFalse($successor->hasPermission($revokedPermissionId));
        self::assertTrue($successor->hasPermission($remainingPermissionId));
        self::assertSame([$remainingPermissionId], $successor->getPermissionIds());
        self::assertSame(3, $agent->getPermissionAssignmentRevision());
        self::assertSame(4, $successor->getPermissionAssignmentRevision());
        self::assertSame($agent->getState(), $successor->getState());
        self::assertSame($credentialId, $successor->getCredentialId());
        self::assertSame(0, $successor->getCredentialRevision());
        self::assertSame('encrypted:current-secret', $successor->getEncryptedHmacSharedSecretEnvelope());
        self::assertSame($provisionedAt, $successor->getCreatedAt());
        self::assertSame($revokedAt, $successor->getUpdatedAt());

        self::assertSame(
            $successor,
            $successor->revokePermission($revokedPermissionId, new DateTimeImmutable('2026-08-26T12:05:00+00:00'))
        );
    }

    public function test_it_replaces_the_complete_permission_set_with_revision_and_set_semantics(): void
    {
        $firstPermissionId = PermissionId::generate();
        $secondPermissionId = PermissionId::generate();
        $replacementPermissionId = PermissionId::generate();
        $agent = Agent::provision(
            AgentId::generate(),
            AgentName::fromString('Production deployment'),
            AgentCredentialId::generate(),
            'encrypted:current-secret',
            new DateTimeImmutable('2026-08-25T12:00:00+00:00')
        )->grantPermission(
            $firstPermissionId,
            new DateTimeImmutable('2026-08-25T13:00:00+00:00')
        )->grantPermission(
            $secondPermissionId,
            new DateTimeImmutable('2026-08-25T14:00:00+00:00')
        );

        $sameSet = $agent->replacePermissions(
            [$secondPermissionId, $firstPermissionId],
            3,
            new DateTimeImmutable('2026-08-26T11:00:00+00:00')
        );
        $replacement = $agent->replacePermissions(
            [$replacementPermissionId, $secondPermissionId],
            3,
            new DateTimeImmutable('2026-08-26T12:00:00+00:00')
        );
        $empty = $replacement->replacePermissions(
            [],
            4,
            new DateTimeImmutable('2026-08-26T13:00:00+00:00')
        );

        self::assertSame($agent, $sameSet);
        self::assertSame([$firstPermissionId, $secondPermissionId], $sameSet->getPermissionIds());
        self::assertSame(3, $sameSet->getPermissionAssignmentRevision());
        self::assertSame([$replacementPermissionId, $secondPermissionId], $replacement->getPermissionIds());
        self::assertSame(4, $replacement->getPermissionAssignmentRevision());
        self::assertSame([], $empty->getPermissionIds());
        self::assertSame(5, $empty->getPermissionAssignmentRevision());
        self::assertSame($agent->getCredentialId(), $replacement->getCredentialId());

        try {
            $agent->replacePermissions(
                [$replacementPermissionId],
                2,
                new DateTimeImmutable('2026-08-26T14:00:00+00:00')
            );
            self::fail('A stale complete-set replacement was accepted.');
        } catch (AgentPermissionAssignmentException) {
            self::assertSame([$firstPermissionId, $secondPermissionId], $agent->getPermissionIds());
            self::assertSame(3, $agent->getPermissionAssignmentRevision());
        }

        self::assertSame(
            $agent,
            $agent->replacePermissions(
                [$secondPermissionId, $firstPermissionId, $firstPermissionId],
                3,
                new DateTimeImmutable('2026-08-26T14:05:00+00:00')
            )
        );
    }

    public function test_that_an_active_agent_rotates_to_one_successor_credential(): void
    {
        $agentId = AgentId::generate();
        $credentialId = AgentCredentialId::generate();
        $rotatedCredentialId = AgentCredentialId::generate();
        $provisionedAt = new DateTimeImmutable('2026-08-25T12:00:00+00:00');
        $rotatedAt = new DateTimeImmutable('2026-08-25T12:05:00+00:00');
        $agent = Agent::provision(
            $agentId,
            AgentName::fromString('Production deployment'),
            $credentialId,
            'encrypted:current-secret',
            $provisionedAt
        );

        $successor = $agent->rotateCredential(
            $credentialId,
            $rotatedCredentialId,
            'encrypted:rotated-secret',
            $rotatedAt
        );

        self::assertSame($agentId, $successor->getId());
        self::assertSame($rotatedCredentialId, $successor->getCredentialId());
        self::assertSame(1, $successor->getCredentialRevision());
        self::assertSame('encrypted:rotated-secret', $successor->getEncryptedHmacSharedSecretEnvelope());
        self::assertSame(1, $successor->getPermissionAssignmentRevision());
        self::assertSame($provisionedAt, $successor->getCreatedAt());
        self::assertSame($rotatedAt, $successor->getUpdatedAt());
    }

    public function test_that_revocation_is_terminal_and_rejects_later_rotation(): void
    {
        $credentialId = AgentCredentialId::generate();
        $revokedAt = new DateTimeImmutable('2026-08-25T12:05:00+00:00');
        $agent = Agent::provision(
            AgentId::generate(),
            AgentName::fromString('Production deployment'),
            $credentialId,
            'encrypted:current-secret',
            new DateTimeImmutable('2026-08-25T12:00:00+00:00')
        );

        $revoked = $agent->revoke($revokedAt);

        self::assertSame(AgentState::REVOKED, $revoked->getState());
        self::assertSame($credentialId, $revoked->getCredentialId());
        self::assertSame(0, $revoked->getCredentialRevision());
        self::assertSame(1, $revoked->getPermissionAssignmentRevision());
        self::assertSame($revokedAt, $revoked->getUpdatedAt());

        try {
            $revoked->rotateCredential(
                $credentialId,
                AgentCredentialId::generate(),
                'encrypted:replacement-secret',
                new DateTimeImmutable('2026-08-25T12:10:00+00:00')
            );
            self::fail('Expected a revoked Agent to reject credential rotation.');
        } catch (AgentCredentialException) {
            self::addToAssertionCount(1);
        }

        $this->expectException(AgentCredentialException::class);
        $revoked->revoke(new DateTimeImmutable('2026-08-25T12:10:00+00:00'));
    }
}
