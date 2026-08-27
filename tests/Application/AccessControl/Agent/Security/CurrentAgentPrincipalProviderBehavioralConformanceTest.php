<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Security;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentAuthenticationService;
use Fight\AccessControl\Application\AccessControl\Agent\Security\CurrentAgentPrincipalProvider;
use Fight\AccessControl\Application\AccessControl\Agent\Security\SignedAgentRequest;
use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentName;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\CurrentAgentPrincipalResolutionRejectedException;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Repository\InMemoryAgentRepository;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\FixedHmacSharedSecretDecipher;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\FixedHmacSignedAgentRequestVerifier;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\InMemoryAgentRequestNonceConsumer;
use Fight\Test\AccessControl\Application\AccessControl\Permission\Repository\InMemoryPermissionRepository;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CurrentAgentPrincipalProvider::class)]
final class CurrentAgentPrincipalProviderBehavioralConformanceTest extends TestCase
{
    public function test_that_the_framework_free_public_seam_resolves_one_complete_principal_and_fails_closed(): void
    {
        $viewAgents = PermissionName::fromString('VIEW_AGENTS');
        [$provider, $request] = $this->provider();

        $firstPrincipal = $provider->resolve($request, 'correlation-success');
        $secondPrincipal = $provider->resolve($request, 'correlation-success');

        self::assertSame($firstPrincipal, $secondPrincipal);
        self::assertSame(
            [
                'agent_id' => '018f0000-0000-7000-8000-000000000101',
                'credential_id' => '018f0000-0000-7000-8000-000000000102',
                'credential_revision' => 0,
                'permission_assignment_revision' => 2,
                'permissions' => [
                    [
                        'permission_id' => '018f0000-0000-7000-8000-000000000103',
                        'name' => 'VIEW_AGENTS',
                    ],
                ],
            ],
            $firstPrincipal->toArray()
        );
        self::assertTrue($firstPrincipal->hasPermission($viewAgents));
        self::assertFalse($firstPrincipal->hasPermission(PermissionName::fromString('MANAGE_AGENTS')));

        foreach ($this->rejectedResolutions() as $name => [$rejectedProvider, $rejectedRequest, $correlationId]) {
            try {
                $rejectedProvider->resolve($rejectedRequest, $correlationId);
                self::fail(sprintf('%s must not resolve a partial Agent principal.', $name));
            } catch (CurrentAgentPrincipalResolutionRejectedException $exception) {
                self::assertSame('Agent authentication rejected.', $exception->getMessage());
                self::assertSame($correlationId, $exception->getDiagnostic()->getCorrelationId());
                self::assertSafeDiagnostic($exception, $rejectedRequest);
            }
        }
    }

    /**
     * @return array<string, array{0: CurrentAgentPrincipalProvider, 1: SignedAgentRequest, 2: string}>
     */
    private function rejectedResolutions(): array
    {
        [$revokedProvider, $revokedRequest] = $this->provider(agent: $this->agent()->revoke($this->now()));
        [$staleCredentialProvider, $staleCredentialRequest] = $this->provider(
            agent: $this->agent()->rotateCredential(
                $this->credentialId(),
                AgentCredentialId::fromString('018f0000-0000-7000-8000-000000000104'),
                'encrypted:successor-secret',
                $this->now()
            )
        );
        [$authenticationProvider, $authenticationRequest] = $this->provider(signature: 'invalid-signature');
        [$missingPermissionProvider, $missingPermissionRequest] = $this->provider(includePermission: false);
        [$staleAssignmentProvider, $staleAssignmentRequest] = $this->provider(
            providerAgent: $this->agent()->replacePermissions(
                [PermissionId::fromString('018f0000-0000-7000-8000-000000000105')],
                2,
                $this->now()
            )
        );

        return [
            'revoked authority' => [$revokedProvider, $revokedRequest, 'correlation-revoked'],
            'stale credential' => [$staleCredentialProvider, $staleCredentialRequest, 'correlation-stale-credential'],
            'authentication failure' => [$authenticationProvider, $authenticationRequest, 'correlation-authentication'],
            'missing Permission' => [
                $missingPermissionProvider,
                $missingPermissionRequest,
                'correlation-missing-permission',
            ],
            'stale assignment' => [$staleAssignmentProvider, $staleAssignmentRequest, 'correlation-stale-assignment'],
        ];
    }

    private function assertSafeDiagnostic(
        CurrentAgentPrincipalResolutionRejectedException $exception,
        SignedAgentRequest $signedAgentRequest
    ): void {
        $serialized = json_encode($exception->getDiagnostic()->toArray(), JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString($signedAgentRequest->getSignature(), $serialized);
        self::assertStringNotContainsString($signedAgentRequest->getNonce(), $serialized);
        self::assertStringNotContainsString($signedAgentRequest->getAuthority(), $serialized);
        self::assertStringNotContainsString('current-secret', $serialized);
        self::assertSame(
            ['classification', 'correlation_id'],
            array_keys($exception->getDiagnostic()->toArray())
        );
    }

    /**
     * @return array{0: CurrentAgentPrincipalProvider, 1: SignedAgentRequest}
     */
    private function provider(
        ?Agent $agent = null,
        ?Agent $providerAgent = null,
        string $signature = 'valid-signature',
        bool $includePermission = true
    ): array {
        $agent ??= $this->agent();
        $request = $this->request($signature);
        $authenticationAgents = new InMemoryAgentRepository();
        $authenticationAgents->add($agent);

        $providerAgents = new InMemoryAgentRepository();
        $providerAgents->add($providerAgent ?? $agent);

        $permissions = new InMemoryPermissionRepository();
        if ($includePermission) {
            $permissions->add(Permission::define(
                $this->permissionId(),
                PermissionName::fromString('VIEW_AGENTS'),
                $this->now()
            ));
        }

        $unitOfWork = new InMemoryUnitOfWork();
        $authentication = new AgentAuthenticationService(
            $authenticationAgents,
            new FixedHmacSharedSecretDecipher('encrypted:'),
            new FixedHmacSignedAgentRequestVerifier(
                $this->request('valid-signature'),
                'current-secret'
            ),
            new FixedClock($this->now()),
            new InMemoryAgentRequestNonceConsumer($authenticationAgents, $unitOfWork),
            $unitOfWork
        );

        return [new CurrentAgentPrincipalProvider($authentication, $providerAgents, $permissions), $request];
    }

    private function request(string $signature): SignedAgentRequest
    {
        return new SignedAgentRequest(
            'POST',
            'agents.fight.example',
            '/v1/agent-tasks',
            'page=1',
            $this->now(),
            'nonce-00023',
            $this->credentialId(),
            'HMAC-SHA256',
            $signature,
            null,
            ''
        );
    }

    private function agent(): Agent
    {
        return Agent::provision(
            AgentId::fromString('018f0000-0000-7000-8000-000000000101'),
            AgentName::fromString('Conformance agent'),
            $this->credentialId(),
            'encrypted:current-secret',
            $this->now()
        )->grantPermission($this->permissionId(), $this->now());
    }

    private function credentialId(): AgentCredentialId
    {
        return AgentCredentialId::fromString('018f0000-0000-7000-8000-000000000102');
    }

    private function permissionId(): PermissionId
    {
        return PermissionId::fromString('018f0000-0000-7000-8000-000000000103');
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-08-26T12:00:00+00:00');
    }
}
