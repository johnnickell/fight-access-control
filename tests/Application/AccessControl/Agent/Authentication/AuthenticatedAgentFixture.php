<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Authentication;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\Agent\Authentication\CurrentAgentPrincipalProvider;
use Fight\AccessControl\Application\AccessControl\Agent\Authentication\SignedAgentRequest;
use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentName;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Repository\InMemoryAgentRepository;
use Fight\Test\AccessControl\Application\AccessControl\Permission\Repository\InMemoryPermissionRepository;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;

/**
 * Authenticates an Agent with only the explicitly selected direct Permissions over controlled ports.
 */
final readonly class AuthenticatedAgentFixture
{
    public Agent $agent;

    public CurrentAgentPrincipalProvider $provider;

    public SignedAgentRequest $request;

    public string $correlationId;

    /** @param list<string> $permissionNames */
    public function __construct(array $permissionNames)
    {
        $now = new DateTimeImmutable('2026-10-01T12:00:00+00:00');
        $unitOfWork = new InMemoryUnitOfWork();
        $agentRepository = new InMemoryAgentRepository($unitOfWork);
        $permissionRepository = new InMemoryPermissionRepository($unitOfWork);
        $agent = Agent::provision(
            AgentId::generate(),
            AgentName::fromString('Read admission agent'),
            AgentCredentialId::generate(),
            'encrypted:current-secret',
            $now
        );
        foreach ($permissionNames as $name) {
            $id = PermissionId::generate();
            $permissionRepository->add(Permission::define($id, PermissionName::fromString($name), $now));
            $agent = $agent->grantPermission($id, $now);
        }

        $agentRepository->add($agent);
        $this->agent = $agent;
        $this->correlationId = 'read-admission-request';
        $this->request = new SignedAgentRequest(
            'POST',
            'mcp.example',
            '/mcp',
            '',
            $now,
            'read-admission-nonce',
            $agent->getCredentialId(),
            'HMAC-SHA256',
            'valid-signature',
            null,
            ''
        );
        $this->provider = new CurrentAgentPrincipalProvider(
            $agentRepository,
            $permissionRepository,
            new FixedHmacSharedSecretDecipher('encrypted:'),
            new FixedHmacSignedAgentRequestVerifier($this->request, 'current-secret'),
            new FixedClock($now),
            new InMemoryAgentRequestNonceConsumer($agentRepository, $unitOfWork),
            $unitOfWork
        );
    }
}
