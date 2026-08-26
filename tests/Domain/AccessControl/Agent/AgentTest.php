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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Agent::class)]
#[CoversClass(AgentCredentialId::class)]
#[CoversClass(AgentId::class)]
#[CoversClass(AgentName::class)]
#[CoversClass(AgentCredentialException::class)]
#[CoversClass(AgentNameException::class)]
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
        self::assertSame(
            'consumer-encrypted-hmac-shared-secret-envelope',
            $agent->getEncryptedHmacSharedSecretEnvelope()
        );
        self::assertSame($provisionedAt, $agent->getCreatedAt());
        self::assertSame($provisionedAt, $agent->getUpdatedAt());
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
