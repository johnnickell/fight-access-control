<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\Agent;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Agent::class)]
#[CoversClass(AgentCredentialId::class)]
#[CoversClass(AgentId::class)]
#[CoversClass(AgentState::class)]
final class AgentTest extends TestCase
{
    public function test_that_provisioning_creates_an_active_agent_with_one_initial_encrypted_credential(): void
    {
        $agentId = AgentId::generate();
        $credentialId = AgentCredentialId::generate();
        $provisionedAt = new DateTimeImmutable('2026-08-25T12:00:00+00:00');

        $agent = Agent::provision(
            $agentId,
            $credentialId,
            'consumer-encrypted-hmac-shared-secret-envelope',
            $provisionedAt
        );

        self::assertSame($agentId, $agent->getId());
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
}
